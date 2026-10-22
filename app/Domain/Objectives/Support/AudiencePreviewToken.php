<?php

namespace App\Domain\Objectives\Support;

use Illuminate\Validation\ValidationException;

final class AudiencePreviewToken
{
    public function issue(int $workspaceId, string $segmentVersionId, string $evaluationHash): string
    {
        $payload = CanonicalObjectivePayload::encode([
            'evaluation_hash' => $evaluationHash,
            'expires_at' => now()->addMinutes((int) config('objectives.snapshot_preview_ttl_minutes', 15))->getTimestamp(),
            'segment_version_id' => $segmentVersionId,
            'workspace_id' => $workspaceId,
        ]);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    public function assertValid(string $token, int $workspaceId, string $segmentVersionId, string $evaluationHash): void
    {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($encoded === '' || ! hash_equals(hash_hmac('sha256', $encoded, (string) config('app.key')), $signature)) {
            $this->invalid();
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($payload)
            || (int) ($payload['workspace_id'] ?? 0) !== $workspaceId
            || ($payload['segment_version_id'] ?? null) !== $segmentVersionId
            || ! hash_equals((string) ($payload['evaluation_hash'] ?? ''), $evaluationHash)
            || (int) ($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['preview_token' => 'The audience preview is invalid or expired.']);
    }
}
