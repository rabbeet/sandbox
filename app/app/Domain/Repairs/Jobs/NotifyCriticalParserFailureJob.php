<?php

namespace App\Domain\Repairs\Jobs;

use App\Domain\Repairs\Models\ParserFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a critical-failure alert to Slack and/or PagerDuty.
 *
 * Both destinations are optional — if the relevant env vars are not set the
 * channel is silently skipped. This allows running in environments that only
 * have one channel configured (e.g. staging with Slack only).
 *
 * SLACK
 * -----
 * Uses an incoming webhook URL (ALERTS_SLACK_WEBHOOK_URL). No SDK or bot token
 * required. The message includes airport IATA, source ID, board type, error
 * code, HTTP status (if present), and the failure ID for direct lookup.
 *
 * PAGERDUTY
 * ---------
 * Uses the Events API v2 (PAGERDUTY_ROUTING_KEY). Each failure gets a stable
 * dedup_key so duplicate alerts from retries do not open multiple incidents.
 */
class NotifyCriticalParserFailureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public readonly int $parserFailureId) {}

    public function handle(): void
    {
        $failure = ParserFailure::with(['airportSource.airport'])->find($this->parserFailureId);

        if (! $failure) {
            Log::warning('NotifyCriticalParserFailureJob: failure not found', [
                'parser_failure_id' => $this->parserFailureId,
            ]);
            return;
        }

        $context = $this->buildContext($failure);

        $this->notifySlack($failure, $context);
        $this->notifyPagerDuty($failure, $context);
    }

    private function buildContext(ParserFailure $failure): array
    {
        $source    = $failure->airportSource;
        $airport   = $source?->airport;
        $iata      = $airport?->iata ?? 'UNKNOWN';
        $boardType = $source?->board_type ?? 'unknown';
        $httpStatus = $failure->failure_details['http_status'] ?? null;

        return compact('iata', 'boardType', 'httpStatus');
    }

    private function notifySlack(ParserFailure $failure, array $ctx): void
    {
        $webhookUrl = config('services.alerts.slack_webhook_url');
        if (empty($webhookUrl)) {
            return;
        }

        $httpDetail = $ctx['httpStatus'] ? " | HTTP {$ctx['httpStatus']}" : '';
        $text = implode("\n", [
            ":rotating_light: *Critical parser failure* — {$ctx['iata']} {$ctx['boardType']}",
            "Source ID: {$failure->airport_source_id} | Code: `{$failure->error_code}`{$httpDetail}",
            "Failure ID: {$failure->id} — `GET /api/sources/{$failure->airport_source_id}/failures`",
        ]);

        $response = Http::timeout(10)->post($webhookUrl, ['text' => $text]);

        if (! $response->successful()) {
            Log::error('NotifyCriticalParserFailureJob: Slack webhook failed', [
                'parser_failure_id' => $failure->id,
                'status'            => $response->status(),
                'body'              => $response->body(),
            ]);
        } else {
            Log::info('NotifyCriticalParserFailureJob: Slack alert sent', [
                'parser_failure_id' => $failure->id,
            ]);
        }
    }

    private function notifyPagerDuty(ParserFailure $failure, array $ctx): void
    {
        $routingKey = config('services.alerts.pagerduty_routing_key');
        if (empty($routingKey)) {
            return;
        }

        $summary = "Critical parser failure: [{$ctx['iata']}] {$ctx['boardType']} — {$failure->error_code}";

        $details = [
            'failure_id'        => $failure->id,
            'airport_source_id' => $failure->airport_source_id,
            'error_code'        => $failure->error_code,
            'error_message'     => $failure->error_message,
            'failure_details'   => $failure->failure_details,
            'review_url'        => "GET /api/sources/{$failure->airport_source_id}/failures",
        ];

        $response = Http::timeout(10)->post('https://events.pagerduty.com/v2/enqueue', [
            'routing_key'  => $routingKey,
            'event_action' => 'trigger',
            'dedup_key'    => "parser_failure_{$failure->id}",
            'payload'      => [
                'summary'        => $summary,
                'severity'       => 'critical',
                'source'         => 'airport-platform',
                'custom_details' => $details,
            ],
        ]);

        if (! $response->successful()) {
            Log::error('NotifyCriticalParserFailureJob: PagerDuty alert failed', [
                'parser_failure_id' => $failure->id,
                'status'            => $response->status(),
                'body'              => $response->body(),
            ]);
        } else {
            Log::info('NotifyCriticalParserFailureJob: PagerDuty alert sent', [
                'parser_failure_id' => $failure->id,
                'dedup_key'         => "parser_failure_{$failure->id}",
            ]);
        }
    }
}
