<?php

namespace App\Http\Controllers\Admin\ClientController;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\NetworkTarget;
use App\Models\Person;
use App\Models\Workflow;
use App\Services\ClientController\NetworkJobDispatcher;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'devices' => Device::query()->orderBy('name')->get(['id', 'network_node_id', 'name', 'status']),
            'targets' => NetworkTarget::query()->orderBy('name')->get(['id', 'name']),
            'workflows' => Workflow::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'persons' => Person::query()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, NetworkJobDispatcher $jobs, WorkflowExecutionService $workflows): RedirectResponse
    {
        $validated = $request->validate([
            'job_mode' => ['required', 'string', 'in:workflow,raw'],
            'network_node_id' => ['required', 'exists:network_nodes,id'],
            'device_id' => ['nullable', 'exists:devices,id'],
            'network_target_id' => ['nullable', 'exists:network_targets,id'],
            'workflow_id' => ['nullable', 'required_if:job_mode,workflow', 'exists:workflows,id'],
            'person_id' => ['nullable', 'exists:persons,id'],
            'type' => ['nullable', 'required_if:job_mode,raw', 'string', 'max:120'],
            'payload_json' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $node = NetworkNode::query()->findOrFail((int) $validated['network_node_id']);
        $device = ! empty($validated['device_id']) ? Device::query()->findOrFail((int) $validated['device_id']) : null;

        if ($device && (int) $device->network_node_id !== (int) $node->id) {
            return back()->withErrors(['device_id' => 'Das Geraet gehoert nicht zum ausgewaehlten Node.'])->withInput();
        }

        if ($validated['job_mode'] === 'workflow') {
            $workflow = Workflow::query()->findOrFail((int) $validated['workflow_id']);
            $run = $workflows->start($workflow, [
                'person_id' => ! empty($validated['person_id']) ? (int) $validated['person_id'] : null,
                'started_from' => 'client-controller-jobs',
                'execution_target' => 'client_controller',
                'network_node_id' => $node->id,
                'device_id' => $device?->id,
            ], optional(auth()->user())->email ?: 'admin-ui');

            return back()->with('success', 'Workflow wurde fuer den ClientController eingeplant: '.$run->run_uuid);
        }

        $payload = [];
        if (! empty($validated['payload_json'])) {
            $decoded = json_decode((string) $validated['payload_json'], true);
            if (! is_array($decoded)) {
                return back()->withErrors(['payload_json' => 'Payload muss ein gueltiges JSON-Objekt oder -Array sein.'])->withInput();
            }

            $payload = $decoded;
        }

        $target = ! empty($validated['network_target_id'])
            ? NetworkTarget::query()->findOrFail((int) $validated['network_target_id'])
            : null;
        $jobs->dispatch(
            $node,
            (string) $validated['type'],
            $payload,
            $device,
            $target,
            optional(auth()->user())->email,
            $validated['expires_at'] ?? null,
        );

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
