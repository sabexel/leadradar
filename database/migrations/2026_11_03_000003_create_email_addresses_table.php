<?php

declare(strict_types=1);

use App\Enums\EmailVerificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->string('email');
            $table->string('kind');
            $table->string('origin');
            $table->string('source_url')->nullable();
            $table->string('verification_status')->default(EmailVerificationStatus::Unverified->value);
            $table->json('verification_raw')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_addresses');
    }
};
