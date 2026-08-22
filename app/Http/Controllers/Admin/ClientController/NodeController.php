<?php

namespace App\Http\Controllers\Admin\ClientController;

use App\Http\Controllers\Controller;
use App\Models\NetworkNode;
use App\Services\ClientController\NetworkNodeCredentialService;
use App\Services\ClientController\NodeEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NodeController extends Controller
{
    public function index(Request $request): View
    {
        NetworkNode::expireStale();
        $query = NetworkNode::query()->latest('id');

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('node_uuid', 'like', '%'.$search.'%')
                    ->orWhere('current_server_domain', 'like', '%'.$search.'%')
                    ->orWhere('public_ip', 'like', '%'.$search.'%')
                    ->orWhere('os', 'like', '%'.$search.'%');
            });
        }

        return view('admin.client-controller.nodes.index', [
            'nodes' => $query->paginate(20)->withQueryString(),
        ]);
    }

    public function store(
        Request $request,
        NetworkNodeCredentialService $credentials,
        NodeEnrollmentService $enrollments,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'current_server_domain' => ['nullable', 'url', 'max:191'],
            'allow_server_rebind' => ['nullable', 'boolean'],
        ]);

        $node = new NetworkNode;
        $node->forceFill([
            'name' => $validated['name'],
            'node_uuid' => (string) Str::uuid(),
            'current_server_domain' => $validated['current_server_domain'] ?? null,
            'last_successful_server_domain' => $validated['current_server_domain'] ?? null,
            'allow_server_rebind' => (bool) ($validated['allow_server_rebind'] ?? true),
            'is_online' => false,
        ]);
        $credentials->initializeRevoked($node);
        $token = $enrollments->issue($node, $request->user(), 'admin-node-created');

        return back()
            ->with('success', 'Node wurde angelegt. Das Enrollment-Token wird nur jetzt angezeigt.')
            ->with('node_enrollment_token', $token);
    }

    public function update(Request $request, NetworkNode $node): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'current_server_domain' => ['nullable', 'url', 'max:191'],
            'allow_server_rebind' => ['nullable', 'boolean'],
            'status' => ['required', 'string', 'in:active,paused,disabled'],
        ]);

        $node->update([
            'name' => $validated['name'],
            'current_server_domain' => $validated['current_server_domain'] ?? null,
            'allow_server_rebind' => (bool) ($validated['allow_server_rebind'] ?? false),
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Node wurde aktualisiert.');
    }

    public function regenerateApiKey(
        Request $request,
        NetworkNode $node,
        NetworkNodeCredentialService $credentials,
        NodeEnrollmentService $enrollments,
    ): RedirectResponse {
        $credentials->revoke($node, $request->user(), 'admin-reenrollment');
        $token = $enrollments->issue($node, $request->user(), 'admin-reenrollment');

        return back()
            ->with('success', 'Der bisherige Node-Key wurde widerrufen. Das Enrollment-Token wird nur jetzt angezeigt.')
            ->with('node_enrollment_token', $token);
    }

    public function destroy(NetworkNode $node): RedirectResponse
    {
        $node->delete();

        return back()->with('success', 'Node wurde gelöscht.');
    }
}
