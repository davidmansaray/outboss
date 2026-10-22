<?php

namespace App\Domain\Mail\Support;

class EmailNormalizer
{
    public static function normalize(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function isValid(?string $email): bool
    {
        $email = trim((string) $email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function domain(?string $email): ?string
    {
        $normalized = self::normalize($email);
        $at = strrpos($normalized, '@');

        if ($at === false) {
            return null;
        }

        $domain = substr($normalized, $at + 1);

        return $domain !== '' ? $domain : null;
    }

    public static function firstName(?string $fullName): ?string
    {
        $fullName = trim((string) $fullName);

        if ($fullName === '') {
            return null;
        }

        return explode(' ', $fullName)[0];
    }

    public static function stripHeaderInjection(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    public static function normalizeSubject(string $subject): string
    {
        $subject = strtolower(trim($subject));
        $subject = preg_replace('/^(re|fwd|fw)\s*:\s*/i', '', $subject) ?: $subject;

        return trim($subject);
    }

    public static function htmlToText(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
