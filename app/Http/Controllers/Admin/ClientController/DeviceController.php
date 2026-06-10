<?php

namespace App\Http\Controllers\Admin\ClientController;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\NetworkNode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(): View
    {
        return view('admin.client-controller.devices.index', [
            'devices' => Device::query()->with('networkNode')->latest('id')->paginate(20),
            'nodes' => NetworkNode::query()->orderBy('name')->get(['id', 'name', 'node_uuid']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'network_node_id' => ['nullable', 'exists:network_nodes,id'],
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:50'],
            'device_uuid' => ['required', 'string', 'max:191', 'unique:devices,device_uuid'],
            'adb_serial' => ['nullable', 'string', 'max:191'],
            'appium_endpoint' => ['nullable', 'url', 'max:2048'],
            'status' => ['required', 'string', 'in:offline,online,busy,error'],
        ]);

        Device::query()->create($validated);

        return back()->with('success', 'Gerät wurde angelegt.');
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate([
            'network_node_id' => ['nullable', 'exists:network_nodes,id'],
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:50'],
            'device_uuid' => ['required', 'string', 'max:191', 'unique:devices,device_uuid,'.$device->id],
            'adb_serial' => ['nullable', 'string', 'max:191'],
            'appium_endpoint' => ['nullable', 'url', 'max:2048'],
            'status' => ['required', 'string', 'in:offline,online,busy,error'],
        ]);

        $device->update($validated);

        return back()->with('success', 'Gerät wurde aktualisiert.');
    }

    public function destroy(Device $device): RedirectResponse
    {
        $device->delete();

        return back()->with('success', 'Gerät wurde gelöscht.');
    }
}
