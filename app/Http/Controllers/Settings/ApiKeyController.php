<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Api\TokenScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiKeyRequest;
use App\Models\PersonalAccessToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        $keys = $request->user()
            ->tokens()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereNull('device_session_id')
            ->latest()
            ->get()
            ->map(function (PersonalAccessToken $token): array {
                $scopes = is_array($token->abilities) ? $token->abilities : [];

                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'scopes' => $scopes,
                    'display_prefix' => $token->display_prefix,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('api-keys/Index', [
            'keys' => $keys,
            'scopeGroups' => TokenScope::groups(),
            'readOnlyScopes' => TokenScope::readOnly(),
            'plainTextToken' => $request->session()->get('plainTextToken'),
        ]);
    }

    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        /** @var array{name: string, scopes: list<string>, expires_at?: string|null} $validated */
        $validated = $request->validated();

        $expiresAt = isset($validated['expires_at'])
            ? Date::parse($validated['expires_at'])
            : null;

        $newToken = $request->user()->createToken(
            $validated['name'],
            $validated['scopes'],
            $expiresAt,
        );

        $newToken->accessToken->forceFill([
            'display_prefix' => PersonalAccessToken::displayPrefixFrom($newToken->plainTextToken),
        ])->save();

        Inertia::flash('plainTextToken', $newToken->plainTextToken);

        return to_route('api-keys.index')
            ->with('plainTextToken', $newToken->plainTextToken)
            ->with('success', 'API key created. Copy it now — it will not be shown again.');
    }

    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        $token = $request->user()
            ->tokens()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereNull('device_session_id')
            ->whereKey($tokenId)
            ->first();

        if ($token === null) {
            abort(403);
        }

        $token->delete();

        return to_route('api-keys.index')->with('success', 'API key revoked.');
    }
}
