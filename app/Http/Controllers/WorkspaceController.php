<?php

namespace App\Http\Controllers;

use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WorkspaceController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'website_url' => ['nullable', 'string', 'max:2048'],
            'client_operation_id' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $request->user();
        try {
            $workspace = app(ProvisionWorkspace::class)->create(
                $user,
                $validated['name'],
                'team',
                $validated['website_url'] ?? null,
                $validated['client_operation_id'] ?? null,
            );
        } catch (RuntimeException $exception) {
            $messages = [
                'operation_replay_mismatch' => 'This creation request was already used with different workspace details.',
                'workspace_operation_in_progress' => 'This workspace creation is still being processed. Retry with the same details.',
                'workspace_operation_result_missing' => 'The earlier workspace creation result is unavailable. Contact support before retrying.',
            ];
            $code = $exception->getMessage();
            if (! array_key_exists($code, $messages)) {
                throw $exception;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'type' => 'conflict',
                        'code' => $code,
                        'message' => $messages[$code],
                    ],
                    'errors' => [
                        'client_operation_id' => $messages[$code],
                    ],
                ], 409);
            }

            return back()->withInput()->withErrors([
                'client_operation_id' => $messages[$code],
            ]);
        }

        $request->session()->put(User::ACTIVE_WORKSPACE_SESSION_KEY, $workspace->id);

        return redirect('/settings/gtm-profile')->with('success', "{$workspace->name} created.");
    }

    public function switch(Request $request, int $workspaceId): RedirectResponse
    {
        $workspace = $request->user()
            ->workspaces()
            ->whereKey($workspaceId)
            ->first();

        abort_if($workspace === null, 403);

        $validated = $request->validate([
            'destination' => ['nullable', 'in:settings'],
        ]);

        $request->session()->put(User::ACTIVE_WORKSPACE_SESSION_KEY, $workspace->id);

        if (($validated['destination'] ?? null) === 'settings') {
            return to_route('mailboxes.index')->with('success', "Switched to {$workspace->name}.");
        }

        return redirect($this->safePreviousUrl($request) ?? route('unibox.index'))
            ->with('success', "Switched to {$workspace->name}.");
    }

    private function safePreviousUrl(Request $request): ?string
    {
        $previousUrl = url()->previous();
        $previousScheme = parse_url($previousUrl, PHP_URL_SCHEME);
        $previousPort = parse_url($previousUrl, PHP_URL_PORT)
            ?? ($previousScheme === 'https' ? 443 : 80);

        if (parse_url($previousUrl, PHP_URL_HOST) !== $request->getHost()
            || $previousScheme !== $request->getScheme()
            || $previousPort !== $request->getPort()) {
            return null;
        }

        try {
            $previousRoute = Route::getRoutes()->match(Request::create($previousUrl, 'GET'));
        } catch (MethodNotAllowedHttpException|NotFoundHttpException) {
            return null;
        }

        return $previousRoute->parameterNames() === [] ? $previousUrl : null;
    }
}
