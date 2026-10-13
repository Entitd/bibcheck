<?php

namespace App\Http\Controllers;

use App\Models\CheckHistory;
use App\Models\ExternalCheckRun;
use App\Services\Bibliography\ExternalCheckService;
use App\Support\BibEditorViewData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CheckHistoryController extends Controller
{
    /**
     * Получить последние проверки текущего пользователя
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([]);
        }

        $limit = $request->get('limit', 10);

        $history = CheckHistory::forUser($user->id)
            ->recent($limit)
            ->get();

        return response()->json($history);
    }

    /**
     * Показать результаты сохранённой проверки
     */
    public function show($id)
    {
        $user = Auth::user();

        if (! $user) {
            abort(403, 'Необходима авторизация');
        }

        $check = CheckHistory::where('user_id', $user->id)
            ->findOrFail($id);

        // Используем сохранённые результаты проверки, НЕ запускаем заново
        $analysisResults = $check->analysis_data ?? [];

        // Добавляем дополнительные поля для отображения
        $run = ExternalCheckRun::where('check_history_id', $check->id)->where('user_id', $user->id)->first();
        if ($run) {
            $analysisResults = app(ExternalCheckService::class)->payload($run)['analysis'];
        }
        $analysisResults['raw_content'] = $check->content;
        $analysisResults['original_filename'] = $check->filename;
        $analysisResults['course_comparison_result'] = $check->verdict;
        $analysisResults['check_id'] = $check->id;
        $analysisResults['check_date'] = $check->created_at->format('d.m.Y H:i');

        return Inertia::render('bib/editor', BibEditorViewData::make($analysisResults, $user, $check));
    }

    /**
     * Удалить запись из истории
     */
    public function destroy($id)
    {
        $user = Auth::user();

        $history = CheckHistory::where('user_id', $user->id)
            ->findOrFail($id);

        foreach (ExternalCheckRun::where('check_history_id', $history->id)->get() as $run) {
            app(ExternalCheckService::class)->cancel($run);
            $run->delete();
        }
        $history->delete();

        return redirect()->route('bib.blade');
    }
}
