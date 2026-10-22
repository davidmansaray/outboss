<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\People\Services\AccountEditor;
use App\Domain\Prospecting\Exceptions\SourceIdempotencyConflict;
use App\Http\Resources\Api\V1\AccountResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Creates an account (company) in the authenticated workspace. A website domain must be unique. If the name already exists without a website, set confirmed_duplicate=true to keep both records.')]
class CreateAccount extends OutbossTool
{
    public function __construct(private AccountEditor $editor) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->required()
                ->description('Account name, up to 255 characters.'),
            'website' => $schema->string()
                ->description('Optional website. The derived domain must be unique in the workspace.'),
            'notes' => $schema->string()
                ->description('Optional notes, up to 10,000 characters.'),
            'tags' => $schema->array()
                ->items($schema->string())
                ->max(20)
                ->description('Optional tags, up to 20 values.'),
            'confirmed_duplicate' => $schema->boolean()
                ->description('Set true to create an account whose name already exists when no website is supplied.'),
            'idempotency_key' => $schema->string()
                ->description('Optional stable retry key for this create request, maximum 160 characters.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::PeopleWrite->value)) {
            return $denied;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'confirmed_duplicate' => ['sometimes', 'boolean'],
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
        ]);
        $user = $this->actor($request);
        try {
            $account = $this->editor->create(
                $user->currentWorkspace(),
                $user,
                $data,
                $data['idempotency_key'] ?? null,
                sourceKey: 'mcp',
            );
        } catch (SourceIdempotencyConflict $exception) {
            return Response::error('source_idempotency_conflict: '.$exception->getMessage());
        }

        return $this->json((new AccountResource($account->loadCount('people')))->resolve());
    }
}
