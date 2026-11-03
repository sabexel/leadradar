<?php

declare(strict_types=1);

use App\Enums\RunTrigger;
use App\Enums\ScrapeRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('trigger')->default(RunTrigger::Schedule->value);
            $table->unsignedInteger('signals_found')->default(0);
            $table->unsignedInteger('companies_created')->default(0);
            $table->unsignedInteger('qualified_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->string('status')->default(ScrapeRunStatus::Running->value)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
