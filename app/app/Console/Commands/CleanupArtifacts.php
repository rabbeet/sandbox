<?php

namespace App\Console\Commands;

use App\Domain\Scraping\Models\ScrapeArtifact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupArtifacts extends Command
{
    protected $signature = 'scrapes:cleanup';
    protected $description = 'Delete expired scrape artifacts from storage and database';

    public function handle(): int
    {
        $expired = ScrapeArtifact::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired artifacts to clean up.');
            return self::SUCCESS;
        }

        $deleted = 0;
        $errors  = 0;

        foreach ($expired as $artifact) {
            if ($artifact->storage_path) {
                try {
                    Storage::delete($artifact->storage_path);
                } catch (\Throwable $e) {
                    Log::warning('CleanupArtifacts: failed to delete from storage', [
                        'artifact_id'  => $artifact->id,
                        'storage_path' => $artifact->storage_path,
                        'error'        => $e->getMessage(),
                    ]);
                    $errors++;
                }
            }

            $artifact->delete();
            $deleted++;
        }

        $this->info("Cleaned up {$deleted} expired artifact(s). Errors: {$errors}.");

        Log::info('scrapes:cleanup completed', [
            'deleted' => $deleted,
            'errors'  => $errors,
        ]);

        return self::SUCCESS;
    }
}
