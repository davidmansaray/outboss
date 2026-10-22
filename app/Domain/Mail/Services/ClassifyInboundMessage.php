<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\Enums\BounceSubtype;
use App\Domain\Mail\Enums\ThreadClassification;
use Illuminate\Support\Collection;

class ClassifyInboundMessage
{
    /**
     * @return array{classification: ThreadClassification, bounce_subtype: BounceSubtype|null, confidence: float}
     */
    public function handle(RemoteMessage $message): array
    {
        $subject = strtolower($message->subject);
        $text = strtolower($message->bodyText);
        $from = strtolower((string) $message->fromEmail);
        $headers = collect($message->headers)->mapWithKeys(fn ($value, $key) => [strtolower((string) $key) => strtolower((string) $value)]);

        if ($this->isBounce($from, $headers, $text, $subject)) {
            $subtype = str_contains($text, '5.1.1') || str_contains($text, 'user unknown') || str_contains($text, 'permanent')
                ? BounceSubtype::Hard
                : (str_contains($text, '4.') || str_contains($text, 'mailbox full') ? BounceSubtype::Soft : BounceSubtype::Unknown);

            return [
                'classification' => ThreadClassification::Bounce,
                'bounce_subtype' => $subtype,
                'confidence' => 0.9,
            ];
        }

        if ($this->isAutomatedReply($headers, $subject, $text)) {
            return [
                'classification' => ThreadClassification::OutOfOffice,
                'bounce_subtype' => null,
                'confidence' => 0.8,
            ];
        }

        if ($this->isUnsubscribe($text, $subject)) {
            return [
                'classification' => ThreadClassification::Unsubscribe,
                'bounce_subtype' => null,
                'confidence' => 0.85,
            ];
        }

        return [
            'classification' => ThreadClassification::Unknown,
            'bounce_subtype' => null,
            'confidence' => 0.4,
        ];
    }

    /**
     * @param  Collection<string, lowercase-string>  $headers
     */
    private function isBounce(string $from, $headers, string $text, string $subject): bool
    {
        return str_contains($from, 'mailer-daemon')
            || str_contains($from, 'postmaster')
            || $headers->has('x-failed-recipients')
            || str_contains($subject, 'undeliverable')
            || str_contains($subject, 'delivery status notification')
            || str_contains($text, 'diagnostic-code');
    }

    /**
     * @param  Collection<string, lowercase-string>  $headers
     */
    private function isAutomatedReply($headers, string $subject, string $text): bool
    {
        $autoSubmitted = trim((string) $headers->get('auto-submitted'));

        if (($autoSubmitted !== '' && $autoSubmitted !== 'no')
            || $headers->has('x-autoreply')
            || $headers->has('x-autorespond')
        ) {
            return true;
        }

        foreach ([
            'out of office',
            'automatic reply',
            'automated reply',
            'automated response',
            'auto reply',
            'auto-reply',
            'autoreply',
            'risposta automatica',
            'réponse automatique',
            'respuesta automática',
            'resposta automática',
            'automatische antwort',
            'automatisch antwoord',
        ] as $pattern) {
            if (str_contains($subject, $pattern)) {
                return true;
            }
        }

        return str_contains($text, 'i am out of the office')
            || str_contains($text, 'this is an automated reply')
            || str_contains($text, 'this is an automated response')
            || str_contains($text, 'we carefully review all messages received and will respond');
    }

    private function isUnsubscribe(string $text, string $subject): bool
    {
        $haystack = trim($text !== '' ? $text : $subject);
        $patterns = ['unsubscribe', 'remove me', 'stop emailing me'];

        foreach ($patterns as $pattern) {
            if ($haystack === $pattern || str_starts_with($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
