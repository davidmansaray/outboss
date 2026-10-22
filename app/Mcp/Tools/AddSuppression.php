<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Domain\Mail\Services\AddSuppressionEntry;
use App\Http\Resources\Api\V1\SuppressionEntryResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Adds an email to the workspace suppression list so it will not be enrolled or emailed. Defaults to a manual reason. Existing entries are returned unchanged.')]
class AddSuppression extends OutbossTool
{
    public function __construct(private AddSuppressionEntry $add) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->required()
                ->description('Email address to suppress.'),
            'reason' => $schema->string()
                ->enum(SuppressionReason::class)
                ->description('unsubscribe, hard_bounce, or manual. Defaults to manual.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SuppressionsWrite->value)) {
            return $denied;
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'reason' => ['sometimes', 'in:unsubscribe,hard_bounce,manual'],
        ]);
        $user = $this->actor($request);
        $reason = isset($data['reason'])
            ? SuppressionReason::from($data['reason'])
            : SuppressionReason::Manual;
        $entry = $this->add->handle($user, $user->currentWorkspace(), $data['email'], $reason);

        return $this->json((new SuppressionEntryResource($entry))->resolve());
    }
}
