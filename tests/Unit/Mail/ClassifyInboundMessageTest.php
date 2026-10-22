<?php

use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\Enums\BounceSubtype;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Services\ClassifyInboundMessage;

function remote(string $subject, string $body, string $from = 'maya@stripe.com', array $headers = [], array $metadata = []): RemoteMessage
{
    return new RemoteMessage(
        remoteMessageId: 'm-1',
        remoteThreadId: 't-1',
        direction: MessageDirection::Inbound,
        subject: $subject,
        bodyText: $body,
        bodyHtml: $body,
        fromEmail: $from,
        fromName: $from,
        headers: $headers,
        metadata: $metadata,
    );
}

it('classifies bounce, out-of-office, and unsubscribe messages', function () {
    $classifier = app(ClassifyInboundMessage::class);

    expect($classifier->handle(remote('Undeliverable', 'Diagnostic-Code: smtp; 5.1.1 user unknown', 'mailer-daemon@google.com'))['classification'])
        ->toBe(ThreadClassification::Bounce)
        ->and($classifier->handle(remote('Undeliverable', 'Diagnostic-Code: smtp; 5.1.1 user unknown', 'mailer-daemon@google.com'))['bounce_subtype'])
        ->toBe(BounceSubtype::Hard)
        ->and($classifier->handle(remote('Out of office', 'I am away', 'maya@stripe.com', ['Auto-Submitted' => 'auto-replied']))['classification'])
        ->toBe(ThreadClassification::OutOfOffice)
        ->and($classifier->handle(remote('Please unsubscribe', 'unsubscribe'))['classification'])
        ->toBe(ThreadClassification::Unsubscribe)
        ->and($classifier->handle(remote('Could you remove me from this list when you have a moment?', 'longer ambiguous text'))['classification'])
        ->toBe(ThreadClassification::Unknown);
});

it('does not classify ordinary inbound mail as human', function () {
    $result = app(ClassifyInboundMessage::class)->handle(remote(
        'Re: hiring',
        'Yes, let’s talk',
        metadata: ['campaign_linked' => true],
    ));

    expect($result['classification'])->toBe(ThreadClassification::Unknown);
});

it('classifies the production automatic replies that were incorrectly treated as leads', function (string $subject, string $body) {
    $result = app(ClassifyInboundMessage::class)->handle(remote($subject, $body));

    expect($result['classification'])->toBe(ThreadClassification::OutOfOffice)
        ->and($result['confidence'])->toBe(0.8);
})->with([
    'Toplife Italian automatic reply' => [
        'risposta automatica',
        'Buongiorno se ha scritto a questa mail per problemi tecnici o relativi a qualsiasi domande sui prodotti/servizi sul sito Toplifeproject.com non avrà alcuna risposta ma deve scrivere a support@toplifeproject.com.',
    ],
    'TOTM automatic acknowledgement' => [
        'Re: Is checkout working on TOTM?',
        "-- Please reply above this line --\n\nHello David,\n\nThank you for emailing TOTM.\n\nWe carefully review all messages received and will respond if your inquiry is something we can help with.",
    ],
]);

it('uses any non-no Auto-Submitted value as an automatic reply signal', function (string $value) {
    $result = app(ClassifyInboundMessage::class)->handle(remote(
        'Re: hiring',
        'Thanks for your note.',
        headers: ['Auto-Submitted' => $value],
    ));

    expect($result['classification'])->toBe(ThreadClassification::OutOfOffice);
})->with(['auto-replied', 'auto-generated', 'auto-replied; owner-email=office@example.com']);

it('does not treat an explicit Auto-Submitted no header as automatic', function () {
    $result = app(ClassifyInboundMessage::class)->handle(remote(
        'Re: hiring',
        'Yes, let\'s talk.',
        headers: ['Auto-Submitted' => 'no'],
    ));

    expect($result['classification'])->toBe(ThreadClassification::Unknown);
});
