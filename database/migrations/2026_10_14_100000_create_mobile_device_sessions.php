<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A device session belongs to the user. The access tokens it mints are
        // ordinary Sanctum tokens bound to one workspace at a time.
        Schema::create('device_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('install_id', 64);
            $table->string('name', 120);
            $table->string('platform', 16);
            $table->string('app_version', 40)->nullable();
            $table->string('apns_token', 200)->nullable()->index();
            $table->string('apns_environment', 16)->nullable();
            $table->string('notify', 16)->default('needs');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'install_id']);
        });

        Schema::create('device_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('device_session_id')->constrained('device_sessions')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('mobile_authorization_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('code_challenge', 128);
            $table->string('redirect_uri');
            $table->string('device_name', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignUuid('device_session_id')
                ->nullable()
                ->after('workspace_id')
                ->constrained('device_sessions')
                ->cascadeOnDelete();
        });

        // Keyset paging for the thread list and the mobile changes feed.
        Schema::table('threads', function (Blueprint $table): void {
            $table->index(['workspace_id', 'last_message_at', 'id'], 'threads_workspace_last_message_id_index');
            $table->index(['workspace_id', 'updated_at', 'id'], 'threads_workspace_updated_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            $table->dropIndex('threads_workspace_updated_id_index');
            $table->dropIndex('threads_workspace_last_message_id_index');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('device_session_id');
        });

        Schema::dropIfExists('mobile_authorization_codes');
        Schema::dropIfExists('device_refresh_tokens');
        Schema::dropIfExists('device_sessions');
    }
};
