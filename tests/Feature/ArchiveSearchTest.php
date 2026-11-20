<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Monday 10:00 Manila, inside working hours.
    $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 16)->setTime(10, 0));
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->engineering = Department::create(['code' => 'CEO', 'name' => 'Engineering', 'state' => 1]);
    $this->clerk = User::factory()->create(['role' => 'records_manager', 'department_id' => $records->id]);
    $this->healthStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->health->id]);
    $this->simple = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);
    $this->complex = TransactionType::create(['name' => 'Complex', 'processing_days' => 7, 'state' => 1]);
    $this->communication = DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1]);
    $this->purchaseOrder = DocumentType::create(['document_code' => 'PO', 'document_type' => 'Purchase Order', 'state' => 1]);

    $this->register = function (string $barcode, Department $office, array $overrides = []) {
        test()->actingAs($this->clerk)->post(route('documents.store'), [
            'barcode' => $barcode, 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Juan dela Cruz',
            'subject_matter' => 'Request for chairs and tables', 'is_confidential' => false, 'route_mode' => 'paper',
            'routed_to' => [$office->id], 'primary_office_id' => $office->id,
            'transaction_type_id' => $this->simple->id, 'document_type_id' => $this->communication->id,
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return Document::where('barcode', $barcode)->firstOrFail();
    };
});

test('records sees every active document and can combine filters', function () {
    ($this->register)('ARC-1', $this->health);
    ($this->register)('ARC-2', $this->engineering, [
        'subject_matter' => 'Snacks for the training of trainors',
        'transaction_type_id' => $this->complex->id, 'document_type_id' => $this->purchaseOrder->id,
    ]);
    ($this->register)('ARC-3', $this->health)->update(['state' => 0]);

    $barcodes = fn (array $query) => collect($this->actingAs($this->clerk)->get(route('archive.index', $query))
        ->assertOk()->viewData('page')['props']['documents']['data'])->pluck('barcode')->all();

    expect($barcodes([]))->toEqualCanonicalizing(['ARC-1', 'ARC-2'])
        ->and($barcodes(['keyword' => 'training']))->toBe(['ARC-2'])
        ->and($barcodes(['keyword' => 'arc-1']))->toBe(['ARC-1'])
        ->and($barcodes(['transaction_type_id' => $this->complex->id]))->toBe(['ARC-2'])
        ->and($barcodes(['document_type_id' => $this->communication->id, 'office_id' => $this->health->id]))->toBe(['ARC-1'])
        ->and($barcodes(['office_id' => $this->health->id, 'keyword' => 'training']))->toBe([])
        ->and($barcodes(['status' => 'completed']))->toBe([])
        ->and($barcodes(['from' => '2026-11-16', 'to' => '2026-11-16']))->toEqualCanonicalizing(['ARC-1', 'ARC-2'])
        ->and($barcodes(['from' => '2026-11-17']))->toBe([]);
});

test('the page lists the rows with their office and the filter options', function () {
    ($this->register)('ARC-4', $this->health);

    $this->actingAs($this->clerk)->get(route('archive.index', ['keyword' => 'chairs', 'per_page' => 25]))
        ->assertInertia(fn (Assert $page) => $page->component('archive/Index')
            ->where('filters', ['keyword' => 'chairs'])
            ->where('perPage', 25)
            ->where('documents.data.0.barcode', 'ARC-4')
            ->where('documents.data.0.document_type', ['code' => 'COM', 'name' => 'Communications'])
            ->where('documents.data.0.holders.0.code', 'CHO')
            ->has('options.transactionTypes', 2)
            ->where('options.documentTypes.0', ['id' => $this->communication->id, 'name' => 'Communications']));
});

test('workstation staff only find non-confidential documents their office handled', function () {
    ($this->register)('ARC-5', $this->health);
    ($this->register)('ARC-6', $this->engineering);
    ($this->register)('ARC-7', $this->health)->update(['is_confidential' => true]);

    $this->actingAs($this->healthStaff)->get(route('archive.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('documents.data', 1)
            ->where('documents.data.0.barcode', 'ARC-5'));
});

test('a reversed date range is rejected and guests are sent to log in', function () {
    $this->actingAs($this->clerk)->from(route('archive.index'))
        ->get(route('archive.index', ['from' => '2026-11-20', 'to' => '2026-11-01']))
        ->assertSessionHasErrors('to');

    auth()->logout();
    $this->get(route('archive.index'))->assertRedirect(route('login'));
});
