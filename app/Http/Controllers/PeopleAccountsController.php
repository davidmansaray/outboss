<?php

namespace App\Http\Controllers;

use App\Domain\People\Services\BuildPeopleAccountsRegistry;
use App\Domain\People\Support\ProspectSourceSurface;
use App\Models\Account;
use App\Models\Person;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeopleAccountsController extends Controller
{
    public function index(
        Request $request,
        BuildPeopleAccountsRegistry $registry,
    ): Response {
        $workspace = $request->user()->currentWorkspace();
        $view = $request->query('view', $request->routeIs('accounts.index') ? 'accounts' : 'people');

        return Inertia::render('people/Index', [
            ...$registry->for($workspace),
            'initialView' => in_array($view, ['people', 'accounts', 'sources'], true) ? $view : 'people',
            'sources' => ProspectSourceSurface::list($workspace),
            'preselect' => $this->preselect($request, (int) $workspace->id),
        ]);
    }

    /**
     * @return array{kind: 'person'|'account'|'source', id: int|string}|null
     */
    private function preselect(Request $request, int $workspaceId): ?array
    {
        $personId = $request->query('person');

        if (is_numeric($personId)) {
            Person::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey((int) $personId)
                ->firstOrFail();

            return ['kind' => 'person', 'id' => (int) $personId];
        }

        $accountId = $request->query('account');

        if (is_numeric($accountId)) {
            Account::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey((int) $accountId)
                ->firstOrFail();

            return ['kind' => 'account', 'id' => (int) $accountId];
        }

        $sourceId = $request->query('source');

        if (is_string($sourceId) && ProspectSourceSurface::find(
            $request->user()->currentWorkspace(),
            $sourceId,
        ) !== null) {
            return ['kind' => 'source', 'id' => strtolower($sourceId)];
        }

        return null;
    }
}
