<?php

declare(strict_types=1);

use App\Enums\PageType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extracted facts only — never full HTML (docs/03, docs/09).
        Schema::create('crawled_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('url', 700);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->dateTime('fetched_at')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('page_type')->default(PageType::Other->value);
            $table->json('extracted')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawled_pages');
    }
};
