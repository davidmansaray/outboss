<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\AccountResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists accounts (companies) in the authenticated workspace. Optional search across name, domain, and website. Defaults to 20 results (maximum 100). Does not mutate data.')]
#[IsReadOnly]
class ListAccounts extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search term matched against name, domain, and website.'),
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
            ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

        return $this->json($this->pagePayload(
            AccountResource::collection($accounts->getCollection())->resolve(),
            $accounts->currentPage(),
            $accounts->perPage(),
            $accounts->total(),
        ));
    }
}
