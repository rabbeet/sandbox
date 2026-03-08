<?php

namespace Tests\Feature\Admin;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Jobs\ScrapeAirportSourceJob;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tests for AdminActionController — web UI operator actions.
 *
 * Routes under test:
 *   POST  /admin/sources/{source}/scrape
 *   POST  /admin/sources/{source}/parser-versions/{parserVersion}/activate
 *   PATCH /admin/failures/{failure}
 *
 * CSRF is disabled per-request via webPost/webPatch helpers so that session
 * middleware and route model binding remain active (needed for flash messages
 * and 404 assertions).
 */
class AdminActionControllerTest extends TestCase
{
    use RefreshDatabase;

    private const CSRF = 'test-csrf-token';

    /**
     * POST with a synthetic CSRF token injected into both the session and the
     * request body so that ValidateCsrfToken passes without disabling middleware.
     * This keeps SubstituteBindings (route model binding) and session middleware
     * fully active, which is required for 404 assertions and session flash checks.
     */
    private function webPost(string $url, array $data = []): TestResponse
    {
        return $this->withSession(['_token' => self::CSRF])
                    ->post($url, array_merge($data, ['_token' => self::CSRF]));
    }

    /** PATCH with a synthetic CSRF token — see webPost. */
    private function webPatch(string $url, array $data = []): TestResponse
    {
        return $this->withSession(['_token' => self::CSRF])
                    ->patch($url, array_merge($data, ['_token' => self::CSRF]));
    }

    private Airport $airport;
    private AirportSource $source;
    private ParserVersion $parserVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->airport = Airport::create([
            'iata'      => 'TST',
            'name'      => 'Test Airport',
            'city'      => 'Testville',
            'country'   => 'US',
            'timezone'  => 'UTC',
            'is_active' => true,
        ]);

        $this->source = AirportSource::create([
            'airport_id'              => $this->airport->id,
            'board_type'              => 'departures',
            'source_type'             => 'json_endpoint',
            'url'                     => 'https://example.com/flights',
            'scrape_interval_minutes' => 15,
            'is_active'               => true,
        ]);

        $this->parserVersion = ParserVersion::create([
            'airport_source_id' => $this->source->id,
            'version'           => 1,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => true,
            'activated_at'      => now(),
        ]);

