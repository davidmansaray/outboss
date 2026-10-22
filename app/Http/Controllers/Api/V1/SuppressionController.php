<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mail\Enums\SuppressionReason;
use App\Domain\Mail\Services\AddSuppressionEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSuppressionRequest;
use App\Http\Resources\Api\V1\SuppressionEntryResource;
use App\Models\SuppressionEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SuppressionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $entries = $workspace->suppressions()
            ->latest()
            ->paginate(min(100, max(1, $request->integer('per_page', 15))))
            ->withQueryString();

        return SuppressionEntryResource::collection($entries);
    }

    public function store(StoreSuppressionRequest $request, AddSuppressionEntry $add): JsonResponse
    {
        $data = $request->validated();
        $reason = isset($data['reason'])
            ? SuppressionReason::from($data['reason'])
            : SuppressionReason::Manual;
        $entry = $add->handle(
            $request->user(),
            $request->user()->currentWorkspace(),
            $data['email'],
            $reason,
        );

        return (new SuppressionEntryResource($entry))
            ->response()
            ->setStatusCode($entry->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(SuppressionEntry $entry): Response
    {
        $this->authorize('delete', $entry);
        $entry->delete();

        return response()->noContent();
    }
}
