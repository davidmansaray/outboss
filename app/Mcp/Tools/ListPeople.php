<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Http\Resources\Api\V1\PersonResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Searches people in the authenticated workspace by name, email, or account. Optional account_id filter. Defaults to 20 results (maximum 100). Does not mutate data.')]
#[IsReadOnly]
class ListPeople extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search term matched against name, email, and account name.'),
            'account_id' => $schema->integer()
                ->description('If set, only people belonging to this account are returned.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::PeopleRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $search = trim((string) $request->get('query', ''));
        $accountId = $request->get('account_id');

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
            ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

        return $this->json($this->pagePayload(
            PersonResource::collection($people->getCollection())->resolve(),
            $people->currentPage(),
            $people->perPage(),
            $people->total(),
        ));
    }
}
