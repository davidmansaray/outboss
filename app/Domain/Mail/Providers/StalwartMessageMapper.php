<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Mail\Support\MessageIdNormalizer;
use Carbon\CarbonImmutable;

/** Maps RFC 8621 Email objects to Outboss's provider-neutral message DTO. */
final class StalwartMessageMapper
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload, SyncScope|string $scope, ?string $mailboxAddress = null): RemoteMessage
    {
        $scope = $scope instanceof SyncScope ? $scope : SyncScope::from($scope);
        $from = $this->firstAddress($payload['from'] ?? $payload['sender'] ?? null);
        $ownAddress = EmailNormalizer::normalize($mailboxAddress);
        $fromAddress = EmailNormalizer::normalize($from['email'] ?? null);
        $direction = $scope === SyncScope::Sent || ($ownAddress !== '' && $ownAddress === $fromAddress)
            ? MessageDirection::Outbound
            : MessageDirection::Inbound;
        $headers = $this->headers($payload);
        $messageIds = $this->messageIds($payload['messageId'] ?? null, $headers['message-id'] ?? null);
        $inReplyTo = $this->messageIds($payload['inReplyTo'] ?? null, $headers['in-reply-to'] ?? null)[0] ?? null;
        $references = $this->messageIds($payload['references'] ?? null, $headers['references'] ?? null);
        $body = $this->body($payload);
        $occurredAt = $this->date($payload['receivedAt'] ?? $payload['sentAt'] ?? null);

        return new RemoteMessage(
            remoteMessageId: (string) ($payload['id'] ?? ''),
            remoteThreadId: $this->nullableString($payload['threadId'] ?? null),
            direction: $direction,
            subject: (string) ($payload['subject'] ?? ''),
            bodyText: $body['text'],
            bodyHtml: $body['html'],
            fromEmail: $from['email'],
            fromName: $from['name'] ?? null,
            to: $this->addresses($payload['to'] ?? null),
            cc: $this->addresses($payload['cc'] ?? null),
            bcc: $this->addresses($payload['bcc'] ?? null),
            internetMessageId: $messageIds[0] ?? null,
            inReplyTo: $inReplyTo,
            references: $references,
            occurredAt: $occurredAt,
            hasAttachments: (bool) ($payload['hasAttachment'] ?? (($payload['attachments'] ?? []) !== [])),
            headers: $headers,
            metadata: [
                'mailbox_ids' => $payload['mailboxIds'] ?? [],
                'keywords' => $payload['keywords'] ?? [],
                'blob_id' => $payload['blobId'] ?? null,
                'size' => $payload['size'] ?? null,
                'client_marker' => $this->headerValue($payload, 'X-Outboss-Message-Id'),
            ],
            deleted: (bool) ($payload['deleted'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function headers(array $payload): array
    {
        $headers = [];

        if (is_array($payload['headers'] ?? null)) {
            foreach ($payload['headers'] as $header) {
                if (! is_array($header) || ! is_string($header['name'] ?? null) || ! is_string($header['value'] ?? null)) {
                    continue;
                }

                $headers[strtolower($header['name'])] = $header['value'];
            }
        }

        foreach ($payload as $key => $value) {
            if (! str_starts_with(strtolower($key), 'header:')) {
                continue;
            }

            $name = strtolower(substr($key, 7));
            $name = preg_replace('/:as[a-z]+(?::all)?$/i', '', $name) ?: $name;

            if (is_string($value)) {
                $headers[$name] = $value;
            } elseif (is_array($value)) {
                $last = end($value);
                if (is_string($last)) {
                    $headers[$name] = $last;
                }
            }
        }

        return $headers;
    }

    /** @param array<string, mixed> $payload */
    private function headerValue(array $payload, string $name): ?string
    {
        foreach ($payload as $key => $value) {
            if (! str_starts_with(strtolower($key), 'header:')) {
                continue;
            }

            $headerName = substr($key, 7);
            $headerName = preg_replace('/:as[a-z]+(?::all)?$/i', '', $headerName) ?: $headerName;

            if (strcasecmp($headerName, $name) !== 0) {
                continue;
            }

            if (is_string($value)) {
                return trim($value);
            }

            if (is_array($value)) {
                foreach (array_reverse($value) as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        return trim($item);
                    }
                }
            }
        }

        foreach (($payload['headers'] ?? []) as $header) {
            if (is_array($header) && strcasecmp((string) ($header['name'] ?? ''), $name) === 0) {
                return is_string($header['value'] ?? null) ? trim($header['value']) : null;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function messageIds(mixed $value, mixed $fallback = null): array
    {
        $values = [];

        foreach ([$value, $fallback] as $candidate) {
            if (is_string($candidate)) {
                preg_match_all('/<([^>]+)>/', $candidate, $matches);
                $values = [...$values, ...$matches[1]];

                if ($matches[1] === []) {
                    $values[] = trim($candidate, " <>\t\r\n");
                }
            } elseif (is_array($candidate)) {
                foreach ($candidate as $item) {
                    if (is_string($item)) {
                        $values[] = $item;
                    }
                }
            }
        }

        return MessageIdNormalizer::normalizeList($values);
    }

    /** @return list<array{email:string,name?:string|null}> */
    private function addresses(mixed $value): array
    {
        if (is_string($value)) {
            $items = preg_split('/\s*,\s*/', $value) ?: [];
            $result = [];

            foreach ($items as $item) {
                $address = $this->parseAddress($item);
                if ($address !== null) {
                    $result[] = $address;
                }
            }

            return $result;
        }

        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $address = $this->parseAddress($item);
            } elseif (is_array($item) && is_string($item['email'] ?? null)) {
                $address = [
                    'email' => (string) $item['email'],
                    'name' => array_key_exists('name', $item) && (is_string($item['name']) || $item['name'] === null)
                        ? $item['name']
                        : null,
                ];
            } else {
                $address = null;
            }

            if ($address !== null) {
                $result[] = $address;
            }
        }

        return $result;
    }

    /** @return array{email:string,name:?string}|null */
    private function parseAddress(string $value): ?array
    {
        if (preg_match('/^(?:"?([^"<]*)"?\s*)?<([^>]+)>$/', trim($value), $matches) === 1) {
            return ['email' => trim($matches[2]), 'name' => trim($matches[1]) !== '' ? trim($matches[1]) : null];
        }

        return filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false
            ? ['email' => trim($value), 'name' => null]
            : null;
    }

    /** @return array{email:?string,name:?string} */
    private function firstAddress(mixed $value): array
    {
        $addresses = $this->addresses($value);

        if (! isset($addresses[0])) {
            return ['email' => null, 'name' => null];
        }

        return [
            'email' => $addresses[0]['email'],
            'name' => $addresses[0]['name'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{text:string,html:?string}
     */
    private function body(array $payload): array
    {
        $text = is_string($payload['bodyText'] ?? null) ? (string) $payload['bodyText'] : '';
        $html = is_string($payload['bodyHtml'] ?? null) ? (string) $payload['bodyHtml'] : null;
        $bodyValues = is_array($payload['bodyValues'] ?? null) ? $payload['bodyValues'] : [];

        foreach (is_array($payload['textBody'] ?? null) ? $payload['textBody'] : [] as $part) {
            if (! is_array($part)) {
                continue;
            }

            $partId = $part['partId'] ?? null;
            $value = is_string($partId) ? data_get($bodyValues, $partId.'.value') : null;
            if (is_string($value) && $text === '') {
                $text = $value;
            }
        }

        foreach (is_array($payload['htmlBody'] ?? null) ? $payload['htmlBody'] : [] as $part) {
            if (! is_array($part)) {
                continue;
            }

            $partId = $part['partId'] ?? null;
            $value = is_string($partId) ? data_get($bodyValues, $partId.'.value') : null;
            if (is_string($value) && ($html === null || $html === '')) {
                $html = $value;
            }
        }

        if ($text === '' && $html !== null) {
            $text = EmailNormalizer::htmlToText($html);
        }

        if ($text === '' && is_string($payload['preview'] ?? null)) {
            $text = (string) $payload['preview'];
        }

        return ['text' => $text, 'html' => $html];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
