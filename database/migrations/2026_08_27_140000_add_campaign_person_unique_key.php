<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('campaign_recipients')
            ->select('campaign_id', 'person_id')
            ->whereNotNull('person_id')
            ->groupBy('campaign_id', 'person_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add campaign_recipients (campaign_id, person_id) unique key: duplicate person enrolments exist.',
            );
        }

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->unique(['campaign_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropUnique(['campaign_id', 'person_id']);
        });
    }
};