        $this->source->update(['active_parser_version_id' => $this->parserVersion->id]);
    }

    // =========================================================================
    // POST /admin/sources/{source}/scrape
    // =========================================================================

    public function test_trigger_scrape_creates_pending_job_and_dispatches(): void
    {
        Queue::fake();

        $response = $this->webPost("/admin/sources/{$this->source->id}/scrape");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Queue::assertPushed(ScrapeAirportSourceJob::class, function ($job) {
            return $job->airportSourceId === $this->source->id;
        });

        $this->assertDatabaseHas('scrape_jobs', [
            'airport_source_id' => $this->source->id,
            'status'            => 'pending',
            'triggered_by'      => 'manual',
        ]);
    }

    public function test_trigger_scrape_flash_contains_job_id(): void
    {
        Queue::fake();

        $this->webPost("/admin/sources/{$this->source->id}/scrape");

        $jobId = ScrapeJob::where('airport_source_id', $this->source->id)->value('id');

        $this->assertEquals(
            "Scrape queued (job #{$jobId}).",
            session('success')
        );
    }

    public function test_trigger_scrape_fails_when_source_inactive(): void
    {
        Queue::fake();

        $this->source->update(['is_active' => false]);

        $response = $this->webPost("/admin/sources/{$this->source->id}/scrape");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('scrape_jobs', ['airport_source_id' => $this->source->id]);
    }

    public function test_trigger_scrape_fails_when_no_active_parser_version(): void
    {
        Queue::fake();

        $this->source->update(['active_parser_version_id' => null]);

        $response = $this->webPost("/admin/sources/{$this->source->id}/scrape");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_trigger_scrape_fails_when_job_already_pending(): void
    {
        Queue::fake();

        ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'status'            => 'pending',
            'triggered_by'      => 'scheduler',
        ]);

        $response = $this->webPost("/admin/sources/{$this->source->id}/scrape");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();

        // Still only one job in the database
        $this->assertCount(1, ScrapeJob::where('airport_source_id', $this->source->id)->get());
    }

    public function test_trigger_scrape_fails_when_job_already_running(): void
    {
        Queue::fake();

        ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'status'            => 'running',
            'started_at'        => now(),
        ]);

        $response = $this->webPost("/admin/sources/{$this->source->id}/scrape");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_trigger_scrape_succeeds_when_only_completed_jobs_exist(): void
    {
        Queue::fake();

        ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'status'            => 'success',
            'completed_at'      => now()->subMinutes(30),
        ]);

        $response = $this->webPost("/admin/sources/{$this->source->id}/scrape");

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Queue::assertPushed(ScrapeAirportSourceJob::class);
    }

    public function test_trigger_scrape_returns_404_for_unknown_source(): void
    {
        $response = $this->webPost('/admin/sources/99999/scrape');

        $response->assertNotFound();
    }

    // =========================================================================
    // POST /admin/sources/{source}/parser-versions/{parserVersion}/activate
    // =========================================================================

    public function test_activate_parser_version_marks_it_active(): void
    {
        $v2 = ParserVersion::create([
            'airport_source_id' => $this->source->id,
            'version'           => 2,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => false,
        ]);

        $response = $this->webPost("/admin/sources/{$this->source->id}/parser-versions/{$v2->id}/activate");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertTrue($v2->fresh()->is_active);
        $this->assertNotNull($v2->fresh()->activated_at);
    }

    public function test_activate_parser_version_deactivates_previous(): void
    {
        $v2 = ParserVersion::create([
            'airport_source_id' => $this->source->id,
            'version'           => 2,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => false,
        ]);

        $this->webPost("/admin/sources/{$this->source->id}/parser-versions/{$v2->id}/activate");

        // Original v1 should now be inactive
        $this->assertFalse($this->parserVersion->fresh()->is_active);
        $this->assertNotNull($this->parserVersion->fresh()->deactivated_at);
    }

    public function test_activate_parser_version_updates_source_fk(): void
    {
        $v2 = ParserVersion::create([
            'airport_source_id' => $this->source->id,
            'version'           => 2,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => false,
        ]);

        $this->webPost("/admin/sources/{$this->source->id}/parser-versions/{$v2->id}/activate");

        $this->assertEquals($v2->id, $this->source->fresh()->active_parser_version_id);
    }

    public function test_activate_parser_version_flash_includes_version_number(): void
    {
        $v2 = ParserVersion::create([
            'airport_source_id' => $this->source->id,
            'version'           => 2,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => false,
        ]);

        $this->webPost("/admin/sources/{$this->source->id}/parser-versions/{$v2->id}/activate");

        $this->assertEquals('Parser v2 activated.', session('success'));
    }

    public function test_activate_parser_version_returns_404_for_wrong_source(): void
    {
        $otherAirport = Airport::create([
            'iata'      => 'OTH',
            'name'      => 'Other Airport',
            'city'      => 'Other City',
            'country'   => 'US',
            'timezone'  => 'UTC',
            'is_active' => true,
        ]);

        $otherSource = AirportSource::create([
            'airport_id'              => $otherAirport->id,
            'board_type'              => 'arrivals',
            'source_type'             => 'json_endpoint',
            'url'                     => 'https://example.com/other',
            'scrape_interval_minutes' => 15,
            'is_active'               => true,
        ]);

        // parserVersion belongs to $this->source, not $otherSource
        $response = $this->webPost("/admin/sources/{$otherSource->id}/parser-versions/{$this->parserVersion->id}/activate");

        $response->assertNotFound();
    }

    public function test_activate_already_active_version_is_idempotent(): void
    {
        // Activating the already-active version should succeed without error
        $response = $this->webPost("/admin/sources/{$this->source->id}/parser-versions/{$this->parserVersion->id}/activate");

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertTrue($this->parserVersion->fresh()->is_active);
    }

    // =========================================================================
    // PATCH /admin/failures/{failure}
    // =========================================================================

    private function makeFailure(array $overrides = []): ParserFailure
    {
        return ParserFailure::create(array_merge([
            'airport_source_id' => $this->source->id,
            'failure_type'      => 'hard',
            'severity'          => 'critical',
            'error_code'        => 'scraper_runtime_http_500',
            'error_message'     => 'HTTP 500',
            'status'            => 'open',
        ], $overrides));
    }

    public function test_update_failure_open_to_investigating(): void
    {
        $failure = $this->makeFailure(['status' => 'open']);

        $response = $this->webPatch("/admin/failures/{$failure->id}", ['status' => 'investigating']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals('investigating', $failure->fresh()->status);
    }

    public function test_update_failure_open_to_repaired_sets_resolved_at(): void
    {
        $failure = $this->makeFailure(['status' => 'open']);

        $this->webPatch("/admin/failures/{$failure->id}", ['status' => 'repaired']);

        $fresh = $failure->fresh();
        $this->assertEquals('repaired', $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_update_failure_open_to_ignored_sets_resolved_at(): void
    {
        $failure = $this->makeFailure(['status' => 'open']);

        $this->webPatch("/admin/failures/{$failure->id}", ['status' => 'ignored']);

        $fresh = $failure->fresh();
        $this->assertEquals('ignored', $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_update_failure_investigating_to_repaired(): void
    {
        $failure = $this->makeFailure(['status' => 'investigating']);

        $response = $this->webPatch("/admin/failures/{$failure->id}", ['status' => 'repaired']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals('repaired', $failure->fresh()->status);
    }

    public function test_update_failure_repaired_is_terminal(): void
    {
        $failure = $this->makeFailure(['status' => 'repaired', 'resolved_at' => now()]);

        $response = $this->webPatch("/admin/failures/{$failure->id}", ['status' => 'ignored']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertEquals('repaired', $failure->fresh()->status); // unchanged
    }

    public function test_update_failure_ignored_is_terminal(): void
    {
        $failure = $this->makeFailure(['status' => 'ignored', 'resolved_at' => now()]);

        $response = $this->webPatch("/admin/failures/{$failure->id}", ['status' => 'repaired']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertEquals('ignored', $failure->fresh()->status); // unchanged
    }

    public function test_update_failure_flash_includes_new_status(): void
    {
        $failure = $this->makeFailure(['status' => 'open']);

        $this->webPatch("/admin/failures/{$failure->id}", ['status' => 'investigating']);

        $this->assertEquals('Failure marked as investigating.', session('success'));
    }

    public function test_update_failure_rejects_invalid_status(): void
    {
        $failure = $this->makeFailure(['status' => 'open']);

        $response = $this->webPatch("/admin/failures/{$failure->id}", ['status' => 'open']);

        $response->assertSessionHasErrors('status');
        $this->assertEquals('open', $failure->fresh()->status); // unchanged
    }

    public function test_update_failure_returns_404_for_unknown_failure(): void
    {
        $response = $this->webPatch('/admin/failures/99999', ['status' => 'repaired']);

        $response->assertNotFound();
    }

    public function test_update_failure_does_not_overwrite_existing_resolved_at(): void
    {
        $resolvedAt = now()->subHour();
        $failure    = $this->makeFailure(['status' => 'investigating', 'resolved_at' => $resolvedAt]);

        $this->webPatch("/admin/failures/{$failure->id}", ['status' => 'repaired']);

        // resolved_at should not be moved forward because it was already set
        $this->assertEquals(
            $resolvedAt->toDateTimeString(),
            $failure->fresh()->resolved_at->toDateTimeString()
        );
    }
}
