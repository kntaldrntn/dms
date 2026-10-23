<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FGD Phase 2d: referral slips are retired. A slip still waiting for the ADM Department Head's signature moves to
 * the City Administrator's single approval (it is already at ADM). The executive step now lives only on the trail
 * stage, so documents.requires_signature_from is dropped. documents.approved_routing_slip keeps past slips' target
 * offices until the fresh-data reset (2e) and is no longer used.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_trails')->whereNull('released_at')->where('stage', 'adm_signature')->update(['stage' => 'adm_approval']);

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('requires_signature_from');
        });
    }

    public function down(): void
    {
        // The column comes back with its default only; the stage on document_trails stays the source of truth.
        Schema::table('documents', function (Blueprint $table) {
            $table->enum('requires_signature_from', ['department_head', 'city_administrator', 'city_mayor', 'none'])->default('none');
        });
    }
};
