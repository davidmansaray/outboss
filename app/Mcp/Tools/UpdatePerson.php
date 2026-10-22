<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\People\Services\PersonEditor;
use App\Http\Resources\Api\V1\PersonResource;
use App\Models\Person;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Updates a person. Email is required and must stay unique in the workspace. Changing email does not rewrite historical campaign or inbox records. Does not send email.')]
class UpdatePerson extends OutbossTool
{
    public function __construct(private PersonEditor $editor) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'person_id' => $schema->integer()
                ->required()
                ->description('The person to update.'),
            'email' => $schema->string()
                ->required()
                ->description('Current or new email address. Must be unique in this workspace.'),
            'full_name' => $schema->string()
                ->description('Full name. First name is derived automatically.'),
            'job_title' => $schema->string()
                ->description('Optional job title.'),
            'notes' => $schema->string()
                ->description('Optional notes, up to 10,000 characters.'),
            'tags' => $schema->array()
                ->items($schema->string())
                ->max(20)
                ->description('Replacement tag list, up to 20 values.'),
            'account_id' => $schema->integer()
                ->description('Workspace account to attach, or omit to leave unchanged. Pass null via omitting after setting another field if needed.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::PeopleWrite->value)) {
            return $denied;
        }

        $data = $request->validate([
            'person_id' => ['required', 'integer'],
            'email' => ['required', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'account_id' => ['nullable', 'integer'],
        ]);
        $person = $this->authorised($request, Person::class, (int) $data['person_id'], 'update');
        unset($data['person_id']);
        $person = $this->editor->update($person, $data);

        return $this->json((new PersonResource($person->load('account:id,name')))->resolve());
    }
}
