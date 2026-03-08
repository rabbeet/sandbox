<?php

namespace Tests\Feature\Repairs;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Repairs\Jobs\NotifyCriticalParserFailureJob;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Alert wiring: verifies that a critical ParserFailure triggers
 * NotifyCriticalParserFailureJob, and that the job correctly sends
 * Slack webhook and PagerDuty Events API calls.
 */
class AlertWiringTest extends TestCase
{
    use RefreshDatabase;

    private AirportSource $source;
    private ParserVersion $parserVersion;
    private ScrapeJob $scrapeJob;

    protected function setUp(): void
    {
        parent::setUp();

        $airport = Airport::create([
            'iata'      => 'ALT',
            'name'      => 'Alert Test Airport',
            'city'      => 'Alertville',
            'country'   => 'US',
            'timezone'  => 'UTC',
            'is_active' => true,
        ]);

        $this->source = AirportSource::create([
            'airport_id'              => $airport->id,
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

        $this->scrapeJob = ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $this->parserVersion->id,
            'status'            => 'success',
            'scheduled_at'      => now(),
            'started_at'        => now(),
            'completed_at'      => now(),
            'duration_ms'       => 100,
            'row_count'         => 0,
            'quality_score'     => 1.0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Observer dispatch
    // -------------------------------------------------------------------------

    public function test_critical_hard_failure_dispatches_notification_job(): void
    {
        Queue::fake();

        ParserFailure::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $this->parserVersion->id,
            'scrape_job_id'     => $this->scrapeJob->id,
            'failure_type'      => 'hard',
            'severity'          => 'critical',
            'error_code'        => 'scraper_runtime_http_500',
            'error_message'     => 'HTTP 500',
            'failure_details'   => ['http_status' => 500],
            'status'            => 'open',
        ]);

        Queue::assertPushed(NotifyCriticalParserFailureJob::class, function ($job) {
            return $job->parserFailureId === ParserFailure::latest()->first()->id;
        });
    }

    public function test_non_critical_soft_failure_does_not_dispatch_notification(): void
    {
        Queue::fake();

        ParserFailure::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $this->parserVersion->id,
            'scrape_job_id'     => $this->scrapeJob->id,
            'failure_type'      => 'soft',
            'severity'          => 'low',
            'error_code'        => 'zero_rows',
            'error_message'     => 'Zero rows returned',
            'failure_details'   => [],
            'status'            => 'open',
        ]);

