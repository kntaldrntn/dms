<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FGD document-type rulebook: signature, whether the type may be confidential, default
 * transaction category, and which classifications a type may carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->enum('signature_type', ['digital', 'handwritten'])->nullable()->after('document_type');
            $table->boolean('may_be_confidential')->default(false)->after('signature_type');
            $table->foreignId('default_transaction_type_id')->nullable()->after('may_be_confidential')
                ->constrained('transaction_types')->nullOnDelete();
            $table->string('rulebook_remarks')->nullable()->after('default_transaction_type_id');
        });

        Schema::create('document_classification_document_type', function (Blueprint $table) {
            $table->id();
            // Explicit constraint names: the generated ones exceed MySQL's 64-character limit.
            $table->foreignId('document_type_id')
                ->constrained('document_types', indexName: 'doc_type_classification_type_fk')->cascadeOnDelete();
            $table->foreignId('document_classification_id')
                ->constrained('document_classifications', indexName: 'doc_type_classification_classification_fk')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['document_type_id', 'document_classification_id'], 'doc_type_classification_unique');
        });

        // FGD: 24 h (1440 min) per office until CART calibrates the Citizen's Charter times.
        Schema::table('performance_standards', function (Blueprint $table) {
            $table->integer('allocated_minutes')->default(1440)->change();
        });
    }

    public function down(): void
    {
        Schema::table('performance_standards', function (Blueprint $table) {
            $table->integer('allocated_minutes')->default(7200)->change();
        });

        Schema::dropIfExists('document_classification_document_type');

        Schema::table('document_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_transaction_type_id');
            $table->dropColumn(['signature_type', 'may_be_confidential', 'rulebook_remarks']);
        });
    }
};
