<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Objectives\Services\CreateSegment;
use App\Domain\Objectives\Services\EvaluateSegment;
use App\Domain\Objectives\Services\FreezeAudienceSnapshot;
use App\Domain\Objectives\Services\VersionSegment;
use App\Domain\Objectives\Support\AudiencePreviewToken;
use App\Http\Controllers\Controller;
use App\Models\AudienceSnapshot;
use App\Models\Segment;
use App\Models\SegmentVersion;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SegmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $segments = Segment::query()
            ->where('workspace_id', $workspace->id)
            ->with('currentVersion')
            ->orderByDesc('created_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($segments->items())->map(fn (Segment $segment): array => $this->segmentPayload($segment))->all(),
            'meta' => [
                'current_page' => $segments->currentPage(),
                'last_page' => $segments->lastPage(),
                'per_page' => $segments->perPage(),
                'total' => $segments->total(),
            ],
        ]);
    }

    public function show(Request $request, string $segment): JsonResponse
    {
        return response()->json(['data' => $this->segmentPayload($this->segment($request, $segment)->load('currentVersion'))]);
    }

    public function store(Request $request, CreateSegment $create): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'criteria' => ['required', 'array'],
            'buyer_roles' => ['sometimes', 'array', 'max:20'],
            'buyer_roles.*' => ['string', 'max:120'],
            'required_qualification_outcomes' => ['sometimes', 'array', 'max:10'],
            'required_qualification_outcomes.*' => ['string', 'in:contact,withhold,research_further,insufficient_evidence,needs_review'],
        ]);
        $segment = $create->handle(
            $workspace,
            $request->user(),
            $data['name'],
            $data['criteria'],
            $data['description'] ?? null,
            $data['buyer_roles'] ?? [],
            $data['required_qualification_outcomes'] ?? ['contact'],
        );

        return response()->json(['data' => $this->segmentPayload($segment)], 201);
    }

    public function version(Request $request, string $segment, VersionSegment $version): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $segmentModel = $this->segment($request, $segment);
        $data = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'criteria' => ['sometimes', 'array'],
            'buyer_roles' => ['sometimes', 'array', 'max:20'],
            'required_qualification_outcomes' => ['sometimes', 'array', 'max:10'],
        ]);
        $created = $version->handle($segmentModel, $request->user(), $data, (int) $data['expected_lock_version']);

        return response()->json(['data' => $this->versionPayload($created)], 201);
    }

    public function preview(Request $request, string $segment, EvaluateSegment $evaluate, AudiencePreviewToken $tokens): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $segmentModel = $this->segment($request, $segment)->load('currentVersion');
        abort_if($segmentModel->currentVersion === null, 409, 'segment_version_missing');
        $evaluation = $evaluate->handle($workspace, $segmentModel->currentVersion);

        return response()->json(['data' => [
            'segment_version_id' => $segmentModel->currentVersion->id,
            'criteria_hash' => $evaluation->criteriaHash,
            'evaluation_hash' => $evaluation->evaluationHash,
            'preview_token' => $tokens->issue((int) $workspace->id, $segmentModel->currentVersion->id, $evaluation->evaluationHash),
            'counts' => $evaluation->counts,
            'members' => array_map(fn (array $member): array => $this->memberPayload($member), $evaluation->members),
        ]]);
    }

    public function snapshot(Request $request, string $segment, FreezeAudienceSnapshot $freeze): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $segmentModel = $this->segment($request, $segment)->load('currentVersion');
        abort_if($segmentModel->currentVersion === null, 409, 'segment_version_missing');
        $data = $request->validate([
            'preview_hash' => ['required', 'string', 'size:64'],
            'preview_token' => ['required', 'string', 'max:2000'],
            'objective_id' => ['nullable', 'uuid'],
        ]);
        $snapshot = $freeze->handle(
            $workspace,
            $segmentModel->currentVersion,
            $request->user(),
            $data['preview_hash'],
            $data['preview_token'],
            $data['objective_id'] ?? null,
        );

        return response()->json(['data' => $this->snapshotPayload($snapshot)], 201);
    }

    public function showSnapshot(Request $request, string $snapshot): JsonResponse
    {
        $snapshotModel = $this->snapshotModel($request, $snapshot);

        return response()->json(['data' => $this->snapshotPayload($snapshotModel)]);
    }

    public function snapshotMembers(Request $request, string $snapshot): JsonResponse
    {
        $snapshotModel = $this->snapshotModel($request, $snapshot);
        $members = $snapshotModel->members()->orderBy('account_id')->orderBy('person_id')
            ->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return response()->json([
            'data' => collect($members->items())->map(fn ($member): array => $this->memberPayload($member->toArray()))->all(),
            'meta' => ['current_page' => $members->currentPage(), 'last_page' => $members->lastPage(), 'per_page' => $members->perPage(), 'total' => $members->total()],
        ]);
    }

    private function segment(Request $request, string $id): Segment
    {
        return Segment::query()->where('workspace_id', $request->user()->currentWorkspace()->id)->whereKey($id)->firstOrFail();
    }

    private function snapshotModel(Request $request, string $id): AudienceSnapshot
    {
        return AudienceSnapshot::query()->where('workspace_id', $request->user()->currentWorkspace()->id)->whereKey($id)->firstOrFail();
    }

    private function assertOwner(Request $request, Workspace $workspace): void
    {
        abort_unless($workspace->isOwner($request->user()), 403);
    }

    /** @return array<string, mixed> */
    private function segmentPayload(Segment $segment): array
    {
        return [
            'id' => $segment->id,
            'status' => $segment->status->value,
            'lock_version' => $segment->lock_version,
            'current_version' => $segment->currentVersion === null ? null : $this->versionPayload($segment->currentVersion),
            'created_at' => $segment->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function versionPayload(SegmentVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'name' => $version->name,
            'description' => $version->description,
            'criteria' => $version->criteria,
            'buyer_roles' => $version->buyer_roles,
            'required_qualification_outcomes' => $version->required_qualification_outcomes,
            'criteria_schema_version' => $version->criteria_schema_version,
            'evaluator_version' => $version->evaluator_version,
            'content_hash' => $version->content_hash,
        ];
    }

    /** @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    private function memberPayload(array $member): array
    {
        return collect($member)->only([
            'id', 'account_id', 'person_id', 'result', 'criterion_results', 'inclusion_reasons',
            'exclusion_reasons', 'missing_fields', 'suppression_snapshot', 'evidence_manifest',
            'member_hash', 'evidence_fresh_until', 'evaluated_at',
        ])->map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value)->all();
    }

    /** @return array<string, mixed> */
    private function snapshotPayload(AudienceSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'segment_version_id' => $snapshot->segment_version_id,
            'objective_id' => $snapshot->objective_id,
            'status' => $snapshot->status,
            'purpose' => $snapshot->purpose,
            'criteria_hash' => $snapshot->criteria_hash,
            'evaluation_hash' => $snapshot->evaluation_hash,
            'counts' => [
                'included_people' => $snapshot->included_people_count,
                'excluded_people' => $snapshot->excluded_people_count,
                'uncertain_people' => $snapshot->uncertain_people_count,
                'suppressed_people' => $snapshot->suppressed_people_count,
                'included_accounts' => $snapshot->included_accounts_count,
                'excluded_accounts' => $snapshot->excluded_accounts_count,
                'uncertain_accounts' => $snapshot->uncertain_accounts_count,
                'suppressed_accounts' => $snapshot->suppressed_accounts_count,
            ],
            'source_rights' => $snapshot->source_rights_snapshot,
            'frozen_at' => $snapshot->frozen_at->toAtomString(),
        ];
    }
}
