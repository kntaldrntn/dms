<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentClassification;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->office = Department::create(['code' => 'DST', 'name' => 'Destination', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->admin = User::factory()->create(['role' => 'system_administrator', 'department_id' => $this->recordsOffice->id]);
    $this->staff = User::factory()->create(['role' => 'staff', 'department_id' => $this->office->id]);
    $this->simple = TransactionType::create(['name' => 'Simple Transaction', 'processing_days' => 3, 'state' => 1]);
    $this->financial = DocumentClassification::create(['name' => 'Financial', 'state' => 1]);
    $this->aics = DocumentClassification::create(['name' => 'AICS', 'state' => 1]);
});

test('records section manages registries but not users; workstation users get neither', function () {
    $this->actingAs($this->records)->get(route('document-types.index'))->assertOk();
    $this->get(route('performance-standards.index'))->assertOk();
    $this->get(route('users.index'))->assertForbidden();

    $this->actingAs($this->admin)->get(route('users.index'))->assertOk();

    $this->actingAs($this->staff)->get(route('document-types.index'))->assertForbidden();
    $this->get(route('departments.index'))->assertForbidden();
});

test('a document type saves its rulebook entry and allowed classifications', function () {
    $this->actingAs($this->records)->post(route('document-types.store'), [
        'document_code' => 'VC', 'document_type' => 'Disbursement Voucher', 'state' => 1,
        'signature_type' => 'handwritten', 'may_be_confidential' => false,
        'default_transaction_type_id' => $this->simple->id, 'rulebook_remarks' => 'Complex for procurement',
        'classification_ids' => [$this->financial->id, $this->aics->id],
    ])->assertRedirect(route('document-types.index'))->assertSessionHasNoErrors();

    $type = DocumentType::where('document_code', 'VC')->firstOrFail();
    expect($type->signature_type)->toBe('handwritten')
        ->and($type->default_transaction_type_id)->toBe($this->simple->id)
        ->and($type->classifications->pluck('id')->sort()->values()->all())->toBe([$this->financial->id, $this->aics->id]);

    $this->put(route('document-types.update', $type), [
        'document_code' => 'VC', 'document_type' => 'Disbursement Voucher', 'state' => 1,
        'signature_type' => 'digital', 'may_be_confidential' => true, 'default_transaction_type_id' => null,
        'classification_ids' => [$this->aics->id],
    ])->assertSessionHasNoErrors();

    $type->refresh();
    expect($type->signature_type)->toBe('digital')
        ->and($type->may_be_confidential)->toBeTrue()
        ->and($type->default_transaction_type_id)->toBeNull()
        ->and($type->classifications->pluck('id')->all())->toBe([$this->aics->id]);

    $this->get(route('document-types.index'))->assertInertia(fn (Assert $page) => $page
        ->component('document-types/Index')
        ->where('documenttypes.data.0.classifications.0.name', 'AICS')
        ->has('transactionTypes', 1)
        ->has('classifications', 2));
});

test('rulebook input is validated', function () {
    $inactive = DocumentClassification::create(['name' => 'Retired', 'state' => 0]);

    $this->actingAs($this->records)->post(route('document-types.store'), [
        'document_code' => 'X', 'document_type' => 'Bad', 'state' => 1,
        'signature_type' => 'stamped', 'default_transaction_type_id' => 9999,
        'classification_ids' => [$inactive->id, $this->aics->id, $this->aics->id],
    ])->assertSessionHasErrors(['signature_type', 'default_transaction_type_id', 'classification_ids.0', 'classification_ids.1']);

    expect(DocumentType::where('document_code', 'X')->exists())->toBeFalse();
});

test('seeder loads the FGD rulebook and classification matrix', function () {
    $this->seed(DatabaseSeeder::class);

    $communications = DocumentType::where('document_code', 'COM')->firstOrFail();
    expect($communications->signature_type)->toBe('digital')
        ->and($communications->may_be_confidential)->toBeTrue()
        ->and($communications->default_transaction_type_id)->toBeNull()
        ->and($communications->classifications()->count())->toBe(17);

    expect(DocumentType::where('document_code', 'PY')->first()->defaultTransactionType->name)->toBe('Complex Transaction')
        ->and(DocumentType::where('document_code', 'PRMT')->first()->signature_type)->toBeNull()
        ->and(DocumentType::where('document_code', 'PRMT')->first()->classifications->pluck('name')->sort()->values()->all())
        ->toBe(['For Signature', 'Special Mayors Permit']);
});

test('an office copy is delinquent after 24 hours when no performance standard is set', function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 21)->setTime(9, 0));
    $document = Document::create([
        'barcode' => 'DLQ-1', 'access_code' => 'X1', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Delinquency default', 'department_id' => $this->office->id,
        'document_type_id' => DocumentType::create(['document_code' => 'T', 'document_type' => 'Test', 'state' => 1])->id,
        'transaction_type_id' => $this->simple->id,
    ]);
    DocumentTrail::create([
        'document_id' => $document->id, 'department_id' => $this->recordsOffice->id, 'received_by' => $this->records->id,
        'received_at' => now('Asia/Manila'), 'released_by' => $this->records->id, 'released_at' => now('Asia/Manila'),
        'released_to' => $this->office->id,
    ]);

    $this->travel(23)->hours();
    expect($document->fresh()->is_delinquent)->toBeFalse();

    $this->travel(2)->hours();
    expect($document->fresh()->is_delinquent)->toBeTrue();
});
