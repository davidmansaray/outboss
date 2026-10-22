<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->string('connection_kind')->default('oauth')->after('workspace_id');
            $table->string('auth_scheme')->default('oauth2')->after('connection_kind');
            $table->text('credential_payload')->nullable()->after('refresh_token');
            $table->string('managed_instance_key')->nullable()->after('provider_metadata');
        });

        // Be explicit for databases that preserve NULL when adding a
        // defaulted column to existing rows during a rolling migration.
        DB::table('provider_connections')
            ->whereNull('connection_kind')
            ->update(['connection_kind' => 'oauth']);
        DB::table('provider_connections')
            ->whereNull('auth_scheme')
            ->update(['auth_scheme' => 'oauth2']);
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn([
                'connection_kind',
                'auth_scheme',
                'credential_payload',
                'managed_instance_key',
            ]);
        });
    }
};
