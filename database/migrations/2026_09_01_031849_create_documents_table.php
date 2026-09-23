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
            
            // --- NEW: Workflow & Assignment Tracking ---
            $table->foreignId('current_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('requires_signature_from', [
                'department_head', 
                'city_administrator', 
                'city_mayor',
                'none'
            ])->default('none');
            
            // Foreign Keys for Registries
            $table->foreignId('transaction_type_id')->nullable()->constrained('transaction_types');
            $table->foreignId('document_type_id')->nullable()->constrained('document_types');
            $table->foreignId('classification_id')->nullable()->constrained('document_classifications');
            $table->foreignId('delivery_method_id')->nullable()->constrained('delivery_methods');
            $table->foreignId('department_id')->nullable()->constrained('departments'); 
            
            // AI Data
            $table->json('ai_routing_suggestions')->nullable();
            
            // Source Info
            $table->string('source_type', 50)->default('External');
            $table->string('source_location')->nullable(); 
            $table->string('source_name');
            
            // Optional Client Info
            $table->string('gender', 20)->nullable();
            $table->string('contact_no', 50)->nullable();
            $table->string('email')->nullable();
            
            // Content
            $table->text('subject_matter');
            $table->json('linked_documents')->nullable(); // Converts it to a proper 1-to-many array structure
            $table->string('attached_file')->nullable();
            
            // System & Lifecycle Status
            $table->integer('state')->default(1);
            $table->string('status')->default('Pending');
            $table->timestamp('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};