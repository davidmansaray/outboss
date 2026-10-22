<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Models\SuppressionEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Removes a suppression entry so the address can be enrolled again. Does not send email.')]
class RemoveSuppression extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'suppression_id' => $schema->integer()
                ->required()
                ->description('The suppression entry id to delete.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SuppressionsWrite->value)) {
            return $denied;
        }

        $entry = $this->authorised(
            $request,
            SuppressionEntry::class,
            $request->integer('suppression_id'),
            'delete',
        );
        $entry->delete();

        return $this->json([
            'deleted' => true,
            'id' => $entry->id,
        ]);
    }
}
