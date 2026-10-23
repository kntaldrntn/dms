<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FGD Phase 2c-2: each signer's own PNPKI certificate (.p12), stored on the private `certificates` disk.
 * Only the file location and what was read from it are kept here; the PIN is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('certificate_path')->nullable()->after('signature_path');
            $table->string('certificate_subject')->nullable()->after('certificate_path');
            $table->timestamp('certificate_valid_until')->nullable()->after('certificate_subject');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['certificate_path', 'certificate_subject', 'certificate_valid_until']);
        });
    }
};
