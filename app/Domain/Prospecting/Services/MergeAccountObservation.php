<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\People\Support\DomainNormalizer;
use App\Domain\Prospecting\Enums\CanonicalFieldDecision;
use App\Models\Account;
use App\Models\CanonicalFieldProvenance;
use App\Models\SourceObservation;
use App\Models\User;
use Illuminate\Support\Str;

final class MergeAccountObservation
{
    /** @param array<string,mixed> $values */
    public function handle(Account $account, SourceObservation $observation, array $values, ?User $actor = null): Account
    {
        $fields = [
            'name' => $values['name'] ?? null,
            'website' => $values['website'] ?? null,
            'domain_normalized' => $values['domain_normalized'] ?? ($values['domain'] ?? null),
            'notes' => $values['notes'] ?? null,
            'tags' => $values['tags'] ?? null,
        ];
        if ($fields['website'] !== null) {
            $fields['website'] = $this->nullableString($fields['website']);
        }
        $fields['domain_normalized'] = DomainNormalizer::normalize(
            $fields['domain_normalized'] !== null ? (string) $fields['domain_normalized'] : ($fields['website'] !== null ? (string) $fields['website'] : null),
        );
        $fields['name'] = $this->nullableString($fields['name']);
        $fields['notes'] = $this->nullableString($fields['notes']);
        $fields['tags'] = $this->tags($fields['tags']);

        $changes = [];
        foreach ($fields as $field => $incoming) {
            if ($incoming === null || ($field === 'tags' && $incoming === [])) {
                continue;
            }
            $current = $account->getAttribute($field);
            $decision = CanonicalFieldDecision::KeptExisting;
            $next = $current;
            if ($field === 'tags') {
                $incomingTags = $this->tags($incoming);
                $currentTags = $this->tags($current);
                $next = $this->unionTags($currentTags, $incomingTags);
                $decision = $next !== $currentTags
                    ? CanonicalFieldDecision::Applied
                    : CanonicalFieldDecision::KeptExisting;
            } elseif ($current === null || $current === '') {
                $next = $incoming;
                $decision = CanonicalFieldDecision::Applied;
            } elseif (is_scalar($current) && is_scalar($incoming) && (string) $current !== (string) $incoming) {
                $decision = CanonicalFieldDecision::KeptExisting;
            }
            if ($decision === CanonicalFieldDecision::Applied) {
                $account->setAttribute($field, $next);
            }
            $changes[] = [$field, $current, $next, $decision];
        }
        if ($account->isDirty()) {
            $account->save();
        }
        $this->recordProvenance($account, $observation, $changes, $actor);

        return $account->fresh();
    }

    /** @param array<string,mixed> $values */
    public function merge(Account $account, SourceObservation $observation, array $values, ?User $actor = null): Account
    {
        return $this->handle($account, $observation, $values, $actor);
    }

    /** @param list<array{0:string,1:mixed,2:mixed,3:CanonicalFieldDecision}> $changes */
    private function recordProvenance(Account $account, SourceObservation $observation, array $changes, ?User $actor): void
    {
        foreach ($changes as [$field, $prior, $new, $decision]) {
            CanonicalFieldProvenance::query()->create([
                'public_id' => (string) Str::uuid(),
                'workspace_id' => $account->workspace_id,
                'entity_type' => 'account',
                'account_id' => $account->id,
                'person_id' => null,
                'field_path' => $field,
                'source_observation_id' => $observation->id,
                'source_run_id' => $observation->source_run_id,
                'decision' => $decision,
                'prior_value_hash' => $this->valueHash($prior),
                'new_value_hash' => $this->valueHash($new),
                'actor_type' => $actor === null ? 'system' : 'user',
                'actor_user_id' => $actor?->id,
                'actor_display_name' => $actor?->name,
                'created_at' => now(),
            ]);
        }
    }

    /** @return list<string> */
    private function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $result = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && ! in_array($tag, $result, true)) {
                $result[] = mb_strcut($tag, 0, 120, 'UTF-8');
            }
            if (count($result) === 20) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $existing
     * @param  array<array-key, mixed>  $incoming
     * @return list<string>
     */
    private function unionTags(array $existing, array $incoming): array
    {
        return $this->tags(array_merge($existing, $incoming));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_strcut($value, 0, 5000, 'UTF-8');
    }

    private function valueHash(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash('sha256', is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR));
    }
}
