<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $workspace = $user?->currentWorkspace();
        $membership = $workspace
            ? $workspace->membershipFor($user)
            : null;
        $availableWorkspaces = $user?->workspaces()
            ->withPivot('role')
            ->orderBy('workspaces.name')
            ->get() ?? collect();
        $workspaces = $availableWorkspaces
            ->map(fn (Workspace $availableWorkspace): array => $this->workspaceData(
                $availableWorkspace,
                data_get($availableWorkspace->getRelation('pivot'), 'role'),
            ))
            ->values()
            ->all();
        $currentWorkspace = $workspace === null
            ? null
            : collect($workspaces)->firstWhere('id', $workspace->id);
        if ($workspace !== null && $currentWorkspace === null) {
            $currentWorkspace = $this->workspaceData($workspace, $membership?->role);
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'workspace' => $currentWorkspace,
                'workspaces' => $workspaces,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'import' => $request->session()->get('import'),
                'personImport' => $request->session()->get('personImport'),
                'enrollment' => $request->session()->get('enrollment'),
            ],
        ];
    }

    /** @return array{id: int, name: string, type: string, role: string|null, avatar_url: string|null} */
    private function workspaceData(Workspace $workspace, mixed $role): array
    {
        $path = is_string($workspace->avatar_path) && $workspace->avatar_path !== ''
            ? $workspace->avatar_path
            : null;

        return [
            'id' => (int) $workspace->id,
            'name' => (string) $workspace->name,
            'type' => (string) $workspace->type,
            'role' => is_string($role) ? $role : null,
            'avatar_url' => $path !== null ? Storage::disk('public')->url($path) : null,
        ];
    }
}
