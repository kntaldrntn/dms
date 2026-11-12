<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FGD Phase 5: the City Administrator's office tags a delinquent document (held over its allowed time) with a
 * follow-up note. Tags are history: one row per tag, never edited except the holding office's acknowledgement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delinquency_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // The office holding the Primary copy when it was tagged, and that entry.
            $table->foreignId('department_id')->constrained();
            $table->foreignId('document_trail_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tag', 20);
            $table->text('note');
            $table->foreignId('tagged_by')->constrained('users');
            // Manila wall-clock, like the other workflow timestamps.
            $table->dateTime('tagged_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users');
            $table->dateTime('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delinquency_tags');
    }
};
