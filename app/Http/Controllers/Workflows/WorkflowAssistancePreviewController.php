<?php

namespace App\Http\Controllers\Workflows;

use App\Http\Controllers\Controller;
use App\Models\WorkflowAssistanceRequest;
use App\Services\Workflows\WorkflowAssistanceService;
use Symfony\Component\HttpFoundation\Response;

class WorkflowAssistancePreviewController extends Controller
{
    public function __invoke(
        WorkflowAssistanceRequest $assistance,
        WorkflowAssistanceService $assistanceService,
    ): Response {
        $path = $assistanceService->previewAbsolutePath($assistance);

        abort_unless($path !== null, 404);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
