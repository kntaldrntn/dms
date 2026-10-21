<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FGD intake: Records tags confidential documents (no AI assist, no upload) and every document
 * travels either paperless (online + PNPKI) or on paper (physical folder, wet signature).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_confidential')->default(false)->after('classification_id');
            $table->enum('route_mode', ['paperless', 'paper'])->default('paper')->after('is_confidential');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['is_confidential', 'route_mode']);
        });
    }
};
