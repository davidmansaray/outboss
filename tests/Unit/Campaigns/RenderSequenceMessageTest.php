<?php

use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Models\CampaignRecipient;
use App\Models\SequenceStep;

it('escapes values and prefixes test subjects', function () {
    $step = new SequenceStep([
        'subject_template' => 'Hello {{first_name}} at {{company}}',
        'body_template' => 'Hi {{first_name}}',
    ]);
    $recipient = new CampaignRecipient([
        'first_name' => '<Maya>',
        'company' => 'Stripe & Co',
    ]);

    $rendered = app(RenderSequenceMessage::class)->handle($step, $recipient, test: true);

    expect($rendered['subject'])->toBe('[TEST] Hello Jane at Acme')
        ->and($rendered['body'])->toBe('Hi Jane');
});

it('rejects unknown variables', function () {
    $step = new SequenceStep([
        'subject_template' => 'Hello {{unknown}}',
        'body_template' => 'Hi',
    ]);

    app(RenderSequenceMessage::class)->handle($step, new CampaignRecipient);
})->throws(InvalidArgumentException::class);

it('renders stable recipient variations without treating variable values as spintax', function () {
    $step = new SequenceStep([
        'position' => 1,
        'subject_spintax_enabled' => true,
        'body_spintax_enabled' => true,
        'subject_template' => '{Question|Quick question} for {{company}}',
        'body_template' => '{Hi|Hello} {{first_name}}, from {{company}}.',
    ]);
    $step->forceFill(['id' => 10]);
    $recipient = new CampaignRecipient([
        'campaign_id' => 7,
        'email_address' => 'maya@example.test',
        'email_normalized' => 'maya@example.test',
        'first_name' => 'Maya',
        'company' => '{Stripe|Adyen}',
    ]);
    $recipient->forceFill(['id' => 42]);

    $first = app(RenderSequenceMessage::class)->handle($step, $recipient);
    $second = app(RenderSequenceMessage::class)->handle($step, $recipient);

    expect($second)->toBe($first)
        ->and($first['body'])->toContain('{Stripe|Adyen}')
        ->and($first['body'])->not->toContain('{{first_name}}');
});

it('varies messages across recipients while keeping the step position stable across versions', function () {
    $draftStep = new SequenceStep([
        'position' => 1,
        'subject_spintax_enabled' => true,
        'body_spintax_enabled' => true,
        'subject_template' => '{Subject A|Subject B|Subject C}',
        'body_template' => '{Body A|Body B|Body C}',
    ]);
    $draftStep->forceFill(['id' => 10]);
    $publishedStep = clone $draftStep;
    $publishedStep->forceFill(['id' => 99]);

    $rendered = collect(range(1, 60))->map(function (int $id) use ($draftStep, $publishedStep): array {
        $recipient = new CampaignRecipient([
            'campaign_id' => 7,
            'email_address' => 'person-'.$id.'@example.test',
        ]);
        $recipient->forceFill(['id' => $id]);

        $draft = app(RenderSequenceMessage::class)->handle($draftStep, $recipient);
        $published = app(RenderSequenceMessage::class)->handle($publishedStep, $recipient);

        expect($published)->toBe($draft);

        return $draft;
    });

    expect($rendered->pluck('subject')->unique())->toHaveCount(3)
        ->and($rendered->pluck('body')->unique())->toHaveCount(3);
});

it('preserves braces and escapes exactly for templates created before variations were enabled', function () {
    $step = new SequenceStep([
        'position' => 1,
        'subject_spintax_enabled' => false,
        'body_spintax_enabled' => false,
        'subject_template' => 'Literal {A|B} and \\{escaped\\}',
        'body_template' => 'Hi {{first_name}} — keep {one|two} and \\| unchanged.',
    ]);
    $recipient = new CampaignRecipient(['first_name' => 'Maya']);

    $rendered = app(RenderSequenceMessage::class)->handle($step, $recipient);

    expect($rendered)->toBe([
        'subject' => 'Literal {A|B} and \\{escaped\\}',
        'body' => 'Hi Maya — keep {one|two} and \\| unchanged.',
    ]);
});
