<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->orderBy('id')
            ->eachById(function ($token): void {
                $workspaceId = DB::table('workspace_memberships')
                    ->join('workspaces', 'workspaces.id', '=', 'workspace_memberships.workspace_id')
                    ->where('workspace_memberships.user_id', $token->tokenable_id)
                    ->orderByRaw("case when workspaces.type = 'personal' then 0 else 1 end")
                    ->orderBy('workspaces.id')
                    ->value('workspaces.id');

                if ($workspaceId !== null) {
                    DB::table('personal_access_tokens')
                        ->where('id', $token->id)
                        ->update(['workspace_id' => $workspaceId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
