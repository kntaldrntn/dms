<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People directory: office heads and frequent signatories. Intake AI matches OCR'd sender names against it,
 * because a handwritten signature often hides part of the printed name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('title', 30)->nullable();
            $table->string('first_name', 100);
            $table->string('middle_initial', 5)->nullable();
            $table->string('last_name', 100)->index();
            $table->string('suffix', 30)->nullable();
            $table->string('position', 150)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments');
            $table->tinyInteger('state')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
