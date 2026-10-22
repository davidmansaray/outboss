<?php

namespace App\Http\Controllers;

use App\Jobs\Mail\RecordOpenEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TrackingController extends Controller
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function show(Request $request, string $trackingToken): Response
    {
        RecordOpenEvent::dispatch(
            $trackingToken,
            $request->ip(),
            $request->userAgent(),
            now()->utc()->format('Y-m-d\TH:i'),
        );

        return response(base64_decode(self::GIF), 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen(base64_decode(self::GIF)),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
