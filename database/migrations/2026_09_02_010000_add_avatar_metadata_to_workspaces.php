<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('avatar_path', 512)->nullable()->after('type');
            $table->string('avatar_source_url', 2048)->nullable()->after('avatar_path');
            $table->char('avatar_website_hash', 64)->nullable()->after('avatar_source_url');
            $table->timestamp('avatar_refreshed_at')->nullable()->after('avatar_website_hash');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn([
                'avatar_path',
                'avatar_source_url',
                'avatar_website_hash',
                'avatar_refreshed_at',
            ]);
        });
    }
};
