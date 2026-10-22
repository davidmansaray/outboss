<?php

namespace App\Http\Controllers;

use App\Domain\People\Services\PersonEditor;
use App\Domain\People\Support\ProvenanceSummary;
use App\Domain\Workspaces\Services\AuditWorkspaceDataExport;
use App\Domain\Workspaces\Support\CsvExport;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonController extends Controller
{
    public function store(StorePersonRequest $request, PersonEditor $editor): RedirectResponse
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
            ->route('prospects.index')
            ->with('success', 'Person created.');
    }

    public function update(UpdatePersonRequest $request, Person $person, PersonEditor $editor): RedirectResponse
    {
        $this->authorize('update', $person);
        $editor->update($person, $request->validated());

        return redirect()
            ->route('prospects.index')
            ->with('success', 'Person saved.');
    }

    public function destroy(Request $request, Person $person, PersonEditor $editor): RedirectResponse
    {
        $this->authorize('delete', $person);
        $editor->delete($person, $request->user());

        return redirect()
            ->route('prospects.index')
            ->with('success', 'Person deleted; their campaign history is preserved.');
    }

    public function export(Request $request, AuditWorkspaceDataExport $audit): StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $audit->record($workspace, $request->user(), 'people');
        $suppressed = $workspace->suppressions()
            ->pluck('email_normalized')
            ->filter()
            ->flip();
        $people = $workspace->people()
            ->with('account:id,name')
            ->withCount('recipients')
            ->orderBy('full_name')
            ->orderBy('email_normalized')
            ->get();

        return CsvExport::stream('people.csv', [
            'name',
            'email',
            'job_title',
            'account',
            'notes',
            'tags',
            'source',
            'source_count',
            'source_ids',
            'source_names',
            'latest_observed_at',
            'source_omission_reason',
            'eligibility',
            'campaigns_count',
            'created_at',
            'updated_at',
        ], $this->peopleRows($people, $suppressed));
    }

    /**
     * @param  Collection<int, Person>  $people
     * @param  Collection<string, int>  $suppressed
     * @return iterable<int, list<int|string|null>>
     */
    private function peopleRows($people, $suppressed): iterable
    {
        foreach ($people as $person) {
            $provenance = ProvenanceSummary::forExport($person);

            yield [
                $person->full_name ?: $person->first_name ?: $person->email_address,
                $person->email_address,
                $person->job_title,
                $person->account?->name,
                $person->notes,
                implode('|', $person->tags),
                $person->source,
                $provenance['source_count'],
                $provenance['source_ids'],
                $provenance['source_names'],
                $provenance['latest_observed_at'],
                $provenance['source_omission_reason'],
                $suppressed->has($person->email_normalized) ? 'Suppressed' : 'Eligible',
                $person->recipients_count,
                $person->created_at?->toIso8601String(),
                $person->updated_at?->toIso8601String(),
            ];
        }
    }
}
