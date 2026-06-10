<?php

namespace App\Http\Controllers\Admin\ClientController;

use App\Http\Controllers\Controller;
use App\Models\NetworkTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NetworkTargetController extends Controller
{
    public function index(): View
    {
        return view('admin.client-controller.targets.index', [
            'targets' => NetworkTarget::query()->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'allow_browser' => ['nullable', 'boolean'],
            'allow_api' => ['nullable', 'boolean'],
            'allow_screenshots' => ['nullable', 'boolean'],
            'timeout' => ['required', 'integer', 'min:1', 'max:600'],
        ]);

        NetworkTarget::query()->create([
            ...$validated,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'allow_browser' => (bool) ($validated['allow_browser'] ?? false),
            'allow_api' => (bool) ($validated['allow_api'] ?? true),
            'allow_screenshots' => (bool) ($validated['allow_screenshots'] ?? false),
        ]);

        return back()->with('success', 'Network Target wurde angelegt.');
    }

    public function update(Request $request, NetworkTarget $target): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'allow_browser' => ['nullable', 'boolean'],
            'allow_api' => ['nullable', 'boolean'],
            'allow_screenshots' => ['nullable', 'boolean'],
            'timeout' => ['required', 'integer', 'min:1', 'max:600'],
        ]);

        $target->update([
            ...$validated,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'allow_browser' => (bool) ($validated['allow_browser'] ?? false),
            'allow_api' => (bool) ($validated['allow_api'] ?? true),
            'allow_screenshots' => (bool) ($validated['allow_screenshots'] ?? false),
        ]);

        return back()->with('success', 'Network Target wurde aktualisiert.');
    }

    public function destroy(NetworkTarget $target): RedirectResponse
    {
        $target->delete();

        return back()->with('success', 'Network Target wurde gelöscht.');
    }
}