        Queue::assertNotPushed(NotifyCriticalParserFailureJob::class);
    }

    public function test_critical_soft_failure_escalation_dispatches_notification(): void
    {
        Queue::fake();

        // A soft failure that has escalated to critical (e.g. 7+ consecutive)
        ParserFailure::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $this->parserVersion->id,
            'scrape_job_id'     => $this->scrapeJob->id,
            'failure_type'      => 'soft',
            'severity'          => 'critical',
            'error_code'        => 'zero_rows',
            'error_message'     => 'Zero rows returned — 7th consecutive',
            'failure_details'   => [],
            'status'            => 'open',
        ]);

        Queue::assertPushed(NotifyCriticalParserFailureJob::class);
    }

    public function test_notification_dispatched_to_repairs_queue(): void
    {
        Queue::fake();

        ParserFailure::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $this->parserVersion->id,
            'scrape_job_id'     => $this->scrapeJob->id,
            'failure_type'      => 'hard',
            'severity'          => 'critical',
            'error_code'        => 'scraper_runtime_http_500',
            'error_message'     => 'HTTP 500',
            'failure_details'   => ['http_status' => 500],
            'status'            => 'open',
        ]);

        Queue::assertPushedOn('repairs', NotifyCriticalParserFailureJob::class);
    }

    // -------------------------------------------------------------------------
    // NotifyCriticalParserFailureJob — Slack
    // -------------------------------------------------------------------------

    public function test_job_sends_slack_webhook_when_configured(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response('ok', 200)]);

        config(['services.alerts.slack_webhook_url' => 'https://hooks.slack.com/services/TEST']);
        config(['services.alerts.pagerduty_routing_key' => null]);

        $failure = $this->createCriticalFailure();

        (new NotifyCriticalParserFailureJob($failure->id))->handle();

        Http::assertSent(function ($request) use ($failure) {
            $body = $request->data();
            return str_contains($request->url(), 'hooks.slack.com')
                && str_contains($body['text'], 'ALT')
                && str_contains($body['text'], (string) $failure->id)
                && str_contains($body['text'], 'scraper_runtime_http_500');
        });
    }

    public function test_job_includes_http_status_in_slack_message_when_present(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response('ok', 200)]);

        config(['services.alerts.slack_webhook_url' => 'https://hooks.slack.com/services/TEST']);
        config(['services.alerts.pagerduty_routing_key' => null]);

        $failure = $this->createCriticalFailure(['failure_details' => ['http_status' => 503]]);

        (new NotifyCriticalParserFailureJob($failure->id))->handle();

        Http::assertSent(function ($request) {
            return str_contains($request->data()['text'], 'HTTP 503');
        });
    }

    public function test_job_skips_slack_when_not_configured(): void
    {
        Http::fake();

        config(['services.alerts.slack_webhook_url' => null]);
        config(['services.alerts.pagerduty_routing_key' => null]);

        $failure = $this->createCriticalFailure();

        (new NotifyCriticalParserFailureJob($failure->id))->handle();

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // NotifyCriticalParserFailureJob — PagerDuty
    // -------------------------------------------------------------------------

    public function test_job_sends_pagerduty_event_when_configured(): void
    {
        Http::fake(['https://events.pagerduty.com/*' => Http::response(['status' => 'success'], 202)]);

        config(['services.alerts.slack_webhook_url' => null]);
        config(['services.alerts.pagerduty_routing_key' => 'test_routing_key_abc']);

        $failure = $this->createCriticalFailure();

        (new NotifyCriticalParserFailureJob($failure->id))->handle();

        Http::assertSent(function ($request) use ($failure) {
            $body = $request->data();
            return str_contains($request->url(), 'pagerduty.com')
                && $body['routing_key'] === 'test_routing_key_abc'
                && $body['event_action'] === 'trigger'
                && $body['dedup_key'] === "parser_failure_{$failure->id}"
                && $body['payload']['severity'] === 'critical'
                && str_contains($body['payload']['summary'], 'ALT');
        });
    }

    public function test_job_skips_pagerduty_when_not_configured(): void
    {
        Http::fake();

        config(['services.alerts.slack_webhook_url' => null]);
        config(['services.alerts.pagerduty_routing_key' => null]);

        $failure = $this->createCriticalFailure();

        (new NotifyCriticalParserFailureJob($failure->id))->handle();

        Http::assertNothingSent();
    }

    public function test_job_sends_both_when_both_configured(): void
    {
        Http::fake([
            'https://hooks.slack.com/*'       => Http::response('ok', 200),
            'https://events.pagerduty.com/*'  => Http::response(['status' => 'success'], 202),
        ]);

        config(['services.alerts.slack_webhook_url' => 'https://hooks.slack.com/services/TEST']);
        config(['services.alerts.pagerduty_routing_key' => 'test_routing_key_abc']);

        $failure = $this->createCriticalFailure();

        (new NotifyCriticalParserFailureJob($failure->id))->handle();

        Http::assertSentCount(2);
    }

    public function test_job_handles_missing_failure_gracefully(): void
    {
        Http::fake();

        config(['services.alerts.slack_webhook_url' => 'https://hooks.slack.com/services/TEST']);

        // Use an ID that does not exist
        (new NotifyCriticalParserFailureJob(99999))->handle();

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createCriticalFailure(array $overrides = []): ParserFailure
    {
        return ParserFailure::create(array_merge([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $this->parserVersion->id,
            'scrape_job_id'     => $this->scrapeJob->id,
            'failure_type'      => 'hard',
            'severity'          => 'critical',
            'error_code'        => 'scraper_runtime_http_500',
            'error_message'     => 'HTTP 500 from scraper runtime',
            'failure_details'   => ['http_status' => 500],
            'status'            => 'open',
        ], $overrides));
    }
}
