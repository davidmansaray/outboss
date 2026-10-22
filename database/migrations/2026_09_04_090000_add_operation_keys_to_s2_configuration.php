<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->string('client_operation_id', 160)->nullable()->after('lock_version');
            $table->char('operation_hash', 64)->nullable()->after('client_operation_id');
            $table->unique(['workspace_id', 'client_operation_id'], 'assets_workspace_operation_unique');
        });

        Schema::table('fulfilment_capabilities', function (Blueprint $table): void {
            $table->string('client_operation_id', 160)->nullable()->after('configuration_version');
            $table->char('operation_hash', 64)->nullable()->after('client_operation_id');
            $table->unique(['workspace_id', 'client_operation_id'], 'fulfilment_workspace_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fulfilment_capabilities', function (Blueprint $table): void {
            $table->dropUnique('fulfilment_workspace_operation_unique');
            $table->dropColumn(['client_operation_id', 'operation_hash']);
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropUnique('assets_workspace_operation_unique');
            $table->dropColumn(['client_operation_id', 'operation_hash']);
        });
    }
};
