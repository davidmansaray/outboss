<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Services\CachedDomainAuthChecker;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Mailbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Checks SPF, DKIM, and DMARC for a mailbox sending domain. Use this when reviewing launch readiness. Does not mutate data.')]
#[IsReadOnly]
class GetMailboxAuthCheck extends OutbossTool
{
    public function __construct(private CachedDomainAuthChecker $checker) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'mailbox_id' => $schema->integer()
                ->required()
                ->description('The mailbox whose sending domain should be checked.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::MailboxesRead->value)) {
            return $denied;
        }

        $mailbox = $this->authorised($request, Mailbox::class, $request->integer('mailbox_id'));
        $domain = EmailNormalizer::domain($mailbox->email_address) ?? '';

        return $this->json([
            'mailbox_id' => $mailbox->id,
            'domain' => $domain,
            ...$this->checker->check($domain),
        ]);
    }
}
