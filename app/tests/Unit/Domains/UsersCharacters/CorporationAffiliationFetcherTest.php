<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\UsersCharacters;

use App\Domains\UsersCharacters\Models\CorporationAffiliationProfile;
use App\Domains\UsersCharacters\Services\CorporationAffiliationFetcher;
use App\Domains\UsersCharacters\Services\CorporationAffiliationSyncResult;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiException;
use App\Services\Eve\Esi\EsiResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Smoke tests for {@see CorporationAffiliationFetcher}.
 *
 * The fetcher talks to an in-process {@see RecordingEsiClient} that
 * replays queued responses or throws queued exceptions, and to a
 * stand-alone SQLite schema for the one table it writes to.
 *
 * setUp() creates just `corporation_affiliation_profiles` directly
 * rather than running the full migration set, because the repo's
 * migration chain contains raw MariaDB-only DDL
 * (`market_history` partitioning) that SQLite can't parse. This
 * narrow setup lets the fetcher's DB behaviour be verified locally
 * without depending on the full suite's DB availability.
 */
final class CorporationAffiliationFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Build just the one table this fetcher writes to — matches
        // the migration
        // `2026_04_15_000004_create_corporation_affiliation_profiles_table.php`
        // column-for-column for the fields the fetcher touches.
        Schema::create('corporation_affiliation_profiles', function (Blueprint $t) {
            $t->unsignedBigInteger('corporation_id')->primary();
            $t->unsignedBigInteger('current_alliance_id')->nullable();
            $t->unsignedBigInteger('previous_alliance_id')->nullable();
            $t->timestamp('last_alliance_change_at')->nullable();
            $t->boolean('recently_changed_affiliation')->default(false);
            $t->string('history_confidence_band', 16)->default('low');
            $t->timestamp('observed_at')->useCurrent();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('corporation_affiliation_profiles');
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_single_alliance_history_yields_medium_confidence_with_no_previous(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');

        $esi = new RecordingEsiClient;
        $esi->enqueueJson(['alliance_id' => 99000001]);
        $esi->enqueueJson([
            ['record_id' => 1, 'alliance_id' => 99000001, 'start_date' => '2024-01-15T10:00:00Z'],
        ]);

        $fetcher = new CorporationAffiliationFetcher($esi);
        $result = $fetcher->sync(98000001);

        $this->assertTrue($result->isSynced());
        $this->assertSame(99000001, $result->currentAllianceId);
        $this->assertNull($result->previousAllianceId);

        $profile = CorporationAffiliationProfile::query()->find(98000001);
        $this->assertNotNull($profile);
        $this->assertSame(99000001, $profile->current_alliance_id);
        $this->assertNull($profile->previous_alliance_id);
        $this->assertSame(CorporationAffiliationProfile::CONFIDENCE_MEDIUM, $profile->history_confidence_band);
        $this->assertSame('2024-01-15 10:00:00', $profile->last_alliance_change_at->format('Y-m-d H:i:s'));
        $this->assertFalse($profile->recently_changed_affiliation);
    }

    public function test_full_history_yields_high_confidence_with_previous_alliance(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');

        $esi = new RecordingEsiClient;
        $esi->enqueueJson(['alliance_id' => 99000002]);
        $esi->enqueueJson([
            ['record_id' => 10, 'alliance_id' => 99000002, 'start_date' => '2025-12-01T00:00:00Z'],
            ['record_id' => 9, 'alliance_id' => 99000001, 'start_date' => '2023-06-15T00:00:00Z'],
            ['record_id' => 8, 'alliance_id' => 99000003, 'start_date' => '2021-01-01T00:00:00Z'],
        ]);

        $fetcher = new CorporationAffiliationFetcher($esi);
        $result = $fetcher->sync(98000002);

        $this->assertTrue($result->isSynced());
        $this->assertSame(99000002, $result->currentAllianceId);
        $this->assertSame(99000001, $result->previousAllianceId);

        $profile = CorporationAffiliationProfile::query()->find(98000002);
        $this->assertSame(CorporationAffiliationProfile::CONFIDENCE_HIGH, $profile->history_confidence_band);
        $this->assertSame('2025-12-01 00:00:00', $profile->last_alliance_change_at->format('Y-m-d H:i:s'));
    }

    public function test_recently_changed_affiliation_flag_trips_inside_window(): void
    {
        // Now = 2026-04-15; last change was 2026-04-10 (5 days ago) —
        // inside the default 14-day window.
        Carbon::setTestNow('2026-04-15 12:00:00');

        $esi = new RecordingEsiClient;
        $esi->enqueueJson(['alliance_id' => 99000002]);
        $esi->enqueueJson([
            ['record_id' => 2, 'alliance_id' => 99000002, 'start_date' => '2026-04-10T00:00:00Z'],
            ['record_id' => 1, 'alliance_id' => 99000001, 'start_date' => '2023-06-15T00:00:00Z'],
        ]);

        $fetcher = new CorporationAffiliationFetcher($esi);
        $fetcher->sync(98000003);

        $profile = CorporationAffiliationProfile::query()->find(98000003);
        $this->assertTrue($profile->recently_changed_affiliation);
    }

    public function test_recently_changed_affiliation_flag_off_outside_window(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');

        $esi = new RecordingEsiClient;
        $esi->enqueueJson(['alliance_id' => 99000002]);
        $esi->enqueueJson([
            // 30 days ago — outside the 14-day window.
            ['record_id' => 2, 'alliance_id' => 99000002, 'start_date' => '2026-03-16T00:00:00Z'],
            ['record_id' => 1, 'alliance_id' => 99000001, 'start_date' => '2023-06-15T00:00:00Z'],
        ]);

        $fetcher = new CorporationAffiliationFetcher($esi);
        $fetcher->sync(98000004);

        $profile = CorporationAffiliationProfile::query()->find(98000004);
        $this->assertFalse($profile->recently_changed_affiliation);
    }

    public function test_no_alliance_now_and_empty_history_writes_low_confidence(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');

        $esi = new RecordingEsiClient;
        $esi->enqueueJson(['name' => 'Solo Corp']); // no alliance_id
        $esi->enqueueJson([]);                       // empty history

        $fetcher = new CorporationAffiliationFetcher($esi);
        $result = $fetcher->sync(98000005);

        $this->assertTrue($result->isSynced());
        $this->assertNull($result->currentAllianceId);

        $profile = CorporationAffiliationProfile::query()->find(98000005);
        $this->assertNull($profile->current_alliance_id);
        $this->assertNull($profile->previous_alliance_id);
        $this->assertSame(CorporationAffiliationProfile::CONFIDENCE_LOW, $profile->history_confidence_band);
        $this->assertFalse($profile->recently_changed_affiliation);
    }

    public function test_current_endpoint_failure_returns_failed_and_does_not_write(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');

        $esi = new RecordingEsiClient;
        $esi->enqueueException(new EsiException('upstream 500', status: 500));

        $fetcher = new CorporationAffiliationFetcher($esi);
        $result = $fetcher->sync(98000006);

        $this->assertSame(CorporationAffiliationSyncResult::STATUS_FAILED, $result->status);
        $this->assertNull(CorporationAffiliationProfile::query()->find(98000006));
    }

    public function test_history_endpoint_failure_still_writes_current_with_low_confidence(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');

        $esi = new RecordingEsiClient;
        $esi->enqueueJson(['alliance_id' => 99000001]);
        $esi->enqueueException(new EsiException('history 503', status: 503));

        $fetcher = new CorporationAffiliationFetcher($esi);
        $result = $fetcher->sync(98000007);

        $this->assertTrue($result->isSynced(), 'partial success still counts as synced');

        $profile = CorporationAffiliationProfile::query()->find(98000007);
        $this->assertSame(99000001, $profile->current_alliance_id);
        $this->assertNull($profile->previous_alliance_id);
        $this->assertNull($profile->last_alliance_change_at);
        $this->assertSame(CorporationAffiliationProfile::CONFIDENCE_LOW, $profile->history_confidence_band);
    }

    public function test_reverse_sorted_history_still_picks_the_most_recent_entry(): void
    {
        // ESI has been known to return these in varying orders. We
        // sort ourselves. Feed them oldest-first to verify the sort
        // is load-bearing.
        Carbon::setTestNow('2026-04-15 12:00:00');

        $esi = new RecordingEsiClient;
        $esi->enqueueJson(['alliance_id' => 99000002]);
        $esi->enqueueJson([
            ['record_id' => 1, 'alliance_id' => 99000003, 'start_date' => '2021-01-01T00:00:00Z'],
            ['record_id' => 2, 'alliance_id' => 99000001, 'start_date' => '2023-06-15T00:00:00Z'],
            ['record_id' => 3, 'alliance_id' => 99000002, 'start_date' => '2025-12-01T00:00:00Z'],
        ]);

        $fetcher = new CorporationAffiliationFetcher($esi);
        $fetcher->sync(98000008);

        $profile = CorporationAffiliationProfile::query()->find(98000008);
        $this->assertSame(99000001, $profile->previous_alliance_id);
        $this->assertSame('2025-12-01 00:00:00', $profile->last_alliance_change_at->format('Y-m-d H:i:s'));
    }

    public function test_resync_updates_existing_profile_in_place(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');

        // Seed an existing row that says "in alliance A".
        CorporationAffiliationProfile::query()->create([
            'corporation_id' => 98000009,
            'current_alliance_id' => 99000001,
            'history_confidence_band' => CorporationAffiliationProfile::CONFIDENCE_MEDIUM,
            'observed_at' => Carbon::parse('2026-04-01 00:00:00'),
        ]);

        $esi = new RecordingEsiClient;
        $esi->enqueueJson(['alliance_id' => 99000002]);
        $esi->enqueueJson([
            ['record_id' => 2, 'alliance_id' => 99000002, 'start_date' => '2026-04-14T00:00:00Z'],
            ['record_id' => 1, 'alliance_id' => 99000001, 'start_date' => '2023-06-15T00:00:00Z'],
        ]);

        $fetcher = new CorporationAffiliationFetcher($esi);
        $fetcher->sync(98000009);

        $profile = CorporationAffiliationProfile::query()->find(98000009);
        $this->assertSame(99000002, $profile->current_alliance_id);
        $this->assertSame(99000001, $profile->previous_alliance_id);
        $this->assertSame(CorporationAffiliationProfile::CONFIDENCE_HIGH, $profile->history_confidence_band);
        $this->assertTrue($profile->recently_changed_affiliation);
        // observed_at refreshed to "now".
        $this->assertSame('2026-04-15 12:00:00', $profile->observed_at->format('Y-m-d H:i:s'));
    }
}

/**
 * In-process stub of the ESI transport. Matches the pattern used in
 * {@see \Tests\Unit\Services\Eve\Esi\CachedEsiClientTest}.
 */
final class RecordingEsiClient implements EsiClientInterface
{
    /** @var list<EsiResponse|\Throwable> */
    private array $queue = [];

    public int $calls = 0;

    public function enqueueJson(array $body): void
    {
        $this->queue[] = new EsiResponse(
            status: 200,
            body: $body,
            notModified: false,
            etag: null,
            lastModified: null,
            expires: gmdate('D, d M Y H:i:s \G\M\T', time() + 3600),
            rateLimit: [],
        );
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
            throw new \RuntimeException("RecordingEsiClient ran out of queued responses on call #{$this->calls} ({$path})");
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function post(
        string $path,
        array $body = [],
        ?string $bearerToken = null,
        array $headers = [],
    ): EsiResponse {
        return $this->get($path);
    }
}
