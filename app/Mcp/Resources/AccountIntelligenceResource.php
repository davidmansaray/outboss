<?php

namespace App\Mcp\Resources;

use App\Domain\Api\TokenScope;
use App\Mcp\Concerns\ChecksScopes;
use App\Models\Account;
use App\Models\ResearchClaim;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Safe Account intelligence index for the authenticated workspace. Claim values and source content are available through scoped tools; this index contains only bounded status counts.')]
#[Uri('outboss://accounts/intelligence')]
#[MimeType('application/json')]
class AccountIntelligenceResource extends Resource
{
    use ChecksScopes;

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::IntelligenceRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $accountQuery = Account::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->limit(100);
        if ($request->filled('account_id')) {
            $accountQuery->whereKey($request->integer('account_id'));
        }
        $accounts = $accountQuery->get(['id', 'name', 'domain_normalized', 'website', 'updated_at']);
        $claimCounts = ResearchClaim::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('account_id', $accounts->pluck('id'))
            ->selectRaw('account_id, status, count(*) as aggregate')
            ->groupBy('account_id', 'status')
            ->get()
            ->groupBy('account_id');

        $payload = [
            'accounts' => $accounts->map(function (Account $account) use ($claimCounts): array {
                $counts = $claimCounts->get($account->id, collect())->mapWithKeys(
                    static fn ($row): array => [(string) $row->status => (int) $row->aggregate],
                )->all();

                return [
                    'id' => (int) $account->id,
                    'name' => mb_substr((string) $account->name, 0, 240),
                    'domain' => $account->domain_normalized,
                    'website' => $account->website,
                    'claim_counts' => $counts,
                    'updated_at' => $account->updated_at?->toIso8601String(),
                ];
            })->values()->all(),
            'truncated' => $accounts->count() === 100,
        ];

        try {
            return Response::text(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            return Response::error('The resource could not encode its response.');
        }
    }
}
