<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Prospecting\Enums\CanonicalFieldDecision;
use App\Models\CanonicalFieldProvenance;
use App\Models\Person;
use App\Models\SourceObservation;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MergePersonObservation
{
    /** @param array<string,mixed> $values */
    public function handle(
        Person $person,
        SourceObservation $observation,
        array $values,
        ?User $actor = null,
        bool $allowUnprovenancedUpdates = false,
    ): Person {
        $fields = [
            'email_address' => $values['email_address'] ?? ($values['email'] ?? null),
            'email_normalized' => $values['email_normalized'] ?? null,
            'full_name' => $values['full_name'] ?? ($values['name'] ?? null),
            'first_name' => $values['first_name'] ?? null,
            'job_title' => $values['job_title'] ?? null,
            'notes' => $values['notes'] ?? null,
            'tags' => $values['tags'] ?? null,
        ];
        $email = trim((string) $fields['email_address']);
        if ($email !== '') {
            if (! EmailNormalizer::isValid($email)) {
                throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
            }
            $fields['email_address'] = $email;
            $fields['email_normalized'] = EmailNormalizer::normalize($email);
        } else {
            $fields['email_address'] = null;
            $fields['email_normalized'] = null;
        }
        $fields['full_name'] = $this->nullableString($fields['full_name']);
        $fields['first_name'] = $this->nullableString($fields['first_name'])
            ?? ($fields['full_name'] === null ? null : EmailNormalizer::firstName($fields['full_name']));
        $fields['job_title'] = $this->nullableString($fields['job_title']);
        $fields['notes'] = $this->nullableString($fields['notes']);
        $fields['tags'] = $this->tags($fields['tags']);

        if (array_key_exists('account_id', $values) && $values['account_id'] !== null && $values['account_id'] !== '') {
            $accountId = (int) $values['account_id'];
            if ((int) $person->account_id !== 0 && (int) $person->account_id !== $accountId) {
                $fields['account_id'] = null;
            } elseif ($person->account_id === null) {
                $fields['account_id'] = $accountId;
            }
        }

        $changes = [];
        foreach ($fields as $field => $incoming) {
            if ($incoming === null || ($field === 'tags' && $incoming === [])) {
                continue;
            }
            $current = $person->getAttribute($field);
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
                if ($allowUnprovenancedUpdates
                    && in_array($field, ['full_name', 'first_name', 'job_title'], true)
                    && ! $this->isUserMaintained($person, $field)) {
                    $next = $incoming;
                    $decision = CanonicalFieldDecision::Applied;
                } else {
                    $decision = CanonicalFieldDecision::KeptExisting;
                }
            }
            if ($decision === CanonicalFieldDecision::Applied) {
                $person->setAttribute($field, $next);
            }
            $changes[] = [$field, $current, $next, $decision];
        }
        if ($person->isDirty()) {
            $person->save();
        }
        $this->recordProvenance($person, $observation, $changes, $actor);

        return $person->fresh();
    }

    /** @param array<string,mixed> $values */
    public function merge(
        Person $person,
        SourceObservation $observation,
        array $values,
        ?User $actor = null,
        bool $allowUnprovenancedUpdates = false,
    ): Person {
        return $this->handle($person, $observation, $values, $actor, $allowUnprovenancedUpdates);
    }

    private function isUserMaintained(Person $person, string $field): bool
    {
        if ($person->source === Person::SOURCE_MANUAL && $person->created_by_user_id !== null) {
            return true;
        }

        return $person->fieldProvenance()
            ->where('field_path', $field)
            ->where('actor_type', 'user')
            ->where('decision', CanonicalFieldDecision::Applied->value)
            ->exists();
    }

    /** @param list<array{0:string,1:mixed,2:mixed,3:CanonicalFieldDecision}> $changes */
    private function recordProvenance(Person $person, SourceObservation $observation, array $changes, ?User $actor): void
    {
        foreach ($changes as [$field, $prior, $new, $decision]) {
            CanonicalFieldProvenance::query()->create([
                'public_id' => (string) Str::uuid(),
                'workspace_id' => $person->workspace_id,
                'entity_type' => 'person',
                'account_id' => null,
                'person_id' => $person->id,
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
