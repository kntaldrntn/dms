<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('status')->default('Pending')->after('state');
            $table->timestamp('due_date')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['status', 'due_date', 'completed_at']);
        });
    }
};