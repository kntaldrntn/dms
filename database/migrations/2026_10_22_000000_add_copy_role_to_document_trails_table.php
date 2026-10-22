<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-office routing: one office holds the Primary entry and acts on the document;
 * the others hold view-only copies they can receive but not act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->enum('copy_role', ['primary', 'copy'])->default('primary')->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->dropColumn('copy_role');
        });
    }
};
