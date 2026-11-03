<?php

declare(strict_types=1);

use App\Enums\CompanyType;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('normalised_name')->index();
            $table->string('website')->nullable();
            $table->string('domain')->nullable()->unique();
            $table->char('country', 2)->default('GB');
            $table->string('location')->nullable();
            $table->json('registered_address')->nullable();
            $table->string('registration_number')->nullable()->index();
            $table->string('registry')->nullable();
            $table->string('company_status')->nullable();
            $table->string('company_kind')->nullable();
            $table->date('incorporated_on')->nullable();
            $table->json('sic_codes')->nullable();
            $table->string('accounts_category')->nullable();
            // FKs to email_addresses are added in a later migration (circular reference).
            $table->unsignedBigInteger('contact_email_id')->nullable();
            $table->unsignedBigInteger('careers_email_id')->nullable();
            $table->string('company_type')->default(CompanyType::Unknown->value);
            $table->json('laravel_evidence')->nullable();
            $table->json('offshore_evidence')->nullable();
            $table->string('status')->default(LeadStatus::Discovered->value);
            $table->string('pipeline_step')->default(PipelineStep::RegistrySearch->value);
            $table->string('reject_reason')->nullable();
            $table->decimal('score', 3, 1)->nullable();
            $table->json('score_breakdown')->nullable();
            $table->string('outreach_status')->default(OutreachStatus::NotContacted->value);
            $table->dateTime('contacted_at')->nullable();
            $table->dateTime('qualified_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'score']);
            $table->index(['country', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
