<?php

use App\Domain\Campaigns\Services\SpintaxTemplate;

it('keeps each variation stable for the same seed', function () {
    $spintax = app(SpintaxTemplate::class);
    $template = '{Hi|Hello|Hey}, {quick question|one quick question}.';

    $first = $spintax->render($template, 'recipient-42');

    expect($spintax->render($template, 'recipient-42'))->toBe($first)
        ->and($spintax->render($template, 'recipient-42'))->toBe($first);
});

it('distributes choices across recipient seeds', function () {
    $spintax = app(SpintaxTemplate::class);

    $choices = collect(range(1, 60))
        ->map(fn (int $recipient): string => $spintax->render('{alpha|bravo|charlie}', 'recipient-'.$recipient))
        ->unique();

    expect($choices)->toHaveCount(3);
});

it('supports nested variations and escaped delimiters', function () {
    $spintax = app(SpintaxTemplate::class);

    $outputs = collect(range(1, 80))
        ->map(fn (int $seed): string => $spintax->render('{Hi|{Hello|Hey}} \\{literal\\} A\\|B', (string) $seed))
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($outputs)->toBe([
        'Hello {literal} A|B',
        'Hey {literal} A|B',
        'Hi {literal} A|B',
    ]);
});

it('distinguishes nested variations and merge variables that both use braces', function () {
    $spintax = app(SpintaxTemplate::class);

    $nested = collect(range(1, 120))
        ->map(fn (int $seed): string => $spintax->render('{{Hi|Hello}|Hey}', (string) $seed))
        ->unique()
        ->sort()
        ->values()
        ->all();
    $variableOption = collect(range(1, 60))
        ->map(fn (int $seed): string => $spintax->render('{{{first_name}}|there}', (string) $seed))
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($nested)->toBe(['Hello', 'Hey', 'Hi'])
        ->and($variableOption)->toBe(['there', '{{first_name}}']);
});

it('preserves legacy literal braces that are not variations', function () {
    $template = 'Use {X}, JSON {"enabled": true}, an unmatched {, and a closing }.';

    expect(app(SpintaxTemplate::class)->render($template, 'recipient-1'))->toBe($template);
});

it('handles brace-heavy literal input in bounded work', function () {
    $literal = str_repeat('{', 9000);

    expect(app(SpintaxTemplate::class)->render($literal, 'recipient-1'))->toBe($literal)
        ->and(fn () => app(SpintaxTemplate::class)->validate($literal.'|'))
        ->toThrow(
            InvalidArgumentException::class,
            'Templates cannot contain more than 256 variation delimiters.',
        );
});

it('rejects adversarially deep nesting before rendering', function () {
    $template = 'leaf';

    foreach (range(1, 21) as $level) {
        $template = '{option-'.$level.'|'.$template.'}';
    }

    expect(strlen($template))->toBeLessThan(10000)
        ->and(fn () => app(SpintaxTemplate::class)->validate($template))
        ->toThrow(InvalidArgumentException::class, 'Variations cannot be nested more than 20 levels.');
});

it('rejects malformed variations', function (string $template, string $message) {
    expect(fn () => app(SpintaxTemplate::class)->validate($template))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['{one|two', 'Unclosed variation'],
    ['{one|}', 'cannot be empty'],
]);
