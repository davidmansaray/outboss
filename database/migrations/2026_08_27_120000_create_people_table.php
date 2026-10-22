<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('email_address');
            $table->string('email_normalized');
            $table->string('job_title')->nullable();
            $table->text('notes')->nullable();
            // Use a parenthesised expression so this works on MySQL and MariaDB,
            // where JSON cannot use a plain literal default.
            $table->json('tags')->default(DB::raw("('[]')"));
            $table->string('source');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'email_normalized']);
            $table->index(['workspace_id', 'account_id']);
            $table->index(['workspace_id', 'full_name']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->foreignId('person_id')
                ->nullable()
                ->after('account_id')
                ->constrained('people')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('person_id');
        });

        Schema::dropIfExists('people');
    }
};
