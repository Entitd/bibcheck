<?php

namespace App\Http\Controllers\Api;

use App\DTO\BibEntryDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VerifySourceRequest;
use App\Services\Bibtex\Parser;
use App\Services\BibtexService;
use Illuminate\Http\JsonResponse;

class ApiSourceController extends Controller
{
    public function __construct(
        private BibtexService $bibtexService,
        private Parser $parser
    ) {}

    public function verify(VerifySourceRequest $request): JsonResponse
    {
        $entry = null;
        $parseErrors = [];
        $fields = $request->input('fields');

        if ($fields === null) {
            $parsed = $this->parser->parse($request->bibContent() ?? '');
            $parseErrors = $parsed['error'] ?? [];
            $entry = $parsed['entries'][0] ?? null;

            if (! $entry instanceof BibEntryDTO) {
                return response()->json([
                    'message' => 'No BibTeX entry found.',
                    'errors' => $parseErrors,
                ], 422);
            }

            if (count($parsed['entries']) !== 1) {
                return response()->json(['message' => 'Передайте ровно одну запись BibLaTeX.'], 422);
            }
            $fields = $entry->fields;
        }

        return response()->json([
            'data' => [
                'entry' => $entry instanceof BibEntryDTO ? [
                    'type' => $entry->type,
                    'key' => $entry->key,
                    'line' => $entry->startLine,
                    'fields' => $entry->fields,
                ] : null,
                'errors' => $parseErrors,
                'api_check' => $this->bibtexService->verifySource($fields, $entry?->type ?? $request->input('type', 'article')),
            ],
        ]);
    }
}
