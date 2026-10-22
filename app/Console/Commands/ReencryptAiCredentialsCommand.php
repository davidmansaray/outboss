<?php

namespace App\Console\Commands;

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Services\AiEventRecorder;
use App\Models\AiProviderCredentialVersion;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ReencryptAiCredentialsCommand extends Command
{
    protected $signature = 'ai:reencrypt-credentials {--batch=100}';

    protected $description = 'Re-encrypt AI provider credentials with the active application key';

    public function handle(AiEventRecorder $events): int
    {
        $processed = 0;
        $failed = 0;
        $batch = max(1, min(1000, (int) $this->option('batch')));

        AiProviderCredentialVersion::query()
            ->whereNotNull('encrypted_secret')
            ->orderBy('id')
            ->chunkById($batch, function ($versions) use (&$processed, &$failed, $events): void {
                foreach ($versions as $version) {
                    try {
                        $secret = $version->encrypted_secret;
                        if ($secret === null) {
                            continue;
                        }
                        DB::table('ai_provider_credential_versions')
                            ->where('id', $version->id)
                            ->update([
                                'encrypted_secret' => Crypt::encryptString($secret),
                                'updated_at' => now(),
                            ]);
                        $processed++;
                    } catch (DecryptException) {
                        $credential = $version->credential()->first();
                        if ($credential !== null) {
                            $credential->forceFill([
                                'status' => AiCredentialStatus::Misconfigured,
                                'last_error_code' => 'credential_decrypt_failed',
                                'last_error_message' => 'The stored credential could not be decrypted with the configured keyring.',
                            ])->save();
                            $workspace = $credential->workspace()->first();
                            if ($workspace !== null) {
                                $events->record($workspace, 'health_changed', credential: $credential, version: $version, toState: 'misconfigured');
                            }
                        }
                        $failed++;
                    }
                }
            });

        $this->info("Re-encrypted {$processed} credential version(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
