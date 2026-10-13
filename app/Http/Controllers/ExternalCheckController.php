<?php

namespace App\Http\Controllers;

use App\Models\ExternalCheckRun;
use App\Services\Bibliography\ExternalCheckService;
use Illuminate\Http\Request;

class ExternalCheckController extends Controller
{
    public function __construct(private ExternalCheckService $checks) {}

    private function assertSession(Request $request): void
    {
        abort_if($request->user()->isGuest() && $request->user()->isGuestSessionExpired(), 401, 'Гостевая сессия истекла.');
    }

    public function store(Request $request)
    {
        $this->assertSession($request);
        $data = $request->validate(['bib_content' => 'required|string|max:100000', 'original_filename' => 'required|string|max:255']);
        $run = $this->checks->start($request->user(), $data['bib_content'], $data['original_filename']);

        return response()->json(['data' => $this->checks->payload($run)], 202);
    }

    public function show(Request $request, ExternalCheckRun $run)
    {
        $this->assertSession($request);
        abort_unless($run->user_id === $request->user()->id, 404);

        return response()->json(['data' => $this->checks->payload($run)]);
    }

    public function cancel(Request $request, ExternalCheckRun $run)
    {
        $this->assertSession($request);
        abort_unless($run->user_id === $request->user()->id, 404);
        $this->checks->cancel($run);

        return response()->json(['data' => $this->checks->payload($run->fresh())]);
    }
}
