<?php

namespace App\Http\Controllers;

use App\Support\Pwa\PwaIcon;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PwaIconController extends Controller
{
    public function __invoke(string $icon): Response|BinaryFileResponse
    {
        abort_unless(PwaIcon::supports($icon), 404);

        $headers = [
            'Cache-Control' => 'public, max-age=604800, immutable',
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $publicIcon = public_path('icons/'.$icon);

        if (is_file($publicIcon) && is_readable($publicIcon)) {
            return response()->file($publicIcon, $headers);
        }

        return response(PwaIcon::fallback($icon), 200, $headers);
    }
}
