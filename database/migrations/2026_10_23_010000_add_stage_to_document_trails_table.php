<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FGD Phase 2a: the executive step a document waits on is recorded on its open Primary entry
 * (null = normal processing) instead of the overloaded documents.requires_signature_from.
 */
return new class extends Migration
{
    private const STAGES = [
        'city_administrator' => 'adm_approval',
        'department_head' => 'adm_signature',
        'city_mayor' => 'ocm_approval',
    ];

    public function up(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->string('stage', 20)->nullable()->after('copy_role')->index();
        });

        // Backfill documents waiting on an executive step: their newest open Primary entry gets the stage.
        foreach (self::STAGES as $old => $stage) {
            $documentIds = DB::table('documents')->where('requires_signature_from', $old)
                ->where('state', 1)->where('status', '!=', 'Completed')->pluck('id');
            foreach ($documentIds as $documentId) {
                $trailId = DB::table('document_trails')->where('document_id', $documentId)
                    ->where('copy_role', 'primary')->whereNull('released_at')->max('id');
                if ($trailId) {
                    DB::table('document_trails')->where('id', $trailId)->update(['stage' => $stage]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->dropIndex(['stage']);
            $table->dropColumn('stage');
        });
    }
};
