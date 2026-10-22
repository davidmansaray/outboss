<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\People\Services\PersonEditor;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Http\Resources\Api\V1\PersonResource;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PersonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $search = trim((string) $request->query('query', $request->query('q', '')));
        $accountId = $request->query('account_id');

        $people = $workspace->people()
            ->with('account:id,name')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $emailNeedle = '%'.addcslashes(EmailNormalizer::normalize($search), '%_\\').'%';
                $query->where(function ($builder) use ($like, $emailNeedle): void {
                    $builder->where('full_name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('email_normalized', 'like', $emailNeedle)
                        ->orWhereHas('account', fn ($account) => $account->where('name', 'like', $like));
                });
            })
            ->when(is_numeric($accountId), fn ($query) => $query->where('account_id', (int) $accountId))
            ->orderBy('full_name')
            ->orderBy('email_normalized')
            ->paginate(min(100, max(1, $request->integer('per_page', 15))))
            ->withQueryString();

        return PersonResource::collection($people);
    }

    public function store(StorePersonRequest $request, PersonEditor $editor): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $person = $editor->create(
            $workspace,
            $request->user(),
            $request->validated(),
            $request->header('Idempotency-Key'),
            sourceKey: 'rest_api',
        );

        return (new PersonResource($person->load('account:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Person $person): PersonResource
    {
        $this->authorize('view', $person);

        return new PersonResource($person->load('account:id,name'));
    }

    public function update(UpdatePersonRequest $request, Person $person, PersonEditor $editor): PersonResource
    {
        $this->authorize('update', $person);
        $person = $editor->update($person, $request->validated());

        return new PersonResource($person->load('account:id,name'));
    }

    public function destroy(Request $request, Person $person, PersonEditor $editor): Response
    {
        $this->authorize('delete', $person);
        $editor->delete($person, $request->user());

        return response()->noContent();
    }
}
