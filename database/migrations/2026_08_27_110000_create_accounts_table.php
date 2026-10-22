<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('website')->nullable();
            $table->string('domain_normalized')->nullable();
            $table->text('notes')->nullable();
            // Use a parenthesised expression so this works on MariaDB, where
            // JSON is backed by a TEXT type and cannot use a plain literal default.
            $table->json('tags')->default(DB::raw("('[]')"));
            $table->string('source');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'domain_normalized']);
            $table->index(['workspace_id', 'name']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->foreignId('account_id')
                ->nullable()
                ->after('sequence_version_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
        });

        Schema::dropIfExists('accounts');
    }
};
