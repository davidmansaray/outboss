<?php

namespace App\Domain\Objectives\Support;

use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use Illuminate\Support\Str;

/** Exact human copy and provenance. Semantic claims still require owner approval. */
final class HumanMessageContract
{
    public const VERSION = 'human_message_contract_v1';

    public function __construct(private readonly RenderSequenceMessage $renderer) {}

    /**
     * The caller owns tenant/readiness/evidence checks. This boundary validates
     * bytes, placeholders and destinations, and binds the resulting hashes to
     * the exact provenance supplied by that checked context.
     *
     * @param  array{first_name:string,company:string}  $recipient
     * @param  array{composition_id:string,goal_version_id:string,offer_version_id:string,offer_content_hash:string,author_id:int,evidence_manifest_hash:string}  $provenance
     * @return array{subject:string,body:string,template_hash:string,content_hash:string,provenance_hash:string}
     */
    public function build(string $subjectTemplate, string $bodyTemplate, array $recipient, string $destination, array $provenance): array
    {
        foreach (['composition_id', 'goal_version_id', 'offer_version_id'] as $field) {
            if (! Str::isUuid($provenance[$field])) {
                $this->reject('Human message provenance requires exact version identities.');
            }
        }
        foreach (['offer_content_hash', 'evidence_manifest_hash'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', $provenance[$field]) !== 1) {
                $this->reject('Human message provenance requires canonical content hashes.');
            }
        }
        if ($provenance['author_id'] < 1) {
            $this->reject('Human message provenance requires one identified author.');
        }
        if (filter_var($destination, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($destination, PHP_URL_SCHEME)) !== 'https'
            || parse_url($destination, PHP_URL_USER) !== null || parse_url($destination, PHP_URL_PASS) !== null
            || preg_match('/[\x00-\x20\x7F{}]/', $destination) === 1 || strlen($destination) > 2048) {
            $this->reject('The Offer destination must be an exact safe HTTPS URL.');
        }
        $this->assertBytes($subjectTemplate, $bodyTemplate);
        foreach ([$subjectTemplate, $bodyTemplate] as $template) {
            // Do not resolve variations and then pretend those bytes were authored.
            $plain = preg_replace('/\{\{(?:first_name|company)\}\}/', '', $template);
            if ($plain === null || str_contains($plain, '{') || str_contains($plain, '}')) {
                $this->reject('Use only {{first_name}} and {{company}}; message variations are not supported.');
            }
        }
        foreach ($recipient as $value) {
            if (trim($value) === '' || mb_strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F{}<>]/u', $value) === 1) {
                $this->reject('Recipient personalization contains unsafe or empty text.');
            }
        }
        $rendered = $this->renderer->previewTemplates($subjectTemplate, $bodyTemplate, $recipient);
        $this->assertBytes($rendered['subject'], $rendered['body']);
        if (preg_match_all('~(?:(?:https?|ftp)://|(?:mailto|data|javascript):|www\.)[^\s<>"\']+~iu', $rendered['subject']."\n".$rendered['body'], $matches) === false) {
            $this->reject('The message contains invalid URL text.');
        }
        foreach ($matches[0] as $url) {
            if (! hash_equals($destination, $url)) {
                $this->reject('The message contains a link outside the approved Offer destination.');
            }
        }
        if (str_contains($rendered['subject'], $destination) || substr_count($rendered['body'], $destination) > 1) {
            $this->reject('Use the approved destination at most once, in the message body.');
        }
        $templateHash = CanonicalObjectivePayload::hash([
            'version' => self::VERSION, 'subject_template' => $subjectTemplate, 'body_template' => $bodyTemplate,
            'destination' => $destination,
        ]);
        $contentHash = CanonicalObjectivePayload::hash([
            'subject' => $rendered['subject'], 'body_text' => $rendered['body'], 'body_html' => null,
        ]);

        return [...$rendered, 'template_hash' => $templateHash, 'content_hash' => $contentHash,
            'provenance_hash' => CanonicalObjectivePayload::hash([
                'version' => self::VERSION, ...$provenance, 'template_hash' => $templateHash, 'content_hash' => $contentHash,
            ])];
    }

    private function assertBytes(string $subject, string $body): void
    {
        if (trim($subject) === '' || mb_strlen($subject) > 160 || preg_match('/[\x00-\x1F\x7F]/u', $subject) !== 0
            || trim($body) === '' || mb_strlen($body) > 12000 || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/u', $body) !== 0
            || preg_match('/<[^>]+>/s', $subject."\n".$body) === 1
            || preg_match('/^(?:bcc|cc|to|from|reply-to|content-type|mime-version):/im', $subject."\n".$body) === 1) {
            $this->reject('Use safe plain-text content without headers or control characters.');
        }
    }

    private function reject(string $message): never
    {
        throw new DraftMessageValidationException($message);
    }
}
