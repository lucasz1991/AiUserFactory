<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiConnectionService;
use Illuminate\Http\Request;

class AiConnectionController extends Controller
{
    public function __construct(
        protected AiConnectionService $ai
    ) {}

    public function send(Request $request)
    {
        $validated = $request->validate([
            'profile' => ['nullable', 'string'],
            'payload' => ['required', 'array'],
        ]);

        return response()->json(
            $this->ai->request(
                $validated['payload'],
                $validated['profile'] ?? null
            )
        );
    }

    public function text(Request $request)
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string'],
            'system' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
        ]);

        return response()->json([
            'content' => $this->ai->text(
                $validated['prompt'],
                $validated['system'] ?? null,
                $validated['options'] ?? []
            ),
        ]);
    }

    public function json(Request $request)
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string'],
            'system' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
        ]);

        return response()->json(
            $this->ai->json(
                $validated['prompt'],
                $validated['system'] ?? null,
                $validated['options'] ?? []
            )
        );
    }

    public function imageGeneration(Request $request)
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string'],
            'options' => ['nullable', 'array'],
        ]);

        $response = $this->ai->imageGeneration(
            $validated['prompt'],
            $validated['options'] ?? []
        );

        return response()->json([
            'response' => $response,
            'images' => $this->ai->generatedImageUrls($response),
        ]);
    }

    public function stream(Request $request)
    {
        $validated = $request->validate([
            'profile' => ['nullable', 'string'],
            'payload' => ['required', 'array'],
        ]);

        return $this->ai->stream(
            $validated['payload'],
            $validated['profile'] ?? 'text'
        );
    }
}
