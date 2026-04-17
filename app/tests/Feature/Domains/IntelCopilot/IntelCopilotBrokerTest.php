<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\IntelCopilot;

use App\Domains\IntelCopilot\Services\BrokerUnavailable;
use App\Domains\IntelCopilot\Services\IntelCopilotBroker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Laravel-side contract for the PHP → Python broker bridge.
 *
 * No live broker — every test scripts ``Http::fake`` responses and asserts
 * the outgoing request shape (URL, headers, payload) and the resulting
 * ``BrokerResponse``. Keeps the unit under test honest about the
 * API contract without requiring the Python service at test time.
 */
class IntelCopilotBrokerTest extends TestCase
{
    private function broker(): IntelCopilotBroker
    {
        return new IntelCopilotBroker(
            http: app(HttpFactory::class),
            baseUrl: 'http://intel_copilot:8000',
            token: 'test-token',
            timeoutSeconds: 5,
        );
    }

    public function test_ask_posts_question_and_unpacks_success(): void
    {
        Http::fake([
            'intel_copilot:8000/ask' => Http::response([
                'parser' => 'heuristic',
                'plan' => ['intent' => 'count', 'plan_version' => '1'],
                'result' => [
                    'backend' => 'opensearch',
                    'rows' => [['label' => 'Catalyst', 'value' => 42]],
                    'total' => 42,
                    'took_ms' => 7,
                    'query' => ['size' => 0],
                ],
            ], 200),
        ]);

        $response = $this->broker()->ask('how many kills last 7 days');

        $this->assertTrue($response->ok);
        $this->assertSame('heuristic', $response->parser);
        $this->assertSame('opensearch', $response->backend);
        $this->assertSame(42, $response->total);
        $this->assertCount(1, $response->rows);
        $this->assertSame('Catalyst', $response->rows[0]['label']);

        Http::assertSent(function ($req): bool {
            return $req->url() === 'http://intel_copilot:8000/ask'
                && $req->method() === 'POST'
                && $req->header('X-Intel-Copilot-Token')[0] === 'test-token'
                && $req['question'] === 'how many kills last 7 days'
                && $req['use_llm'] === true;
        });
    }

    public function test_ask_4xx_becomes_failure_response_not_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => 'no heuristic template matched',
            ], 422),
        ]);

        $response = $this->broker()->ask('explain capital warfare', useLlm: false);

        $this->assertFalse($response->ok);
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('heuristic', $response->error ?? '');
    }

    public function test_connection_failure_throws_broker_unavailable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('broker down');
        });

        $this->expectException(BrokerUnavailable::class);
        $this->broker()->ask('anything');
    }

    public function test_no_token_means_no_header(): void
    {
        Http::fake(['*' => Http::response([
            'parser' => 'dict', 'plan' => [], 'result' => null,
        ], 200)]);

        $broker = new IntelCopilotBroker(
            http: app(HttpFactory::class),
            baseUrl: 'http://intel_copilot:8000',
            token: null,
            timeoutSeconds: 5,
        );

        $broker->executePlan(['intent' => 'count']);

        Http::assertSent(fn ($req) => ! $req->hasHeader('X-Intel-Copilot-Token'));
    }
}
