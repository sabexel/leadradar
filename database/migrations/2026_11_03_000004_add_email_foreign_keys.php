<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('contact_email_id')->references('id')->on('email_addresses')->nullOnDelete();
            $table->foreign('careers_email_id')->references('id')->on('email_addresses')->nullOnDelete();
        });

        Schema::table('people', function (Blueprint $table) {
            $table->foreign('email_id')->references('id')->on('email_addresses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['email_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['contact_email_id']);
            $table->dropForeign(['careers_email_id']);
        });
    }
};
