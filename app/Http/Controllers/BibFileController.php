<?php

namespace App\Http\Controllers;

use App\Models\BibFile;
use App\Models\CheckHistory;
use App\Services\Bibliography\ExternalCheckService;
use App\Services\BibtexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BibFileController extends Controller
{
    protected $parserService;

    public function __construct(BibtexService $parserService)
    {
        $this->parserService = $parserService;
    }

    public function upload(Request $request)
    {
        $request->validate(['bib_file' => 'required|file']);
        $path = $request->file('bib_file')->store('bib_uploads');

        $bibFile = BibFile::create([
            'filename' => $request->file('bib_file')->getClientOriginalName(),
            'path' => $path,
            'status' => 'completed',
        ]);

        $content = Storage::get($path);
        $analysisResults = $this->parserService->localCheck($content);

        $analysisResults['raw_content'] = $content;
        $analysisResults['original_filename'] = $bibFile->filename;
        $analysisResults['course_comparison_result'] = $this->generateVerdict($analysisResults['aggregated_metrics']);

        $this->saveToHistory($bibFile->filename, $content, $analysisResults);

        return redirect()->route('bib.blade')->with('analysis', $analysisResults);
    }

    public function create(Request $request)
    {
        $validated = $request->validate([
            'bib_content' => 'required|string|max:100000',
            'original_filename' => 'required|string|max:255',
        ]);

        $content = $validated['bib_content'];
        $filename = $this->normalizeFilename($validated['original_filename']);
        $path = 'bib_uploads/'.Str::uuid().'_'.$filename;

        Storage::put($path, $content);

        $bibFile = BibFile::create([
            'filename' => $filename,
            'path' => $path,
            'status' => 'completed',
        ]);

        $analysisResults = $this->parserService->localCheck($content);

        $analysisResults['raw_content'] = $content;
        $analysisResults['original_filename'] = $bibFile->filename;
        $analysisResults['course_comparison_result'] = $this->generateVerdict($analysisResults['aggregated_metrics']);

        $this->saveToHistory($bibFile->filename, $content, $analysisResults);

        return redirect()->route('bib.blade')->with('analysis', $analysisResults);
    }

    public function uploadBlade(Request $request)
    {
        return $this->upload($request);
    }

    public function update(Request $request)
    {
        $request->validate(['bib_content' => 'required|string']);

        $content = $request->input('bib_content');

        $analysisResults = $this->parserService->localCheck($content);

        $analysisResults['raw_content'] = $content;
        $analysisResults['original_filename'] = $this->normalizeFilename($request->input('original_filename', 'edited_file.bib'));
        $analysisResults['course_comparison_result'] = $this->generateVerdict($analysisResults['aggregated_metrics']);

        $this->saveToHistory($analysisResults['original_filename'], $content, $analysisResults);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'errors' => $analysisResults['errors'],
            ]);
        }

        return redirect()->route('bib.blade')->with('analysis', $analysisResults);
    }

    public function checkExternal(Request $request)
    {
        $validated = $request->validate([
            'bib_content' => 'required|string|max:100000',
            'original_filename' => 'required|string|max:255',
        ]);

        $content = $validated['bib_content'];
        $run = app(ExternalCheckService::class)->start(
            $request->user(), $content, $this->normalizeFilename($validated['original_filename'])
        );
        $analysisResults = app(ExternalCheckService::class)->payload($run)['analysis'];

        return redirect()->route('bib.blade')->with('analysis', $analysisResults);
    }

    private function normalizeFilename(string $filename): string
    {
        $filename = trim($filename) ?: 'created_file.bib';
        $filename = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $filename));
        $filename = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', $filename) ?: 'created_file.bib';

        return Str::endsWith(Str::lower($filename), '.bib') ? $filename : "{$filename}.bib";
    }

    private function generateVerdict(array $metrics): string
    {
        $total = $metrics['totalQuantity'] ?? 0;

        if ($total === 0) {
            return 'Не удалось проанализировать ни одной записи.';
        }

        $foreign = $metrics['amountOfLiteratureInForeignLanguages'] ?? 0;
        $periodicals = $metrics['numberOfCurrentScientificPeriodicals'] ?? 0;
        $modern = $metrics['Literature21Century'] ?? 0;

        $issues = [];

        if ($total < 10) {
            $issues[] = "мало источников ({$total}, рекомендуется от 10)";
        }
        if ($foreign === 0) {
            $issues[] = 'нет источников на иностранных языках';
        }
        if ($periodicals === 0) {
            $issues[] = 'нет научных периодических изданий';
        }
        if ($modern === 0) {
            $issues[] = 'нет источников XXI века';
        }
        if (empty($issues)) {
            return "Полностью соответствует требованиям. Источников: {$total}, иностранных: {$foreign}, периодика: {$periodicals}, современные: {$modern}.";
        }

        return 'Не соответствует требованиям кафедры: '.implode(', ', $issues).'.';
    }

    private function saveToHistory(string $filename, string $content, array $analysisResults): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        $metrics = $analysisResults['aggregated_metrics'] ?? [];
        $errors = $analysisResults['errors'] ?? [];

        $errorCount = 0;
        $warningCount = 0;

        foreach ($errors as $error) {
            if (in_array($error['severity'] ?? 'error', ['error', 'syntax'], true)) {
                $errorCount++;
            } else {
                $warningCount++;
            }
        }

        $verdict = $this->generateVerdict($metrics);

        CheckHistory::create([
            'user_id' => $user->id,
            'filename' => $filename,
            'content' => $content,
            'status' => 'completed',
            'stats' => $metrics,
            'analysis_data' => $analysisResults,
            'verdict' => $verdict,
            'total_entries' => $metrics['totalQuantity'] ?? 0,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
        ]);
    }
}
