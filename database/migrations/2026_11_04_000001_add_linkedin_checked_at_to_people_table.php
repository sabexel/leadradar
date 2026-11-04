<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When we last searched for this person's LinkedIn URL (found or not) — no repeat queries for 30 days.
        Schema::table('people', function (Blueprint $table) {
            $table->dateTime('linkedin_checked_at')->nullable()->after('linkedin_source_url');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('linkedin_checked_at');
        });
    }
};
