<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('barcode', 50)->unique();
            $table->string('access_code', 20);
            
            // Foreign Keys for Registries
            $table->foreignId('transaction_type_id')->nullable()->constrained('transaction_types');
            $table->foreignId('document_type_id')->nullable()->constrained('document_types');
            $table->foreignId('classification_id')->nullable()->constrained('document_classifications');
            $table->foreignId('delivery_method_id')->nullable()->constrained('delivery_methods');
            $table->foreignId('department_id')->nullable()->constrained('departments'); // Direct To
            
            // Source Info
            $table->string('source_type', 50)->default('External');
            $table->string('source_location')->nullable(); // Can hold 'N/A' or a department ID string
            $table->string('source_name');
            
            // Optional Client Info
            $table->string('gender', 20)->nullable();
            $table->string('contact_no', 50)->nullable();
            $table->string('email')->nullable();
            
            // Content
            $table->text('subject_matter');
            $table->string('linked_documents')->nullable(); // Space-separated barcodes
            
            // System Status (0 = Deleted/Archived, 1 = Active/Pending)
            $table->integer('state')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};