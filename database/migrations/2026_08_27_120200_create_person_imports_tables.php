<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename')->nullable();
            $table->string('source_hash');
            $table->json('headers');
            $table->json('mapping');
            $table->unsignedInteger('mapping_version')->default(1);
            $table->string('status');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('new')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('invalid')->default(0);
            $table->unsignedInteger('duplicate')->default(0);
            $table->unsignedInteger('suppressed')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('person_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_import_id')->constrained('person_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_values');
            $table->string('email_address')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('job_title')->nullable();
            $table->string('company')->nullable();
            // Use a parenthesised expression so this works on MySQL and MariaDB,
            // where JSON cannot use a plain literal default.
            $table->json('tags')->default(DB::raw("('[]')"));
            $table->string('issue');
            $table->string('action');
            $table->string('row_hash');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_import_rows');
        Schema::dropIfExists('person_imports');
    }
};
