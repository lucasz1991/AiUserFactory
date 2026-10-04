<?php

namespace App\Http\Controllers\Workflows;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkflowRecording;
use App\Services\Workflows\WorkflowRecordingBrowser;
use App\Services\Workflows\WorkflowRecordingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WorkflowRecordingController extends Controller
{
    public function state(Request $request, WorkflowRecording $recording, WorkflowRecordingService $service): JsonResponse
    {
        $user = $this->user($request);
        $service->owned($user, $recording->id);
        try {
            $recording = $service->poll($user, $recording->id);

            return $this->json($service->publicState($recording));
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            return $this->json(['message' => 'Die Aufnahme-Verbindung ist unterbrochen. Aufnahme beenden und erneut starten.'], 409);
        }
    }

    public function command(Request $request, WorkflowRecording $recording, WorkflowRecordingService $service): JsonResponse
    {
        $user = $this->user($request);
        $service->owned($user, $recording->id);
        abort_if((int) $request->header('Content-Length', 0) > 32768 || strlen($request->getContent()) > 32768, 413);
        $command = $request->validate([
            'type' => ['required', Rule::in(['navigate', 'click', 'move', 'scroll', 'key', 'fill', 'inspect'])],
            'url' => ['required_if:type,navigate', 'string', 'max:2048'],
            'x' => ['required_if:type,click,move,inspect', 'numeric', 'min:0', 'max:4096'],
            'y' => ['required_if:type,click,move,inspect', 'numeric', 'min:0', 'max:4096'],
            'button' => ['sometimes', Rule::in(['left'])],
            'deltaY' => ['required_if:type,scroll', 'numeric', 'min:-2000', 'max:2000'],
            'deltaX' => ['sometimes', 'numeric', 'min:-2000', 'max:2000'],
            'key' => ['required_if:type,key', Rule::in(['Enter', 'Tab'])],
            'selector' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'source' => ['required_if:type,fill', Rule::in(['fixed', 'workflow_variable', 'literal'])],
            'value' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'workflow_variable' => ['sometimes', 'nullable', 'string', 'max:120'],
            'previewValue' => ['sometimes', 'nullable', 'string', 'max:8192'],
        ]);
        try {
            return $this->json($service->publicState($service->action($user, $recording->id, $command)));
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            return $this->json(['message' => 'Browseraktion nicht möglich. Aufnahmezustand und eindeutiges Ziel prüfen.'], 409);
        }
    }

    public function frame(Request $request, WorkflowRecording $recording, WorkflowRecordingService $service, WorkflowRecordingBrowser $browser): Response
    {
        $recording = $service->owned($this->user($request), $recording->id);
        abort_unless(in_array($recording->status, ['recording', 'paused'], true), 409);
        try {
            return response($browser->frame($recording), 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        } catch (\Throwable) {
            abort(409, 'Die Browser-Vorschau ist nicht verfügbar.');
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin() && $user->isActive(), 403);

        return $user;
    }

    private function json(array $state, int $status = 200): JsonResponse
    {
        return response()->json($state, $status, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
    }
}
