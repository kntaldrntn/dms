<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->string('trail_type')->default('forward')->after('remarks');
            $table->boolean('is_digitally_signed')->default(false)->after('trail_type');
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete()->after('is_digitally_signed');
        });
    }

    public function down(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->dropForeign(['signed_by']);
            $table->dropColumn([
                'trail_type', 
                'is_digitally_signed', 
                'signed_by'
            ]);
        });
    }
};