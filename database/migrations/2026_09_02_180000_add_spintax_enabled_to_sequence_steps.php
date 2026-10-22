<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequence_steps', function (Blueprint $table): void {
            $table->boolean('subject_spintax_enabled')->default(false)->after('body_template');
            $table->boolean('body_spintax_enabled')->default(false)->after('subject_spintax_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('sequence_steps', function (Blueprint $table): void {
            $table->dropColumn(['subject_spintax_enabled', 'body_spintax_enabled']);
        });
    }
};
