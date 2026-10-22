<?php

use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('keeps denormalized records in their parent workspace', function () {
    $recipient = CampaignRecipient::factory()->create();
    $message = Message::factory()->create();

    expect($recipient->workspace_id)->toBe($recipient->campaign->workspace_id)
        ->and($message->workspace_id)->toBe($message->mailbox->workspace_id)
        ->and($message->thread->workspace_id)->toBe($message->workspace_id);
});

it('allows the same suppression address in different workspaces but not twice in one', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $firstWorkspace = $firstUser->currentWorkspace();
    $secondWorkspace = $secondUser->currentWorkspace();
    $attributes = [
        'email_address' => 'same@example.test',
        'email_normalized' => 'same@example.test',
        'reason' => 'manual',
    ];

    SuppressionEntry::factory()->create([
        ...$attributes,
        'user_id' => $firstUser->id,
        'workspace_id' => $firstWorkspace->id,
    ]);
    SuppressionEntry::factory()->create([
        ...$attributes,
        'user_id' => $secondUser->id,
        'workspace_id' => $secondWorkspace->id,
    ]);

    expect(SuppressionEntry::query()
        ->where('email_normalized', 'same@example.test')
        ->count())->toBe(2)
        ->and(fn () => SuppressionEntry::factory()->create([
            ...$attributes,
            'user_id' => $firstUser->id,
            'workspace_id' => $firstWorkspace->id,
        ]))->toThrow(QueryException::class);
});

it('enforces workspace columns as non-null on scoped tables', function () {
    foreach ([
        'provider_connections',
        'mailboxes',
        'campaigns',
        'campaign_recipients',
        'recipient_imports',
        'threads',
        'messages',
        'send_attempts',
        'suppression_entries',
    ] as $table) {
        expect(Schema::getColumnType($table, 'workspace_id'))->toBe('integer');
        $workspaceColumn = collect(Schema::getColumns($table))->firstWhere('name', 'workspace_id');
        expect($workspaceColumn['nullable'] ?? true)->toBeFalse();
    }

    expect(Schema::hasTable('workspaces'))->toBeTrue()
        ->and(Schema::hasTable('workspace_memberships'))->toBeTrue();
});
