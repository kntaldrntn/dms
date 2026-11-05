<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Granted by the System Administrator: this staff member may sign in place of their Department Head.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_sign_for_head')->default(false)->after('role');
        });
        // The signer signed on behalf of the Department Head ("for the Department Head" in the trail).
        Schema::table('document_trails', function (Blueprint $table) {
            $table->boolean('signed_for_head')->default(false)->after('signed_by');
        });
    }

    public function down(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->dropColumn('signed_for_head');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_sign_for_head');
        });
    }
};
