<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\DTOs\OAuthCallback;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\OAuthIntent;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Jobs\Mail\InitialSyncMailbox;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\OAuthAttempt;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ConnectMailboxService
{
    public function __construct(
        private ProviderRegistry $registry,
        private MailboxSenderIdentityService $senderIdentities,
    ) {}

    public function start(User $user, MailProviderName $provider, ?Mailbox $reconnectMailbox = null): string
    {
        // Resolve this before creating an OAuth attempt so transport-only
        // providers cannot leave unusable callback state behind.
        $oauth = $this->registry->oauth($provider);
        $workspace = $user->currentWorkspace();

        if ($reconnectMailbox && (int) $reconnectMailbox->getAttribute('workspace_id') !== (int) $workspace->id) {
            throw new RuntimeException('workspace_mismatch');
        }

        $state = Str::random(40);
        $attempt = OAuthAttempt::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => $provider,
            'state_hash' => hash('sha256', $state),
            'pkce_verifier' => Str::random(64),
            'reconnect_connection_id' => $reconnectMailbox?->provider_connection_id,
            'reconnect_mailbox_id' => $reconnectMailbox?->id,
            'intent' => $reconnectMailbox ? OAuthIntent::ReconnectMailbox : OAuthIntent::ConnectMailbox,
            'expires_at' => now()->addMinutes(10),
        ]);

        $attempt->raw_state = $state;

        return $oauth->authorizationUrl($attempt);
    }

    public function complete(User $user, MailProviderName $provider, OAuthCallback $callback, string $rawState): ProviderConnection
    {
        $oauth = $this->registry->oauth($provider);
        $attempt = OAuthAttempt::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('state_hash', hash('sha256', $rawState))
            ->first();

        if (! $attempt || ! $attempt->isUsable()) {
            throw new RuntimeException('oauth_state_invalid');
        }

        $workspace = $user->workspaces()
            ->whereKey($attempt->workspace_id)
            ->first();

        if ($workspace === null) {
            $attempt->forceFill(['consumed_at' => now()])->save();
            throw new RuntimeException('workspace_mismatch');
        }

        if ($callback->error) {
            $attempt->forceFill(['consumed_at' => now()])->save();
            throw new RuntimeException('oauth_denied');
        }

        $account = $oauth->exchangeAuthorizationCode($callback);
        $attempt->forceFill(['consumed_at' => now()])->save();

        return DB::transaction(function () use ($user, $workspace, $provider, $account, $attempt) {
            if ($attempt->intent === OAuthIntent::ReconnectMailbox) {
                $connection = ProviderConnection::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($attempt->reconnect_connection_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($connection->provider_user_id !== $account->providerUserId) {
                    throw new RuntimeException('oauth_identity_mismatch');
                }

                $mailbox = Mailbox::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($attempt->reconnect_mailbox_id)
                    ->firstOrFail();

                $remote = collect($account->mailboxes)->firstWhere('providerMailboxId', $mailbox->provider_mailbox_id)
                    ?? collect($account->mailboxes)->first();

                if (! $remote || ($remote->emailAddress && strcasecmp($remote->emailAddress, $mailbox->email_address) !== 0 && $remote->providerMailboxId !== $mailbox->provider_mailbox_id)) {
                    throw new RuntimeException('oauth_mailbox_mismatch');
                }
            }

            $existing = ProviderConnection::query()
                ->where('workspace_id', $workspace->id)
                ->where('provider', $provider)
                ->where('connection_kind', ConnectionKind::OAuth)
                ->whereNull('managed_instance_key')
                ->where('provider_user_id', $account->providerUserId)
                ->lockForUpdate()
                ->first();

            $refreshToken = filled($account->refreshToken)
                ? $account->refreshToken
                : $existing?->refresh_token;

            $intent = $attempt->getAttribute('intent');
            $isNewConnect = ($intent instanceof OAuthIntent
                ? $intent
                : OAuthIntent::from((string) $intent)) === OAuthIntent::ConnectMailbox;

            if (! filled($refreshToken) && $isNewConnect && $existing === null) {
                throw new RuntimeException('oauth_no_refresh_token');
            }

            $identity = [
                'workspace_id' => $workspace->id,
                'provider' => $provider,
                'connection_kind' => ConnectionKind::OAuth,
                'managed_instance_key' => null,
                'provider_user_id' => $account->providerUserId,
            ];
            $attributes = [
                'user_id' => $user->id,
                'auth_scheme' => AuthScheme::OAuth2,
                'access_token' => $account->accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $account->tokenExpiresAt,
                'granted_scopes' => $account->grantedScopes,
                'provider_metadata' => $account->metadata,
                'status' => ConnectionStatus::Connected,
                'last_error_code' => null,
                'last_error_message' => null,
            ];

            if ($existing instanceof ProviderConnection) {
                // Completing OAuth again replaces the credentials and thus
                // starts a new approval authority generation. This includes
                // both the explicit reconnect flow and reconnecting the same
                // provider identity through the connect entry point.
                $existing->forceFill($attributes)->advanceAuthorizationVersion()->save();
                $connection = $existing;
            } else {
                $connection = ProviderConnection::query()->create([
                    ...$identity,
                    ...$attributes,
                    'authorization_version' => 1,
                ]);
            }

            foreach ($account->mailboxes as $remote) {
                $mailbox = Mailbox::query()->updateOrCreate(
                    [
                        'provider_connection_id' => $connection->id,
                        'provider_mailbox_id' => $remote->providerMailboxId,
                    ],
                    [
                        'user_id' => $user->id,
                        'workspace_id' => $workspace->id,
                        'email_address' => $remote->emailAddress,
                        'display_name' => $remote->displayName,
                        'status' => ConnectionStatus::Connected,
                        'provider_metadata' => $remote->metadata,
                        'last_sync_error' => null,
                    ],
                );

                $this->senderIdentities->sync(
                    $mailbox,
                    $remote->senderIdentities,
                    $provider === MailProviderName::Zoho,
                );

                foreach ([SyncScope::Inbox, SyncScope::Sent] as $scope) {
                    $folderId = $scope === SyncScope::Inbox
                        ? ($remote->metadata['inbox_id'] ?? null)
                        : ($remote->metadata['sent_id'] ?? null);
                    $folderId = is_scalar($folderId) ? (string) $folderId : null;

                    $state = MailboxSyncState::query()->firstOrCreate(
                        [
                            'mailbox_id' => $mailbox->id,
                            'scope' => $scope,
                        ],
                        [
                            'status' => SyncStatus::Idle,
                            'remote_folder_id' => $folderId,
                        ],
                    );

                    if ($folderId && $state->remote_folder_id !== $folderId) {
                        $state->forceFill(['remote_folder_id' => $folderId])->save();
                    }
                }

                $mailbox->forceFill(['sync_requested_at' => now()])->save();
                InitialSyncMailbox::dispatch($mailbox->id);
            }

            return $connection->fresh(['mailboxes']);
        });
    }
}
