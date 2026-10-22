<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\People\Services\PersonEditor;
use App\Domain\Prospecting\Exceptions\SourceIdempotencyConflict;
use App\Http\Resources\Api\V1\PersonResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Creates a person in the authenticated workspace. Email must be unique in the workspace. Optionally attach an existing account_id. Does not enrol them in a campaign or send email.')]
class CreatePerson extends OutbossTool
{
    public function __construct(private PersonEditor $editor) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->required()
                ->description('Person email address. Must be unique in this workspace.'),
            'full_name' => $schema->string()
                ->description('Full name. First name is derived automatically.'),
            'job_title' => $schema->string()
                ->description('Optional job title.'),
            'notes' => $schema->string()
                ->description('Optional notes, up to 10,000 characters.'),
            'tags' => $schema->array()
                ->items($schema->string())
                ->max(20)
                ->description('Optional tags, up to 20 values of 40 characters.'),
            'account_id' => $schema->integer()
                ->description('Existing workspace account to attach. Omit to leave unassigned.'),
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
            'email' => ['required', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'account_id' => ['nullable', 'integer'],
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
        ]);
        $user = $this->actor($request);
        try {
            $person = $this->editor->create(
                $user->currentWorkspace(),
                $user,
                $data,
                $data['idempotency_key'] ?? null,
                sourceKey: 'mcp',
            );
        } catch (SourceIdempotencyConflict $exception) {
            return Response::error('source_idempotency_conflict: '.$exception->getMessage());
        }

        return $this->json((new PersonResource($person->load('account:id,name')))->resolve());
    }
}
