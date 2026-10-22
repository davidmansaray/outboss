<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Workspaces\Services\DeleteWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteWorkspace $deleteWorkspace): RedirectResponse
    {
        $user = $request->user();

        // Account removal must not remove a workspace that has other members.
        // The service repeats this ownership check inside its transaction so
        // this lookup is only a bounded set of candidates, not authorization.
        $personalWorkspaces = $user->workspaces()
            ->where('workspaces.type', 'personal')
            ->wherePivot('role', WorkspaceMembership::ROLE_OWNER)
            ->get();

        foreach ($personalWorkspaces as $workspace) {
            $deleteWorkspace->handle($workspace, $user);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
