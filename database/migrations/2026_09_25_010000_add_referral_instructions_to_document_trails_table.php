<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->json('required_actions')->nullable()->after('remarks');
            $table->string('other_action')->nullable()->after('required_actions');
            $table->string('concerned_personnel')->nullable()->after('other_action');
        });
    }

    public function down(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->dropColumn(['required_actions', 'other_action', 'concerned_personnel']);
        });
    }
};
