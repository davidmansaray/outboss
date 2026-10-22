<?php

use App\Domain\People\Services\BackfillPeopleFromRecipients;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new BackfillPeopleFromRecipients)->handle();
    }

    public function down(): void
    {
        // People remain; recipient links are discarded when person_id is dropped.
    }
};
