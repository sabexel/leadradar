<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('preferred_name')->nullable();
            $table->string('role');
            $table->date('appointed_on')->nullable();
            $table->date('resigned_on')->nullable();
            $table->string('country_of_residence')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('linkedin_source_url')->nullable();
            // FK to email_addresses is added in a later migration (circular reference).
            $table->unsignedBigInteger('email_id')->nullable();
            $table->string('source');
            $table->string('source_url')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
