<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->integer('allocated_minutes')->default(7200); // 5 Days Default
            $table->timestamps();

            // This ensures a department can only have ONE setting per document type
            $table->unique(['department_id', 'document_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_standards');
    }
};