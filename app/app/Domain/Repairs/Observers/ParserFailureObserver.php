<?php

namespace App\Domain\Repairs\Observers;

use App\Domain\Repairs\Jobs\NotifyCriticalParserFailureJob;
use App\Domain\Repairs\Models\ParserFailure;

class ParserFailureObserver
{
    /**
     * Alert on-call when a critical failure is opened. Both hard failures
     * (severity is always critical) and soft failures that have escalated to
     * critical trigger this path.
     *
     * The notification is dispatched as a queued job so the observer returns
     * instantly and does not add latency to the scrape pipeline.
     */
    public function created(ParserFailure $failure): void
    {
        if ($failure->severity === 'critical') {
            NotifyCriticalParserFailureJob::dispatch($failure->id)
                ->onQueue('repairs');
        }
    }
}
