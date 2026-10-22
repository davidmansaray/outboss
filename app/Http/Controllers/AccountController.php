<?php

namespace App\Http\Controllers;

use App\Domain\People\Services\AccountEditor;
use App\Domain\People\Support\ProvenanceSummary;
use App\Domain\Workspaces\Services\AuditWorkspaceDataExport;
use App\Domain\Workspaces\Support\CsvExport;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    public function store(StoreAccountRequest $request, AccountEditor $editor): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $editor->create(
            $workspace,
            $request->user(),
            $request->validated(),
            $request->header('Idempotency-Key'),
            sourceKey: 'manual',
        );

        return redirect()
            ->route('prospects.index', ['view' => 'accounts'])
            ->with('success', 'Account created.');
    }

    public function update(UpdateAccountRequest $request, Account $account, AccountEditor $editor): RedirectResponse
    {
        $this->authorize('update', $account);
        $editor->update($account, $request->validated());

        return redirect()
            ->route('prospects.index', ['view' => 'accounts'])
            ->with('success', 'Account saved.');
    }

    public function destroy(Request $request, Account $account, AccountEditor $editor): RedirectResponse
    {
        $this->authorize('delete', $account);
        $peopleCount = $editor->delete($account, $request->user());

        return redirect()
            ->route('prospects.index', ['view' => 'accounts'])
            ->with('success', "Account deleted; {$peopleCount} people kept their campaign history.");
    }

    public function export(Request $request, AuditWorkspaceDataExport $audit): StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $audit->record($workspace, $request->user(), 'accounts');
        $accounts = $workspace->accounts()
            ->withCount('people')
            ->orderBy('name')
            ->get();

        return CsvExport::stream('accounts.csv', [
            'name',
            'website',
            'domain',
            'notes',
            'tags',
            'source',
            'source_count',
            'source_ids',
            'source_names',
            'latest_observed_at',
            'source_omission_reason',
            'people_count',
            'created_at',
            'updated_at',
        ], $this->accountRows($accounts));
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return iterable<int, list<int|string|null>>
     */
    private function accountRows($accounts): iterable
    {
        foreach ($accounts as $account) {
            $provenance = ProvenanceSummary::forExport($account);

            yield [
                $account->name,
                $account->website,
                $account->domain_normalized,
                $account->notes,
                implode('|', $account->tags),
                $account->source,
                $provenance['source_count'],
                $provenance['source_ids'],
                $provenance['source_names'],
                $provenance['latest_observed_at'],
                $provenance['source_omission_reason'],
                $account->people_count,
                $account->created_at?->toIso8601String(),
                $account->updated_at?->toIso8601String(),
            ];
        }
    }
}
