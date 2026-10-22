<?php

use App\Domain\People\Services\BackfillAccountsFromRecipients;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new BackfillAccountsFromRecipients)->handle();
    }

    public function down(): void
    {
        // Links are discarded when campaign_recipients.account_id is dropped.
    }
};
