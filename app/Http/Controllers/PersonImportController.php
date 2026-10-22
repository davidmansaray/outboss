<?php

namespace App\Http\Controllers;

use App\Domain\People\Services\CommitPersonImport;
use App\Domain\People\Services\PreviewPersonImport;
use App\Http\Requests\CommitPersonImportRequest;
use App\Http\Requests\PreviewPersonImportRequest;
use App\Models\PersonImport;
use App\Models\PersonImportRow;
use Illuminate\Http\RedirectResponse;

class PersonImportController extends Controller
{
    public function preview(PreviewPersonImportRequest $request, PreviewPersonImport $preview): RedirectResponse
    {
        $data = $request->validated();
        $mapping = array_merge(
            ['email' => 'email', 'name' => 'name', 'job_title' => 'job_title', 'company' => 'company', 'tags' => 'tags'],
            $data['mapping'],
        );
        $import = $preview->handle(
            $request->user(),
            $request->user()->currentWorkspace(),
            $data['rows'],
            $mapping,
            $data['filename'] ?? null,
        );

        return back()->with('personImport', $this->reviewPayload($import->load('rows')));
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewPayload(PersonImport $import): array
    {
        $samples = [];

        foreach (['new', 'updated', 'invalid', 'duplicate', 'suppressed'] as $issue) {
            $samples[$issue] = array_values($import->rows
                ->where('issue', $issue)
                ->take(5)
                ->map(fn (PersonImportRow $row): array => [
                    'row_number' => $row->row_number,
                    'email' => $row->email_address,
                    'name' => $row->full_name,
                    'company' => $row->company,
                    'issue' => $row->issue,
                ])
                ->all());
        }

        return [
            'id' => $import->id,
            'source_name' => $this->sourceName($import),
            'policy_summary' => 'Native CSV import · prospecting use · 365-day normalized retention · export allowed',
            'mapping_version' => $import->mapping_version,
            'total' => $import->total,
            'new' => $import->new,
            'updated' => $import->updated,
            'invalid' => $import->invalid,
            'duplicate' => $import->duplicate,
            'suppressed' => $import->suppressed,
            'samples' => $samples,
        ];
    }

    private function sourceName(PersonImport $import): string
    {
        $filename = trim((string) $import->original_filename);
        $name = $filename === '' ? 'People import #'.$import->id : 'People import · '.$filename;

        return mb_strcut($name, 0, 160, 'UTF-8');
    }

    public function commit(CommitPersonImportRequest $request, PersonImport $import, CommitPersonImport $commit): RedirectResponse
    {
        $this->authorize('commit', $import);
        $commit->handle($import, $request->validated('expected_mapping_version'));

        return back()->with('success', 'People imported.');
    }
}
