<?php

namespace App\Http\Controllers\Admin\ClientController;

use App\Http\Controllers\Controller;
use App\Models\NetworkNode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NodeController extends Controller
{
    public function index(Request $request): View
    {
        $query = NetworkNode::query()->latest('id');

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('node_uuid', 'like', '%'.$search.'%')
                    ->orWhere('current_server_domain', 'like', '%'.$search.'%');
            });
        }

        return view('admin.client-controller.nodes.index', [
            'nodes' => $query->paginate(20)->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'current_server_domain' => ['nullable', 'url', 'max:2048'],
            'allow_server_rebind' => ['nullable', 'boolean'],
        ]);

        NetworkNode::query()->create([
            'name' => $validated['name'],
            'node_uuid' => (string) Str::uuid(),
            'api_key' => Str::random(60),
            'node_secret' => Str::random(60),
            'current_server_domain' => $validated['current_server_domain'] ?? null,
            'last_successful_server_domain' => $validated['current_server_domain'] ?? null,
            'allow_server_rebind' => (bool) ($validated['allow_server_rebind'] ?? true),
            'is_online' => false,
        ]);

        return back()->with('success', 'Node wurde angelegt.');
    }

    public function update(Request $request, NetworkNode $node): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'current_server_domain' => ['nullable', 'url', 'max:2048'],
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

    public function regenerateApiKey(NetworkNode $node): RedirectResponse
    {
        $node->update([
            'api_key' => Str::random(60),
            'node_secret' => Str::random(60),
        ]);

        return back()->with('success', 'Node API-Key wurde neu erzeugt.');
    }

    public function destroy(NetworkNode $node): RedirectResponse
    {
        $node->delete();

        return back()->with('success', 'Node wurde gelöscht.');
    }
}
