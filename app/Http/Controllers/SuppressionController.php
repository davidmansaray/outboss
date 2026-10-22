<?php

namespace App\Http\Controllers;

use App\Models\SuppressionEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuppressionController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace();

        return Inertia::render('suppressions/Index', [
            'entries' => $workspace->suppressions()->latest()->get()->map(fn (SuppressionEntry $entry) => [
                'id' => $entry->id,
                'email' => $entry->email_address,
                'reason' => $entry->reason->value,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function destroy(Request $request, SuppressionEntry $entry): RedirectResponse
    {
        $this->authorize('delete', $entry);
        $entry->delete();

        return back()->with('success', 'Suppression removed. That address can be imported again.');
    }
}
