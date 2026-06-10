<?php

namespace App\Http\Controllers\Admin\ClientController;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\NetworkTarget;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $settings = Setting::getValue('client_controller', 'server') ?? [];

        return view('admin.client-controller.dashboard', [
            'stats' => [
                'nodes_total' => NetworkNode::query()->count(),
                'nodes_online' => NetworkNode::query()->where('is_online', true)->count(),
                'devices_total' => Device::query()->count(),
                'jobs_pending' => NetworkJob::query()->whereIn('status', ['pending', 'dispatched'])->count(),
                'targets_total' => NetworkTarget::query()->count(),
            ],
            'settings' => is_array($settings) ? $settings : [],
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'server_domain' => ['required', 'url', 'max:2048'],
            'fallback_server_domain' => ['nullable', 'url', 'max:2048'],
            'require_signed_jobs' => ['nullable', 'boolean'],
            'allow_server_rebind' => ['nullable', 'boolean'],
            'default_heartbeat_interval_seconds' => ['required', 'integer', 'min:5', 'max:3600'],
            'default_job_timeout_seconds' => ['required', 'integer', 'min:5', 'max:86400'],
        ]);

        Setting::setValue('client_controller', 'server', [
            'server_domain' => $validated['server_domain'],
            'fallback_server_domain' => $validated['fallback_server_domain'] ?? null,
            'require_signed_jobs' => (bool) ($validated['require_signed_jobs'] ?? false),
            'allow_server_rebind' => (bool) ($validated['allow_server_rebind'] ?? true),
            'default_heartbeat_interval_seconds' => (int) $validated['default_heartbeat_interval_seconds'],
            'default_job_timeout_seconds' => (int) $validated['default_job_timeout_seconds'],
        ]);

        return back()->with('success', 'ClientController-Einstellungen wurden gespeichert.');
    }
}
