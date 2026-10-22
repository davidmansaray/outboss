<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Devices\Enums\PushPreference;
use App\Domain\Devices\Support\CurrentDeviceSession;
use App\Domain\Notifications\PushConfiguration;
use App\Http\Controllers\Controller;
use App\Models\DeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DevicePushController extends Controller
{
    public function update(Request $request, PushConfiguration $push): JsonResponse
    {
        $session = CurrentDeviceSession::require($request);
        $data = $request->validate([
            'apns_token' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64,200}$/'],
            'environment' => ['required', 'in:production,sandbox'],
            'notify' => ['required', Rule::enum(PushPreference::class)],
        ]);
        $apnsToken = strtolower($data['apns_token']);

        DB::transaction(function () use ($session, $data, $apnsToken): void {
            // A token identifies one app install. If it moved to another
            // session (a different person signed in on this phone), the old
            // session stops receiving this phone's notifications.
            DeviceSession::query()
                ->where('apns_token', $apnsToken)
                ->whereKeyNot($session->id)
                ->update(['apns_token' => null, 'apns_environment' => null]);

            $session->forceFill([
                'apns_token' => $apnsToken,
                'apns_environment' => $data['environment'],
                'notify' => PushPreference::from($data['notify']),
            ])->save();
        });

        return response()->json([
            'data' => [
                'push_available' => $push->available(),
                'notify' => $session->notify->value,
            ],
        ]);
    }

    public function destroy(Request $request): Response
    {
        CurrentDeviceSession::require($request)
            ->forceFill(['apns_token' => null, 'apns_environment' => null])
            ->save();

        return response()->noContent();
    }
}
