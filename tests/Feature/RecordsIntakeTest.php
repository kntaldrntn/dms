<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentClassification;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Inside working hours: intake routes use the working_hours middleware.
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 21)->setTime(10, 0));
    Storage::fake('public');
    // These tests cover the paperless rulebook; the hybrid paper-only default has its own test below.
    config(['services.paperless.enabled' => true]);

    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    Department::create(['code' => 'ADM', 'name' => 'Administrator', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->simple = TransactionType::create(['name' => 'Simple Transaction', 'processing_days' => 3, 'state' => 1]);
    $this->complaint = DocumentClassification::create(['name' => 'Complaint', 'state' => 1]);
    $this->financial = DocumentClassification::create(['name' => 'Financial', 'state' => 1]);

    $this->communications = DocumentType::create([
        'document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1,
        'signature_type' => 'digital', 'may_be_confidential' => true,
    ]);
    $this->communications->classifications()->sync([$this->complaint->id]);
    $this->purchaseRequest = DocumentType::create([
        'document_code' => 'PR', 'document_type' => 'Purchase Request', 'state' => 1, 'signature_type' => 'handwritten',
    ]);

    $this->payload = [
        'barcode' => 'INTAKE-1', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Intake rulebook', 'transaction_type_id' => $this->simple->id,
        'document_type_id' => $this->communications->id, 'route_mode' => 'paper', 'is_confidential' => false,
    ];
});

function scan(): UploadedFile
{
    return UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf');
}

test('a digitally signed type goes paperless with its scan attached', function () {
    $this->actingAs($this->records)->post(route('documents.store'), [
        ...$this->payload, 'route_mode' => 'paperless', 'attached_file' => scan(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $document = Document::where('barcode', 'INTAKE-1')->firstOrFail();
    expect($document->route_mode)->toBe('paperless')->and($document->is_confidential)->toBeFalse();
    Storage::disk('public')->assertExists($document->attached_file);
});

test('paperless needs the scan and a digitally signed type', function () {
    $this->actingAs($this->records)->post(route('documents.store'), [...$this->payload, 'route_mode' => 'paperless'])
        ->assertSessionHasErrors('attached_file');

    $this->post(route('documents.store'), [
        ...$this->payload, 'document_type_id' => $this->purchaseRequest->id, 'route_mode' => 'paperless', 'attached_file' => scan(),
    ])->assertSessionHasErrors(['route_mode' => 'Purchase Request needs a handwritten signature, so it takes the paper route.']);

    expect(Document::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('confidential documents take the paper route with no upload and no AI results', function () {
    $this->actingAs($this->records)->post(route('documents.store'), [
        ...$this->payload, 'is_confidential' => true, 'route_mode' => 'paperless', 'attached_file' => scan(),
        'ai_routing_suggestions' => [['department_id' => 1, 'priority' => 'Primary']],
    ])->assertSessionHasErrors(['attached_file', 'is_confidential', 'route_mode' => 'Confidential documents always take the paper route.']);

    $this->post(route('documents.store'), [...$this->payload, 'is_confidential' => true, 'classification_id' => $this->complaint->id])
        ->assertSessionHasNoErrors();

    $document = Document::where('barcode', 'INTAKE-1')->firstOrFail();
    expect($document->is_confidential)->toBeTrue()
        ->and($document->route_mode)->toBe('paper')
        ->and($document->attached_file)->toBeNull();
});

test('the classification must be one the document type allows', function () {
    $this->actingAs($this->records)->post(route('documents.store'), [...$this->payload, 'classification_id' => $this->financial->id])
        ->assertSessionHasErrors(['classification_id' => 'This classification is not used for Communications.']);

    // A type with no classification list accepts any active classification.
    $this->post(route('documents.store'), [
        ...$this->payload, 'document_type_id' => $this->purchaseRequest->id, 'classification_id' => $this->financial->id,
    ])->assertSessionHasNoErrors();
});

test('editing keeps the intake flags unless changed and refuses confidential with an existing scan', function () {
    $this->actingAs($this->records)->post(route('documents.store'), [
        ...$this->payload, 'route_mode' => 'paperless', 'attached_file' => scan(),
    ])->assertSessionHasNoErrors();
    $document = Document::where('barcode', 'INTAKE-1')->firstOrFail();

    $update = collect($this->payload)->except(['route_mode', 'is_confidential'])->all();
    $this->put(route('documents.update', $document), [...$update, 'subject_matter' => 'Edited', 'workflow_version' => $document->workflow_version])
        ->assertSessionHasNoErrors();
    $document->refresh();
    expect($document->subject_matter)->toBe('Edited')->and($document->route_mode)->toBe('paperless');

    $this->put(route('documents.update', $document), [
        ...$this->payload, 'is_confidential' => true, 'workflow_version' => $document->workflow_version,
    ])->assertSessionHasErrors('attached_file');
    expect($document->fresh()->is_confidential)->toBeFalse();
});

test('the intake form receives each type rulebook entry', function () {
    $this->actingAs($this->records)->get(route('documents.index'))->assertInertia(fn (Assert $page) => $page
        ->component('documents/Index')
        ->where('documentTypes.0.signature_type', 'digital')
        ->where('documentTypes.0.may_be_confidential', true)
        ->where('documentTypes.0.classifications.0.id', $this->complaint->id));
});

test('with the paperless route switched off, every document is saved on the paper route', function () {
    config(['services.paperless.enabled' => false]);

    $this->actingAs($this->records)->post(route('documents.store'), [
        ...$this->payload, 'route_mode' => 'paperless', 'attached_file' => scan(),
    ])->assertSessionHasNoErrors();

    expect(Document::latest('id')->first()->route_mode)->toBe('paper');
    $this->get(route('documents.index'))->assertInertia(fn (Assert $page) => $page->where('features.paperless', false));
});
