<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const USER_SCOPED_TABLES = [
        'provider_connections',
        'mailboxes',
        'campaigns',
        'recipient_imports',
        'threads',
        'send_attempts',
        'suppression_entries',
    ];

    /** @var list<string> */
    private const SCOPED_TABLES = [
        'provider_connections',
        'mailboxes',
        'campaigns',
        'campaign_recipients',
        'recipient_imports',
        'threads',
        'messages',
        'send_attempts',
        'suppression_entries',
    ];

    public function up(): void
    {
        $this->provisionPersonalWorkspaces();

        foreach (self::USER_SCOPED_TABLES as $tableName) {
            DB::table($tableName)
                ->whereNull('workspace_id')
                ->update([
                    'workspace_id' => DB::raw("(
                        select wm.workspace_id
                        from workspace_memberships wm
                        where wm.user_id = {$tableName}.user_id
                        order by wm.id
                        limit 1
                    )"),
                ]);
        }

        DB::table('campaign_recipients')
            ->whereNull('workspace_id')
            ->update([
                'workspace_id' => DB::raw('(
                    select campaigns.workspace_id
                    from campaigns
                    where campaigns.id = campaign_recipients.campaign_id
                    limit 1
                )'),
            ]);

        DB::table('messages')
            ->whereNull('workspace_id')
            ->update([
                'workspace_id' => DB::raw('(
                    select mailboxes.workspace_id
                    from mailboxes
                    where mailboxes.id = messages.mailbox_id
                    limit 1
                )'),
            ]);

        foreach (self::SCOPED_TABLES as $tableName) {
            if (DB::table($tableName)->whereNull('workspace_id')->exists()) {
                throw new RuntimeException("Unable to backfill workspace_id on {$tableName}.");
            }
        }

        $this->assertWorkspaceConsistency();
    }

    public function down(): void
    {
        // The workspace IDs are intentionally retained until the preceding
        // migration removes the columns during rollback.
    }

    private function provisionPersonalWorkspaces(): void
    {
        $now = now();

        DB::table('users')->orderBy('id')->chunkById(100, function ($users) use ($now): void {
            foreach ($users as $user) {
                $membership = DB::table('workspace_memberships')
                    ->join('workspaces', 'workspaces.id', '=', 'workspace_memberships.workspace_id')
                    ->where('workspace_memberships.user_id', $user->id)
                    ->where('workspaces.type', 'personal')
                    ->orderBy('workspace_memberships.id')
                    ->first();

                if ($membership !== null) {
                    continue;
                }

                $workspaceId = DB::table('workspaces')->insertGetId([
                    'name' => $user->name."'s Workspace",
                    'type' => 'personal',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('workspace_memberships')->insert([
                    'workspace_id' => $workspaceId,
                    'user_id' => $user->id,
                    'role' => 'owner',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    private function assertWorkspaceConsistency(): void
    {
        $checks = [
            'mailboxes/provider_connections' => DB::table('mailboxes as m')
                ->join('provider_connections as pc', 'pc.id', '=', 'm.provider_connection_id')
                ->whereColumn('m.workspace_id', '<>', 'pc.workspace_id')
                ->exists(),
            'campaign_mailbox' => DB::table('campaign_mailbox as cm')
                ->join('campaigns as c', 'c.id', '=', 'cm.campaign_id')
                ->join('mailboxes as m', 'm.id', '=', 'cm.mailbox_id')
                ->whereColumn('c.workspace_id', '<>', 'm.workspace_id')
                ->exists(),
            'campaign_recipients/campaigns' => DB::table('campaign_recipients as cr')
                ->join('campaigns as c', 'c.id', '=', 'cr.campaign_id')
                ->whereColumn('cr.workspace_id', '<>', 'c.workspace_id')
                ->exists(),
            'campaign_recipients/mailboxes' => DB::table('campaign_recipients as cr')
                ->join('mailboxes as m', 'm.id', '=', 'cr.mailbox_id')
                ->whereNotNull('cr.mailbox_id')
                ->whereColumn('cr.workspace_id', '<>', 'm.workspace_id')
                ->exists(),
            'recipient_imports/campaigns' => DB::table('recipient_imports as ri')
                ->join('campaigns as c', 'c.id', '=', 'ri.campaign_id')
                ->whereColumn('ri.workspace_id', '<>', 'c.workspace_id')
                ->exists(),
            'threads/mailboxes' => DB::table('threads as t')
                ->join('mailboxes as m', 'm.id', '=', 't.mailbox_id')
                ->whereColumn('t.workspace_id', '<>', 'm.workspace_id')
                ->exists(),
            'threads/recipients' => DB::table('threads as t')
                ->join('campaign_recipients as cr', 'cr.id', '=', 't.campaign_recipient_id')
                ->whereNotNull('t.campaign_recipient_id')
                ->whereColumn('t.workspace_id', '<>', 'cr.workspace_id')
                ->exists(),
            'messages/mailboxes' => DB::table('messages as msg')
                ->join('mailboxes as m', 'm.id', '=', 'msg.mailbox_id')
                ->whereColumn('msg.workspace_id', '<>', 'm.workspace_id')
                ->exists(),
            'messages/threads' => DB::table('messages as msg')
                ->join('threads as t', 't.id', '=', 'msg.thread_id')
                ->whereNotNull('msg.thread_id')
                ->whereColumn('msg.workspace_id', '<>', 't.workspace_id')
                ->exists(),
            'messages/recipients' => DB::table('messages as msg')
                ->join('campaign_recipients as cr', 'cr.id', '=', 'msg.campaign_recipient_id')
                ->whereNotNull('msg.campaign_recipient_id')
                ->whereColumn('msg.workspace_id', '<>', 'cr.workspace_id')
                ->exists(),
            'send_attempts/mailboxes' => DB::table('send_attempts as sa')
                ->join('mailboxes as m', 'm.id', '=', 'sa.mailbox_id')
                ->whereColumn('sa.workspace_id', '<>', 'm.workspace_id')
                ->exists(),
            'send_attempts/messages' => DB::table('send_attempts as sa')
                ->join('messages as msg', 'msg.id', '=', 'sa.message_id')
                ->whereColumn('sa.workspace_id', '<>', 'msg.workspace_id')
                ->exists(),
            'send_attempts/recipients' => DB::table('send_attempts as sa')
                ->join('campaign_recipients as cr', 'cr.id', '=', 'sa.campaign_recipient_id')
                ->whereColumn('sa.workspace_id', '<>', 'cr.workspace_id')
                ->exists(),
        ];

        foreach ($checks as $relationship => $inconsistent) {
            if ($inconsistent) {
                throw new RuntimeException("Workspace mismatch found in {$relationship}.");
            }
        }
    }
};
