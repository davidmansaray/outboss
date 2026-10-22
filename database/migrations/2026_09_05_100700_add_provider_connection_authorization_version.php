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
            $table->unsignedInteger('authorization_version')->default(1)->after('token_version');
        });

        // S3 approval data predating this column was bound to token_version,
        // which is an OAuth refresh CAS counter rather than an authority
        // generation. Start every existing connection in a distinct next
        // generation so local pre-release approvals fail closed; copying the
        // old value would manufacture approval continuity across the change.
        DB::table('provider_connections')->update([
            'authorization_version' => DB::raw('token_version + 1'),
        ]);
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn('authorization_version');
        });
    }
};
