<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('external_id');
            $table->string('title');
            $table->string('employer_name');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->string('url', 2048)->nullable();
            $table->dateTime('posted_at')->nullable()->index();
            $table->json('raw');
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
