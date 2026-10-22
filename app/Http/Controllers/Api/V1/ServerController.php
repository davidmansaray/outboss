<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\PushConfiguration;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public discovery: lets the iOS app confirm that an address it was given is
 * an Outboss server, and where to start sign-in, before anyone signs in.
 */
class ServerController extends Controller
{
    public function __invoke(PushConfiguration $push): JsonResponse
    {
        $version = config('outboss.version');

        return response()->json([
            'data' => [
                'product' => 'outboss',
                'version' => is_string($version) && trim($version) !== '' ? trim($version) : 'dev',
                'api' => 'v1',
                'push' => $push->available(),
                'sign_in_url' => route('mobile.authorize'),
            ],
        ]);
    }
}
