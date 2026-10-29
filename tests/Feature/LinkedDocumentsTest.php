<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('linked barcodes resolve to the documents they name, so the detail page can link to them', function () {
    $office = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $user = User::factory()->create(['role' => 'records_manager', 'department_id' => $office->id]);
    $base = [
        'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client', 'subject_matter' => 'Letter',
        'department_id' => $office->id, 'status' => 'Pending',
        'document_type_id' => DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1])->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
    ];
    $parent = Document::create([...$base, 'barcode' => 'C000000001']);
    // A deactivated document is not offered as a link.
    Document::create([...$base, 'barcode' => 'C000000002', 'state' => 0]);
    $child = Document::create([...$base, 'barcode' => 'C000000003', 'linked_documents' => ['C000000001', 'C000000002', 'C999999999']]);

    $this->actingAs($user)->get(route('documents.show', $child))->assertInertia(fn (Assert $page) => $page
        ->where('linkedDocumentIds', ['C000000001' => $parent->id]));
});
