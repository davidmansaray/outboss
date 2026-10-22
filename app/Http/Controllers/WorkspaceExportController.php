<?php

namespace App\Http\Controllers;

use App\Domain\Workspaces\Services\AuditWorkspaceDataExport;
use App\Domain\Workspaces\Services\WorkspaceExportService;
use App\Domain\Workspaces\Support\CsvExport;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceExportController extends Controller
{
    public function __construct(
        private WorkspaceExportService $exports,
        private AuditWorkspaceDataExport $audit,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace();

        return Inertia::render('data-export/Index', [
            'suppressionsCount' => $workspace->suppressions()->count(),
            'isOwner' => $workspace->isOwner($request->user()),
        ]);
    }

    public function enrollments(Request $request): StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->audit->record($workspace, $request->user(), 'enrollments');

        return CsvExport::stream(
            'enrollments.csv',
            $this->exports->enrollmentHeaders(),
            $this->exports->enrollmentRows((int) $workspace->id),
        );
    }

    public function suppressions(Request $request): StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->audit->record($workspace, $request->user(), 'suppressions');

        return CsvExport::stream(
            'suppressions.csv',
            $this->exports->suppressionHeaders(),
            $this->exports->suppressionRows((int) $workspace->id),
        );
    }

    public function campaignOutcomes(Request $request): StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->audit->record($workspace, $request->user(), 'campaign_outcomes');

        return CsvExport::stream(
            'campaign-outcomes.csv',
            $this->exports->campaignOutcomeHeaders(),
            $this->exports->campaignOutcomeRows((int) $workspace->id),
        );
    }

    public function sources(Request $request): StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $this->audit->record($workspace, $request->user(), 'prospect_sources');

        return $this->noStore(CsvExport::stream(
            'prospect-sources.csv',
            $this->exports->sourceHeaders(),
            $this->exports->sourceRows((int) $workspace->id),
        ));
    }

    public function integrations(Request $request): StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $this->audit->record($workspace, $request->user(), 'integrations');

        return $this->noStore(CsvExport::stream(
            'integrations.csv',
            $this->exports->integrationHeaders(),
            $this->exports->integrationRows((int) $workspace->id),
        ));
    }

    public function s2(Request $request, string $section): StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $exports = $this->exports->s2Sections((int) $workspace->id);
        abort_unless(array_key_exists($section, $exports), 404);
        $this->audit->record($workspace, $request->user(), 's2_'.$section);

        return $this->noStore(CsvExport::stream(
            's2-'.str_replace('_', '-', $section).'.csv',
            $exports[$section]['headers'],
            $exports[$section]['rows'],
        ));
    }

    private function assertOwner(User $user, Workspace $workspace): void
    {
        abort_unless($workspace->isOwner($user), 403);
    }

    private function noStore(StreamedResponse $response): StreamedResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
