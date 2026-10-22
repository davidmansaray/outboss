<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\Services\UnsubscribeRecipient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class UnsubscribeController extends Controller
{
    public function show(): View
    {
        return view('unsubscribe');
    }

    public function store(Request $request, string $token, UnsubscribeRecipient $unsubscribe): View
    {
        $unsubscribe->handle($token);

        return view('unsubscribe', [
            'confirmed' => true,
        ]);
    }
}
