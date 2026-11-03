<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usages', function (Blueprint $table) {
            $table->id();
            $table->string('service');
            $table->string('endpoint');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('cost_units', 8, 2)->default(0);
            $table->date('date')->index();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['service', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usages');
    }
};
