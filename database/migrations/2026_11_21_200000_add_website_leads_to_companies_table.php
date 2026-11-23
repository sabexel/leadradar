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
            $table->string('lead_product')->default('laravel')->after('company_type');
            $table->string('google_place_id')->nullable()->unique()->after('domain');
            $table->unsignedInteger('review_count')->nullable()->after('location');
            $table->string('phone')->nullable()->after('review_count');
            $table->boolean('email_consent_required')->default(false)->after('phone');
            $table->index(['lead_product', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['lead_product', 'status']);
            $table->dropColumn(['lead_product', 'google_place_id', 'review_count', 'phone', 'email_consent_required']);
        });
    }
};
