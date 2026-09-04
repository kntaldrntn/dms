<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_trails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            
            // --- RECEIVED INFO ---
            $table->foreignId('department_id')->constrained('departments'); // e.g., REC
            $table->foreignId('received_by')->nullable()->constrained('users'); // e.g., Violeta P. Ancheta
            $table->timestamp('received_at')->nullable();
            $table->string('received_action')->nullable(); // e.g., "New Document Trail"
            
            // --- RELEASED INFO ---
            $table->foreignId('released_by')->nullable()->constrained('users'); 
            $table->timestamp('released_at')->nullable();
            $table->string('released_action')->nullable();
            $table->foreignId('released_to')->nullable()->constrained('departments'); // e.g., ADM
            
            // --- REMARKS ---
            $table->text('remarks')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_trails');
    }
};