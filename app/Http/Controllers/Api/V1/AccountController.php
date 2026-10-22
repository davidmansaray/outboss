<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\People\Services\AccountEditor;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $search = trim((string) $request->query('query', $request->query('q', '')));

        $accounts = $workspace->accounts()
            ->withCount('people')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($builder) use ($like): void {
                    $builder->where('name', 'like', $like)
                        ->orWhere('domain_normalized', 'like', $like)
                        ->orWhere('website', 'like', $like);
                });
            })
            ->orderBy('name')
            ->paginate(min(100, max(1, $request->integer('per_page', 15))))
            ->withQueryString();

        return AccountResource::collection($accounts);
    }

    public function store(StoreAccountRequest $request, AccountEditor $editor): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $account = $editor->create(
            $workspace,
            $request->user(),
            $request->validated(),
            $request->header('Idempotency-Key'),
            sourceKey: 'rest_api',
        );

        return (new AccountResource($account->loadCount('people')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        $this->authorize('view', $account);

        return new AccountResource($account->loadCount('people'));
    }

    public function update(UpdateAccountRequest $request, Account $account, AccountEditor $editor): AccountResource
    {
        $this->authorize('update', $account);
        $account = $editor->update($account, $request->validated());

        return new AccountResource($account->loadCount('people'));
    }

    public function destroy(Request $request, Account $account, AccountEditor $editor): Response
    {
        $this->authorize('delete', $account);
        $editor->delete($account, $request->user());

        return response()->noContent();
    }
}
