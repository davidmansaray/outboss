<?php

namespace App\Domain\Mail\Support;

class MessageIdNormalizer
{
    public static function normalize(?string $messageId): ?string
    {
        $messageId = trim((string) $messageId);

        if ($messageId === '') {
            return null;
        }

        if (str_starts_with($messageId, '<') && str_ends_with($messageId, '>')) {
            $messageId = trim(substr($messageId, 1, -1));
        }

        return $messageId === '' ? null : strtolower($messageId);
    }

    /**
     * @param  array<array-key, string|null>  $messageIds
     * @return list<string>
     */
    public static function normalizeList(array $messageIds): array
    {
        $normalized = [];

        foreach ($messageIds as $messageId) {
            $messageId = self::normalize($messageId);

            if ($messageId !== null) {
                $normalized[$messageId] = $messageId;
            }
        }

        return array_values($normalized);
    }
}
