<?php

namespace App\Services\Bibliography;

use App\Jobs\VerifyBibliographyEntry;
use App\Models\CheckHistory;
use App\Models\ExternalCheckEntry;
use App\Models\ExternalCheckRun;
use App\Models\User;
use App\Services\Bibtex\Parser;
use App\Services\BibtexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExternalCheckService
{
    public function __construct(private BibtexService $bibtex, private Parser $parser) {}

    public function start(User $user, string $content, string $filename): ExternalCheckRun
    {
        if (mb_strlen($content) > 100000) {
            throw ValidationException::withMessages(['bib_content' => 'Текст слишком большой.']);
        }
        if ($user->isGuest() && $user->isGuestSessionExpired()) {
            abort(401, 'Гостевая сессия истекла.');
        }
        if (! config('bibliography.enabled')) {
            throw ValidationException::withMessages(['bib_content' => 'Внешняя проверка отключена администратором.']);
        }
        $synchronous = config('bibliography.execution') === 'sync';
        if (! $synchronous && config('queue.default') === 'sync') {
            throw ValidationException::withMessages(['bib_content' => 'Для проверки файла требуется фоновая очередь.']);
        }
        $parsed = $this->parser->parse($content);
        $keys = array_map(fn ($entry) => $entry->key, $parsed['entries']);
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['bib_content' => 'Обнаружены повторяющиеся ключи BibLaTeX.']);
        }
        if (! $keys || count($keys) > config('bibliography.max_entries')) {
            throw ValidationException::withMessages(['bib_content' => 'Допустимо от 1 до '.config('bibliography.max_entries').' записей.']);
        }
        foreach ($keys as $key) {
            if (mb_strlen($key) > 255) {
                throw ValidationException::withMessages(['bib_content' => 'Ключ записи длиннее 255 символов.']);
            }
        }
        if ($synchronous && count($keys) > config('bibliography.sync_max_entries')) {
            throw ValidationException::withMessages(['bib_content' => 'Временный режим без очереди допускает до '.config('bibliography.sync_max_entries').' записей за проверку.']);
        }
        $hash = hash('sha256', $content);

        $run = DB::transaction(function () use ($user, $content, $filename, $hash, $synchronous) {
            // Serialize submissions per owner, including different simultaneous files.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $active = ExternalCheckRun::where('user_id', $user->id)->whereIn('status', ['pending', 'running']);
            $existing = (clone $active)->where('content_hash', $hash)->where('algorithm_version', config('bibliography.algorithm_version'))->first();
            if ($existing && ! $synchronous) {
                return $existing;
            }
            if (! $synchronous && $active->count() >= config('bibliography.max_active_runs')) {
                throw ValidationException::withMessages(['bib_content' => 'Сначала завершите или отмените активную проверку.']);
            }
            $analysis = $this->bibtex->localCheck($content);
            $department = $this->bibtex->departmentCheck($content);
            $analysis['department'] = $department['department'];
            $analysis['course_comparison_result'] = $department['department']['verdict'];
            $analysis['raw_content'] = $content;
            $analysis['original_filename'] = basename(str_replace('\\', '/', $filename));
            $analysis['schema_version'] = 2;
            $history = CheckHistory::create(['user_id' => $user->id, 'filename' => $analysis['original_filename'],
                'content' => $content, 'status' => 'pending', 'analysis_data' => $analysis, 'stats' => $analysis['aggregated_metrics'],
                'total_entries' => count($analysis['entries']), 'error_count' => collect($analysis['errors'])->whereIn('severity', ['error', 'syntax'])->count(),
                'warning_count' => collect($analysis['errors'])->whereNotIn('severity', ['error', 'syntax'])->count(),
                'verdict' => $analysis['course_comparison_result']]);
            $run = ExternalCheckRun::create(['user_id' => $user->id, 'check_history_id' => $history->id,
                'content_hash' => $hash, 'algorithm_version' => config('bibliography.algorithm_version'),
                'input_analysis' => $analysis, 'total_entries' => count($analysis['entries'])]);
            $position = 0;
            foreach ($analysis['entries'] as $key => $input) {
                $entry = $run->entries()->create(['entry_key' => $key, 'position' => $position++, 'input' => $input]);
                if (! $synchronous) {
                    VerifyBibliographyEntry::dispatch($entry->id, $run->created_at->copy()->addMinutes(30)->toIso8601String())->onQueue('external-checks')->afterCommit();
                }
            }

            return $run;
        });

        if ($synchronous) {
            $run->update(['status' => 'running', 'started_at' => now()]);
            foreach ($run->entries()->orderBy('position')->get() as $position => $entry) {
                // Space sequential calls without running a background worker.
                if ($position > 0) {
                    usleep(1000000);
                }
                $entry->update(['status' => 'checking']);
                $result = app(SourceVerificationService::class)->verify(
                    $entry->input['fields'], $entry->input['type'], false,
                    config('bibliography.max_requests'),
                    fn () => ExternalCheckEntry::whereKey($entry->id)
                        ->where('request_count', '<', config('bibliography.max_requests'))
                        ->update(['request_count' => DB::raw('request_count + 1')]) === 1
                );
                $this->finish($entry->id, $result);
            }
        }

        return $run->fresh();
    }

    public function finish(int $entryId, array $result): void
    {
        $entry = ExternalCheckEntry::find($entryId);
        if (! $entry) {
            return;
        }
        DB::transaction(function () use ($entry, $result) {
            // Always lock run before entry, matching cancel and other completion paths.
            $run = ExternalCheckRun::whereKey($entry->run_id)->lockForUpdate()->first();
            if (! $run || ! in_array($run->status, ['pending', 'running'])) {
                return;
            }
            $current = ExternalCheckEntry::whereKey($entry->id)->lockForUpdate()->first();
            if (! $current || $current->status === 'completed') {
                return;
            }
            $current->update(['status' => 'completed', 'result' => $result, 'checked_at' => now()]);
            $run->finished_entries++;
            if ($run->finished_entries === $run->total_entries) {
                $run->status = 'completed';
                $run->finished_at = now();
            }
            $run->save();
            if ($run->status === 'completed') {
                $this->saveHistory($run);
            }
        });
    }

    public function payload(ExternalCheckRun $run): array
    {
        $analysis = $run->input_analysis;
        foreach ($run->entries()->get() as $entry) {
            $analysis['entries'][$entry->entry_key]['api_check'] = $entry->result ?? [
                'schema_version' => 2, 'status' => $run->status === 'cancelled' ? 'incomplete' : ($entry->status === 'checking' ? 'checking' : 'queued'),
                'found' => false, 'complete' => false, 'message' => $run->status === 'cancelled' ? 'Проверка отменена.' : 'Ожидание проверки.',
            ];
        }
        $analysis['aggregated_metrics'] = array_merge($analysis['aggregated_metrics'], $this->bibtex->calculateApiMetrics($analysis['entries']));
        $analysis['external_run'] = ['id' => $run->id, 'status' => $run->status, 'finished_entries' => $run->finished_entries, 'total_entries' => $run->total_entries];

        return ['id' => $run->id, 'status' => $run->status, 'content_hash' => $run->content_hash,
            'finished_entries' => $run->finished_entries, 'total_entries' => $run->total_entries, 'analysis' => $analysis];
    }

    public function cancel(ExternalCheckRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = ExternalCheckRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, ['pending', 'running'])) {
                return;
            }
            $run->update(['status' => 'cancelled', 'finished_at' => now()]);
            $this->saveHistory($run);
        });
    }

    private function saveHistory(ExternalCheckRun $run): void
    {
        $history = CheckHistory::whereKey($run->check_history_id)->where('user_id', $run->user_id)->first();
        if (! $history || hash('sha256', $history->content) !== $run->content_hash) {
            return;
        }
        $analysis = $this->payload($run)['analysis'];
        $history->update(['status' => $run->status, 'analysis_data' => $analysis, 'stats' => $analysis['aggregated_metrics']]);
    }
}
