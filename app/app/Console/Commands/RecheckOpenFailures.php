<?php

namespace App\Console\Commands;

use App\Domain\Repairs\Models\ParserFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecheckOpenFailures extends Command
{
    protected $signature = 'repairs:recheck-open-failures';
    protected $description = 'Recheck open parser failures and trigger AI repair candidates (Phase 5)';

    public function handle(): int
    {
        // Phase 5 stub — anomaly detection, parser failure workflow, and AI repair
        // will be implemented in Phase 5.

        $openCount = ParserFailure::whereIn('status', ['open', 'pending_repair'])->count();

        $this->info("repairs:recheck-open-failures: {$openCount} open failure(s) found (repair pipeline pending Phase 5).");

        Log::info('repairs:recheck-open-failures: stub run', ['open_failures' => $openCount]);

        return self::SUCCESS;
    }
}
