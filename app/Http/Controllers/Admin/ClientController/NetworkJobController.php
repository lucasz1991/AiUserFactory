<?php

namespace App\Http\Controllers\Admin\ClientController;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\NetworkTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NetworkJobController extends Controller
{
    public function index(): View
    {
        return view('admin.client-controller.jobs.index', [
            'jobs' => NetworkJob::query()
                ->with(['networkNode', 'device', 'networkTarget'])
                ->latest('id')
                ->paginate(30),
            'nodes' => NetworkNode::query()->orderBy('name')->get(['id', 'name']),
            'devices' => Device::query()->orderBy('name')->get(['id', 'name']),
            'targets' => NetworkTarget::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'network_node_id' => ['required', 'exists:network_nodes,id'],
            'device_id' => ['nullable', 'exists:devices,id'],
            'network_target_id' => ['nullable', 'exists:network_targets,id'],
            'type' => ['required', 'string', 'max:120'],
            'payload_json' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $payload = [];
        if (! empty($validated['payload_json'])) {
            $decoded = json_decode((string) $validated['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        NetworkJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'network_node_id' => (int) $validated['network_node_id'],
            'device_id' => $validated['device_id'] ?? null,
            'network_target_id' => $validated['network_target_id'] ?? null,
            'type' => $validated['type'],
            'payload_json' => $payload,
            'signature' => hash('sha256', json_encode($payload).config('app.key')),
            'status' => 'pending',
            'queued_at' => now(),
            'expires_at' => $validated['expires_at'] ?? null,
            'requested_by' => optional(auth()->user())->email,
        ]);

        return back()->with('success', 'Job wurde in die Queue eingestellt.');
    }

    public function cancel(NetworkJob $job): RedirectResponse
    {
        if (in_array($job->status, ['success', 'failed', 'cancelled'], true)) {
            return back()->with('success', 'Job-Status blieb unverändert.');
        }

        $job->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);

        return back()->with('success', 'Job wurde abgebrochen.');
    }
}
