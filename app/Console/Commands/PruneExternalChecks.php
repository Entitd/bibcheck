<?php

namespace App\Console\Commands;

use App\Models\ExternalCheckRun;
use App\Services\Bibliography\ExternalCheckService;
use Illuminate\Console\Command;

class PruneExternalChecks extends Command
{
    protected $signature = 'bibliography:prune {--days=30 : Keep terminal runs for this many days}';

    protected $description = 'Finish abandoned external checks and prune old run details; preserve saved history';

    public function handle(ExternalCheckService $checks): int
    {
        ExternalCheckRun::whereIn('status', ['pending', 'running'])->where('created_at', '<', now()->subMinutes(35))
            ->each(function ($run) use ($checks) {
                foreach ($run->entries()->where('status', '!=', 'completed')->get() as $entry) {
                    $checks->finish($entry->id, ['schema_version' => 2, 'status' => 'incomplete', 'found' => false, 'complete' => false,
                        'message' => 'Истекло время проверки. Повторите запуск.', 'checked_at' => now()->toIso8601String()]);
                }
            });
        $deleted = ExternalCheckRun::whereIn('status', ['completed', 'cancelled', 'failed'])
            ->where('finished_at', '<', now()->subDays(max(1, (int) $this->option('days'))))->delete();
        $this->info("Pruned {$deleted} external check runs.");

        return self::SUCCESS;
    }
}
