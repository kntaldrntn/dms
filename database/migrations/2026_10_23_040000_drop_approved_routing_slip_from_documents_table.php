<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FGD Phase 2e: referral slips were retired in 2d; their target offices were kept until the fresh-data reset.
 * Run `php artisan dms:reset-documents` first, then migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('approved_routing_slip');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->json('approved_routing_slip')->nullable();
        });
    }
};
