<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Workspaces\Services\WorkspaceExportService;
use App\Domain\Workspaces\Support\CsvExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    private const JSON_LIMIT = 1000;

    public function __construct(private WorkspaceExportService $exports) {}

    public function enrollments(Request $request): JsonResponse|StreamedResponse
    {
        return $this->export(
            $request,
            'enrollments.csv',
            $this->exports->enrollmentHeaders(),
            $this->exports->enrollmentRows((int) $request->user()->currentWorkspace()->id),
        );
    }

    public function suppressions(Request $request): JsonResponse|StreamedResponse
    {
        return $this->export(
            $request,
            'suppressions.csv',
            $this->exports->suppressionHeaders(),
            $this->exports->suppressionRows((int) $request->user()->currentWorkspace()->id),
        );
    }

    public function campaignOutcomes(Request $request): JsonResponse|StreamedResponse
    {
        return $this->export(
            $request,
            'campaign-outcomes.csv',
            $this->exports->campaignOutcomeHeaders(),
            $this->exports->campaignOutcomeRows((int) $request->user()->currentWorkspace()->id),
        );
    }

    public function s2(Request $request, string $section): JsonResponse|StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        abort_unless($workspace->isOwner($request->user()), 403);
        $exports = $this->exports->s2Sections((int) $workspace->id);
        abort_unless(array_key_exists($section, $exports), 404);

        return $this->export(
            $request,
            's2-'.str_replace('_', '-', $section).'.csv',
            $exports[$section]['headers'],
            $exports[$section]['rows'],
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<int|string|null>>  $rows
     */
    private function export(Request $request, string $filename, array $headers, iterable $rows): JsonResponse|StreamedResponse
    {
        if ($this->wantsCsv($request)) {
            return CsvExport::stream($filename, $headers, $rows);
        }

        $records = $this->exports->records($headers, $rows, self::JSON_LIMIT + 1);
        $truncated = count($records) > self::JSON_LIMIT;

        return response()->json([
            'data' => array_slice($records, 0, self::JSON_LIMIT),
            'meta' => [
                'limit' => self::JSON_LIMIT,
                'truncated' => $truncated,
                'full_export_format' => 'csv',
            ],
        ]);
    }

    private function wantsCsv(Request $request): bool
    {
        if ($request->query('format') === 'csv') {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'text/csv');
    }
}
