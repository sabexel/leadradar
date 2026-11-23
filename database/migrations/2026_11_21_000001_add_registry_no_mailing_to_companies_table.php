<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The company told its registry it wants no unsolicited mail (KVK `indNonMailing`) — a hard gate (D110).
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('registry_no_mailing')->default(false)->after('accounts_category');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('registry_no_mailing');
        });
    }
};
