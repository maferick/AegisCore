<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Eve\Esi;

use App\Services\Eve\Esi\CachedEsiClient;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiException;
use App\Services\Eve\Esi\EsiRateLimitException;
use App\Services\Eve\Esi\EsiResponse;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

final class CachedEsiClientTest extends TestCase
{
    public function test_fresh_hit_returns_cached_body_without_hitting_the_inner_transport(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['value' => 1], expiresInSeconds: 3600));
        $client = $this->buildClient($inner);

        $first = $client->get('/test/');
        self::assertSame(['value' => 1], $first->body);
        self::assertSame(1, $inner->calls);

        // Second call within the freshness window must not call the inner.
        $second = $client->get('/test/');
        self::assertSame(['value' => 1], $second->body);
        self::assertFalse($second->notModified);
        self::assertFalse($second->stale);
        self::assertSame(1, $inner->calls, 'fresh hits must not reach the inner transport');
    }

    public function test_304_from_inner_replays_cached_body_and_keeps_notmodified_true(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['value' => 1], expiresInSeconds: -5)); // already expired
        $inner->enqueue(new EsiResponse(
            status: 304,
            body: null,
            notModified: true,
            etag: 'v1',
            lastModified: null,
            expires: gmdate('D, d M Y H:i:s \G\M\T', time() + 3600),
            rateLimit: [],
        ));
        $client = $this->buildClient($inner);

        // First call seeds the cache with an already-stale entry.
        $client->get('/test/');

        $second = $client->get('/test/');
        self::assertTrue($second->notModified, '304 must preserve the nothing-changed signal for short-circuit callers');
        self::assertSame(['value' => 1], $second->body, '304 must replay the cached body so callers that want it have it');
        self::assertFalse($second->stale);
        self::assertSame(2, $inner->calls);
    }

    public function test_miss_then_store_round_trip(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['value' => 42], expiresInSeconds: 3600));
        $client = $this->buildClient($inner);

        $resp = $client->get('/test/');
        self::assertSame(['value' => 42], $resp->body);
        self::assertSame(1, $inner->calls);

        // Second call within freshness should serve from cache.
        $client->get('/test/');
        self::assertSame(1, $inner->calls);
    }

    public function test_different_bearer_tokens_do_not_share_cache_entries(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['who' => 'alice'], expiresInSeconds: 3600));
        $inner->enqueue($this->freshResponse(['who' => 'bob'], expiresInSeconds: 3600));
        $client = $this->buildClient($inner);

        $aliceFirst = $client->get('/test/', [], 'alice-token');
        $bobFirst = $client->get('/test/', [], 'bob-token');

        self::assertSame(['who' => 'alice'], $aliceFirst->body);
        self::assertSame(['who' => 'bob'], $bobFirst->body);
        self::assertSame(2, $inner->calls);

        // Each token sees its own cached body on the next call — no cross-bleed.
        $aliceSecond = $client->get('/test/', [], 'alice-token');
        $bobSecond = $client->get('/test/', [], 'bob-token');
        self::assertSame(['who' => 'alice'], $aliceSecond->body);
        self::assertSame(['who' => 'bob'], $bobSecond->body);
        self::assertSame(2, $inner->calls, 'auth-scoped cache must not reuse a public or mis-scoped entry');
    }

    public function test_public_and_authenticated_requests_are_cached_under_different_keys(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['scope' => 'public'], expiresInSeconds: 3600));
        $inner->enqueue($this->freshResponse(['scope' => 'auth'], expiresInSeconds: 3600));
        $client = $this->buildClient($inner);

        $public = $client->get('/test/');
        $auth = $client->get('/test/', [], 'some-token');

        self::assertSame(['scope' => 'public'], $public->body);
        self::assertSame(['scope' => 'auth'], $auth->body);
        self::assertSame(2, $inner->calls);
    }

    public function test_query_params_differentiate_cache_entries_even_with_same_path(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['page' => 1], expiresInSeconds: 3600));
        $inner->enqueue($this->freshResponse(['page' => 2], expiresInSeconds: 3600));
        $client = $this->buildClient($inner);

        $p1 = $client->get('/test/', ['page' => 1]);
        $p2 = $client->get('/test/', ['page' => 2]);

        self::assertSame(['page' => 1], $p1->body);
        self::assertSame(['page' => 2], $p2->body);
        self::assertSame(2, $inner->calls);
    }

    public function test_5xx_within_stale_window_serves_stale(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['value' => 'last-good'], expiresInSeconds: -5));
        $inner->enqueueException(new EsiException(
            message: 'ESI error: HTTP 503 on /test/',
            status: 503,
            responseBody: 'gateway',
            url: '/test/',
        ));
        $client = $this->buildClient($inner, staleIfErrorSeconds: 600);

        // Seed the cache (with an already-expired entry so the 2nd call
        // actually revalidates).
        $client->get('/test/');

        $stale = $client->get('/test/');
        self::assertTrue($stale->stale, 'transient 5xx within stale-if-error window must flag the response stale');
        self::assertSame(['value' => 'last-good'], $stale->body);
        self::assertFalse($stale->notModified);
    }

    public function test_5xx_beyond_stale_window_rethrows(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['value' => 'ancient'], expiresInSeconds: -5));
        $inner->enqueueException(new EsiException(
            message: 'ESI error: HTTP 503 on /test/',
            status: 503,
            responseBody: 'gateway',
            url: '/test/',
        ));
        // Zero stale window — any cached body is already outside it.
        $client = $this->buildClient($inner, staleIfErrorSeconds: 0);
        $client->get('/test/');

        $this->expectException(EsiException::class);
        $client->get('/test/');
    }

    public function test_4xx_is_never_served_stale(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['value' => 'cached'], expiresInSeconds: -5));
        $inner->enqueueException(new EsiException(
            message: 'ESI error: HTTP 404 on /test/',
            status: 404,
            responseBody: 'not found',
            url: '/test/',
        ));
        $client = $this->buildClient($inner, staleIfErrorSeconds: 3600);
        $client->get('/test/');

        $this->expectException(EsiException::class);
        $client->get('/test/');
    }

    public function test_rate_limit_exception_is_never_served_stale(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['value' => 'cached'], expiresInSeconds: -5));
        $inner->enqueueException(new EsiRateLimitException(
            message: 'ESI rate-limited',
            retryAfter: 30,
            status: 429,
            responseBody: '',
            url: '/test/',
        ));
        $client = $this->buildClient($inner, staleIfErrorSeconds: 3600);
        $client->get('/test/');

        $this->expectException(EsiRateLimitException::class);
        $client->get('/test/');
    }

    public function test_connection_exception_within_stale_window_serves_stale(): void
    {
        $inner = new RecordingInnerClient;
        $inner->enqueue($this->freshResponse(['value' => 'last-good'], expiresInSeconds: -5));
        $inner->enqueueException(new ConnectionException('connection refused'));
        $client = $this->buildClient($inner, staleIfErrorSeconds: 600);
        $client->get('/test/');

        $stale = $client->get('/test/');
        self::assertTrue($stale->stale);
        self::assertSame(['value' => 'last-good'], $stale->body);
    }

    public function test_error_responses_are_not_persisted_as_payload_entries(): void
    {
        $inner = new RecordingInnerClient;
        // First call: 500 on an empty cache. Must not swallow, must not store.
        $inner->enqueueException(new EsiException(
            message: 'ESI error: HTTP 500',
            status: 500,
            responseBody: '',
            url: '/test/',
        ));
        $inner->enqueue($this->freshResponse(['value' => 'recovered'], expiresInSeconds: 3600));
        $client = $this->buildClient($inner);

        try {
            $client->get('/test/');
            self::fail('expected EsiException');
        } catch (EsiException) {
            // expected
        }

        // Next call goes to the inner because nothing was cached.
        $resp = $client->get('/test/');
        self::assertSame(['value' => 'recovered'], $resp->body);
        self::assertSame(2, $inner->calls);
    }

    public function test_304_with_no_stored_payload_surfaces_the_naked_response(): void
    {
        // Drift scenario: the inner transport has validators cached from a
        // previous process, but our payload entry has been evicted. We
        // can't fabricate a body; the response passes through unchanged so
        // the next non-304 will re-seed the cache naturally.
        $inner = new RecordingInnerClient;
        $inner->enqueue(new EsiResponse(
            status: 304,
            body: null,
            notModified: true,
            etag: 'v1',
            lastModified: null,
            expires: null,
            rateLimit: [],
        ));
        $client = $this->buildClient($inner);

        $resp = $client->get('/test/');
        self::assertTrue($resp->notModified);
        self::assertNull($resp->body);
    }

    private function buildClient(
        EsiClientInterface $inner,
        int $staleIfErrorSeconds = 600,
    ): CachedEsiClient {
        return new CachedEsiClient(
            inner: $inner,
            cache: $this->app['cache']->store('array'),
            fallbackFreshnessSeconds: 60,
            staleIfErrorSeconds: $staleIfErrorSeconds,
            retentionSeconds: 86_400,
            lockWaitSeconds: 1,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function freshResponse(array $body, int $expiresInSeconds): EsiResponse
    {
        return new EsiResponse(
            status: 200,
            body: $body,
            notModified: false,
            etag: 'v1',
            lastModified: null,
            expires: gmdate('D, d M Y H:i:s \G\M\T', time() + $expiresInSeconds),
            rateLimit: [],
        );
    }
}

/**
 * Deterministic fake for the inner transport. Calls dequeue a pre-enqueued
 * response or throw a pre-enqueued exception, in FIFO order.
 */
final class RecordingInnerClient implements EsiClientInterface
{
    public int $calls = 0;

    /** @var array<int, EsiResponse|\Throwable> */
    private array $queue = [];

    public function enqueue(EsiResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function enqueueException(\Throwable $e): void
    {
        $this->queue[] = $e;
    }

    public function get(
        string $path,
        array $query = [],
        ?string $bearerToken = null,
        array $headers = [],
        bool $forceRefresh = false,
    ): EsiResponse {
        $this->calls++;

        if ($this->queue === []) {
            throw new \LogicException('RecordingInnerClient exhausted — test forgot to enqueue a response');
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
