<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\PersonResource;
use App\Models\Person;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one person, including their linked account when present. Does not mutate data.')]
#[IsReadOnly]
class GetPerson extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'person_id' => $schema->integer()
                ->required()
                ->description('The person id to load.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::PeopleRead->value)) {
            return $denied;
        }

        $person = $this->authorised($request, Person::class, $request->integer('person_id'));

        return $this->json((new PersonResource($person->load('account:id,name')))->resolve());
    }
}
