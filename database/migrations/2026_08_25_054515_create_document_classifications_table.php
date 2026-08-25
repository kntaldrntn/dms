<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., Confidential, Public, Internal Use Only
            $table->string('description')->nullable();
            $table->boolean('state')->default(1); // To allow soft-disabling without breaking old records
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_classifications');
    }
};