<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            // 'electronic' or 'physical'; set on the ADM Department Head signing step.
            $table->string('signature_mode', 20)->nullable()->after('signed_by');
        });
    }

    public function down(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->dropColumn('signature_mode');
        });
    }
};
