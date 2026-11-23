<?php

namespace App\Http\Controllers\Workflows;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkflowAssistanceRequest;
use App\Services\Workflows\WorkflowAssistanceLiveBrowser;
use App\Services\Workflows\WorkflowAssistanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Live-Bild und direkte Eingaben fuer eine uebernommene Admin-Aufgabe.
 * Nur der zugewiesene, aktive Administrator einer offenen, nicht abgelaufenen
 * Aufgabe darf das Bild sehen oder Eingaben senden.
 */
class WorkflowAssistanceLiveController extends Controller
{
    public function frame(
        Request $request,
        WorkflowAssistanceRequest $assistance,
        WorkflowAssistanceLiveBrowser $live,
    ): Response {
        $this->authorizeOperator($request, $assistance);

        try {
            $frame = $live->frame(
                $assistance,
                max(0, (int) $request->query('after', 0)),
                max(0, min(2000, (int) $request->query('wait', 0))),
            );
        } catch (\Throwable $exception) {
            return response()->json(['message' => $this->safeMessage($exception)], 409, $this->noStore());
        }

        return response($frame['bytes'], 200, [
            ...$this->noStore(),
            'Content-Type' => 'image/jpeg',
            'X-Frame-Sequence' => (string) $frame['sequence'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function input(
        Request $request,
        WorkflowAssistanceRequest $assistance,
        WorkflowAssistanceLiveBrowser $live,
    ): JsonResponse {
        $this->authorizeOperator($request, $assistance);
        abort_if(strlen($request->getContent()) > 4096, 413);

        $validated = $request->validate([
            'type' => ['required', Rule::in(WorkflowAssistanceLiveBrowser::INPUT_TYPES)],
            'x' => ['required_if:type,down,move,click,wheel', 'numeric', 'min:0', 'max:1'],
            'y' => ['required_if:type,down,move,click,wheel', 'numeric', 'min:0', 'max:1'],
            'deltaX' => ['sometimes', 'numeric', 'min:-2000', 'max:2000'],
            'deltaY' => ['required_if:type,wheel', 'numeric', 'min:-2000', 'max:2000'],
            'key' => ['required_if:type,key', Rule::in(WorkflowAssistanceLiveBrowser::KEYS)],
            'text' => ['required_if:type,text', 'string', 'max:500'],
        ]);

        // Eingaben nur am sicher pausierten Lauf, nicht waehrend einer Pruefung.
        if ($assistance->workflowRun()->value('status') !== 'paused') {
            return response()->json(['message' => 'Der Workflow ist gerade beschäftigt. Bitte kurz warten.'], 409, $this->noStore());
        }

        $command = array_filter(
            $validated,
            fn (mixed $value): bool => $value !== null,
        );

        try {
            $result = $live->input($assistance, $command);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $this->safeMessage($exception)], 409, $this->noStore());
        }

        if (in_array($validated['type'], ['up', 'click', 'key', 'text'], true)) {
            app(WorkflowAssistanceService::class)->recordLiveInteraction($assistance, $request->user(), $validated['type']);
        }

        return response()->json($result, 200, $this->noStore());
    }

    protected function authorizeOperator(Request $request, WorkflowAssistanceRequest $assistance): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin() && $user->isActive(), 403);
        abort_unless((int) $assistance->assigned_to_user_id === (int) $user->getKey(), 403);
        abort_unless(in_array($assistance->status, WorkflowAssistanceRequest::OPEN_STATUSES, true), 409);
        abort_if($assistance->isExpired(), 409);
    }

    protected function safeMessage(\Throwable $exception): string
    {
        // Eigene RuntimeExceptions tragen bereits sichere, deutschsprachige Texte.
        return $exception instanceof \RuntimeException
            ? $exception->getMessage()
            : 'Die Live-Ansicht ist derzeit nicht verfügbar.';
    }

    protected function noStore(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache'];
    }
}
