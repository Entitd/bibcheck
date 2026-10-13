<?php

namespace App\Jobs;

use App\Models\ExternalCheckEntry;
use App\Models\ExternalCheckRun;
use App\Services\Bibliography\DeferredCheck;
use App\Services\Bibliography\ExternalCheckService;
use App\Services\Bibliography\SourceVerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VerifyBibliographyEntry implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 0;

    public function __construct(public int $entryId, public string $deadline) {}

    public function retryUntil(): \DateTimeInterface
    {
        return new \DateTimeImmutable($this->deadline);
    }

    public function handle(SourceVerificationService $verification, ExternalCheckService $checks): void
    {
        $lock = Cache::lock('bib:entry:'.$this->entryId, 120);
        if (! $lock->get()) {
            $this->release(2);

            return;
        }
        try {
            $entry = ExternalCheckEntry::with('run')->find($this->entryId);
            if (! $entry || $entry->status === 'completed' || ! $entry->run || ! in_array($entry->run->status, ['pending', 'running'])) {
                return;
            }
            ExternalCheckRun::whereKey($entry->run_id)->whereIn('status', ['pending', 'running'])->update(['status' => 'running', 'started_at' => $entry->run->started_at ?? now()]);
            $entry->update(['status' => 'checking']);
            try {
                $result = $verification->verify($entry->input['fields'], $entry->input['type'], true,
                    config('bibliography.max_requests') - $entry->request_count,
                    // Reserve durably before sending: a worker crash must still consume this call.
                    fn () => ExternalCheckEntry::whereKey($entry->id)->where('request_count', '<', config('bibliography.max_requests'))
                        ->whereHas('run', fn ($query) => $query->whereIn('status', ['pending', 'running']))
                        ->update(['request_count' => DB::raw('request_count + 1')]) === 1
                );
            } catch (DeferredCheck $e) {
                $this->release($e->delay);

                return;
            }
            $entry = ExternalCheckEntry::find($this->entryId);
            if (! $entry) {
                return;
            }
            $temporary = collect($result['provider_checks'])->whereIn('status', ['rate_limited', 'unavailable']);
            if (! $result['complete'] && $temporary->isNotEmpty() && $entry->retry_count < 3 && $entry->request_count < config('bibliography.max_requests')) {
                $entry->increment('retry_count');
                $this->release(max([5, 15, 60][$entry->retry_count - 1], (int) $temporary->max('retry_after')));

                return;
            }
            $checks->finish($entry->id, $result);
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(ExternalCheckService::class)->finish($this->entryId, [
            'schema_version' => 2, 'status' => 'incomplete', 'found' => false, 'complete' => false,
            'message' => 'Проверка не завершена в отведённое время. Повторите запуск.',
            'provider_checks' => [], 'checked_at' => now()->toIso8601String(),
        ]);
    }
}
