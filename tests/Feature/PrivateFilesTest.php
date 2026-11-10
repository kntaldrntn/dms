<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 10)->setTime(10, 0));
    $this->records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->other = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    $this->clerk = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->records->id]);
});

test('an intake scan is stored privately and opened only by users who may view the document', function () {
    $this->actingAs($this->clerk)->post(route('documents.store'), [
        'barcode' => 'SCAN-1', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Private scan', 'is_confidential' => false, 'route_mode' => 'paper',
        'routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
        'document_type_id' => DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1])->id,
        'attached_file' => UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $document = Document::where('barcode', 'SCAN-1')->firstOrFail();

    Storage::disk('local')->assertExists($document->attached_file);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();

    $healthStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->health->id]);
    $this->actingAs($healthStaff)->get(route('documents.attachment', $document))->assertOk()
        ->assertHeader('content-disposition', 'inline; filename=SCAN-1.pdf');

    // An office that never held the document cannot open its scan, and a guest is sent to sign in.
    $outsider = User::factory()->create(['role' => 'staff', 'department_id' => $this->other->id]);
    $this->actingAs($outsider)->get(route('documents.attachment', $document))->assertForbidden();
    auth()->logout();
    $this->get(route('documents.attachment', $document))->assertRedirect(route('login'));
});

test('a document without a scan has no attachment to open', function () {
    $document = Document::create([
        'barcode' => 'NOSCAN-1', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'No scan', 'department_id' => $this->records->id,
        'document_type_id' => DocumentType::create(['document_code' => 'T', 'document_type' => 'Test', 'state' => 1])->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
    ]);
    DocumentTrail::create(['document_id' => $document->id, 'department_id' => $this->records->id, 'received_by' => $this->clerk->id,
        'received_at' => now(), 'received_action' => 'Registered']);

    $this->actingAs($this->clerk)->get(route('documents.attachment', $document))->assertNotFound();
});

test('the move migration takes existing public scans and signature images to the private disk', function () {
    Storage::disk('public')->put('documents/old.pdf', 'scan');
    Storage::disk('public')->put('signatures/old.png', 'image');
    Storage::disk('public')->put('other/keep.txt', 'not ours');

    $migration = require database_path('migrations/2026_11_10_000000_move_scans_and_signature_images_to_private_storage.php');
    $migration->up();

    expect(Storage::disk('local')->get('documents/old.pdf'))->toBe('scan')
        ->and(Storage::disk('local')->get('signatures/old.png'))->toBe('image');
    Storage::disk('public')->assertMissing('documents/old.pdf');
    Storage::disk('public')->assertMissing('signatures/old.png');
    Storage::disk('public')->assertExists('other/keep.txt');
});
