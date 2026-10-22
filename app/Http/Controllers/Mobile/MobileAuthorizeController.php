<?php

namespace App\Http\Controllers\Mobile;

use App\Domain\Devices\Services\IssueMobileAuthorizationCode;
use App\Domain\Devices\Support\MobileAuthorizationRequest;
use App\Domain\Devices\Support\MobileSignInLogin;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The workspace step of the iOS sign-in. The app opens this page in a secure
 * browser sheet; Fortify handles sign-in (password, two-factor, passkey)
 * before it, and the person picks the workspace the app opens in.
 *
 * The page is skipped only when the person has one workspace and signed in
 * for this attempt moments ago (see MobileSignInLogin). An existing browser
 * session always gets the page, so another app on the phone cannot collect a
 * code from it without a tap.
 */
class MobileAuthorizeController extends Controller
{
    public function show(Request $request, MobileSignInLogin $logins, IssueMobileAuthorizationCode $issue): HttpResponse
    {
        $parameters = MobileAuthorizationRequest::parameters($request->query());
        /** @var User $user */
        $user = $request->user();

        if ($parameters === null) {
            return Inertia::render('auth/MobileAuthorize', ['invalid' => true])
                ->toResponse($request)
                ->setStatusCode(400);
        }

        $workspaces = $user->workspaces()
            ->withPivot('role')
            ->orderBy('workspaces.name')
            ->get();

        if ($logins->pullFreshLogin($request->session(), $user, $parameters) && $workspaces->count() === 1) {
            return $this->allow($user, $workspaces->sole(), $parameters, $issue);
        }

        return Inertia::render('auth/MobileAuthorize', [
            'invalid' => false,
            'request' => $parameters,
            'email' => $user->email,
            'workspaces' => $workspaces
                ->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'role' => $workspace->getRelation('pivot')?->getAttribute('role') ?? WorkspaceMembership::ROLE_OWNER,
                ])
                ->values()
                ->all(),
        ])->toResponse($request);
    }

    public function store(Request $request, IssueMobileAuthorizationCode $issue): HttpResponse
    {
        $parameters = MobileAuthorizationRequest::parameters($request->all());
        abort_if($parameters === null, 400, 'This sign-in request is invalid. Start again from the app.');

        $data = $request->validate([
            'decision' => ['required', 'in:allow,deny'],
            'workspace_id' => ['required_if:decision,allow', 'integer'],
        ]);

        if ($data['decision'] === 'deny') {
            return Inertia::location($this->callback($parameters['redirect_uri'], [
                'error' => 'access_denied',
                'state' => $parameters['state'],
            ]));
        }

        /** @var User $user */
        $user = $request->user();
        $workspace = $user->workspaces()->whereKey((int) $data['workspace_id'])->first();
        abort_if($workspace === null, 403, 'You are not a member of that workspace.');

        return $this->allow($user, $workspace, $parameters, $issue);
    }

    /**
     * Issue the one-time code and send the browser sheet back to the app. An
     * Inertia location response lets the sheet follow the custom scheme.
     *
     * @param  array{client: string, redirect_uri: string, code_challenge: string, code_challenge_method: string, state: string, device_name?: string|null}  $parameters
     */
    private function allow(User $user, Workspace $workspace, array $parameters, IssueMobileAuthorizationCode $issue): HttpResponse
    {
        $code = $issue->handle(
            $user,
            $workspace,
            $parameters['code_challenge'],
            $parameters['redirect_uri'],
            $parameters['device_name'] ?? null,
        );

        return Inertia::location($this->callback($parameters['redirect_uri'], [
            'code' => $code,
            'state' => $parameters['state'],
        ]));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function callback(string $redirectUri, array $query): string
    {
        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
