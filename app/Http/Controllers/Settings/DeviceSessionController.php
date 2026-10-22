<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Devices\Services\RevokeDeviceSession;
use App\Http\Controllers\Controller;
use App\Models\DeviceSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phones signed in to this account through the iOS app. Sessions belong to
 * the person, not a workspace, so the list is the same in every workspace.
 */
class DeviceSessionController extends Controller
{
    public function index(Request $request): Response
    {
        $devices = DeviceSession::query()
            ->where('user_id', $request->user()->id)
            ->active()
            ->with('workspace:id,name')
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (DeviceSession $device): array => [
                'id' => $device->id,
                'name' => $device->name,
                'platform' => $device->platform,
                'app_version' => $device->app_version,
                'workspace' => $device->workspace?->name,
                'notifications' => $device->apns_token !== null && $device->notify->value !== 'off',
                'created_at' => $device->created_at?->toIso8601String(),
                'last_used_at' => $device->last_used_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('devices/Index', [
            'devices' => $devices,
        ]);
    }

    public function destroy(Request $request, string $deviceId, RevokeDeviceSession $revoke): RedirectResponse
    {
        $device = DeviceSession::query()
            ->where('user_id', $request->user()->id)
            ->active()
            ->whereKey($deviceId)
            ->first();

        abort_if($device === null, 404);

        $revoke->handle($device);

        return to_route('devices.index')->with('success', 'Signed out '.$device->name.'.');
    }
}
