<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiImageGenerationRequest;
use App\Http\Requests\Ai\AiJsonRequest;
use App\Http\Requests\Ai\AiTextRequest;
use App\Models\User;
use App\Services\Ai\AiApiBudgetExceededException;
use App\Services\Ai\AiApiExecutionResult;
use App\Services\Ai\AiApiRequestFailedException;
use App\Services\Ai\AiApiRequestService;
use Closure;
use Illuminate\Http\JsonResponse;

class AiConnectionController extends Controller
{
    public function __construct(
        private readonly AiApiRequestService $requests,
    ) {}

    public function text(AiTextRequest $request): JsonResponse
    {
        $validated = $request->validated();
        /** @var User $user */
        $user = $request->user();

        return $this->respond(fn (): AiApiExecutionResult => $this->requests->text(
            $user,
            $validated['prompt'],
            $validated['system'] ?? null,
            $request->aiOptions(),
        ));
    }

    public function json(AiJsonRequest $request): JsonResponse
    {
        $validated = $request->validated();
        /** @var User $user */
        $user = $request->user();

        return $this->respond(fn (): AiApiExecutionResult => $this->requests->json(
            $user,
            $validated['prompt'],
            $validated['system'] ?? null,
            $request->aiOptions(),
        ));
    }

    public function imageGeneration(AiImageGenerationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        /** @var User $user */
        $user = $request->user();

        return $this->respond(fn (): AiApiExecutionResult => $this->requests->imageGeneration(
            $user,
            $validated['prompt'],
            $request->aiOptions(),
        ));
    }

    private function respond(Closure $operation): JsonResponse
    {
        try {
            /** @var AiApiExecutionResult $result */
            $result = $operation();

            return $this->withRequestId(response()->json($result->data), $result->requestId);
        } catch (AiApiBudgetExceededException $exception) {
            return $this->withRequestId(response()->json([
                'error' => 'daily_ai_budget_exceeded',
                'message' => 'Das tägliche AI-Kostenbudget ist ausgeschöpft.',
            ], 429), $exception->requestId);
        } catch (AiApiRequestFailedException $exception) {
            return $this->withRequestId(response()->json([
                'error' => 'ai_service_unavailable',
                'message' => 'Der AI-Dienst ist derzeit nicht verfügbar.',
            ], $exception->httpStatus), $exception->requestId);
        }
    }

    private function withRequestId(JsonResponse $response, string $requestId): JsonResponse
    {
        $response->headers->set(AiApiRequestService::REQUEST_ID_HEADER, $requestId);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
