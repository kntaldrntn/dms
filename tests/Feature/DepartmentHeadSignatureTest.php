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
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 29)->setTime(10, 0));
    Storage::fake('local');
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $recordsManager = User::factory()->create(['role' => 'records_manager', 'department_id' => $records->id]);
    $this->staff = User::factory()->create(['role' => 'staff', 'department_id' => $this->health->id]);
    // The head has no e-signature image: the paper route records the wet signature only.
    $this->head = User::factory()->create(['role' => 'department_head', 'department_id' => $this->health->id, 'signature_path' => null]);

    $this->actingAs($recordsManager)->post(route('documents.store'), [
        'barcode' => 'SIG-1', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Needs the head', 'is_confidential' => false,
        'routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
        'document_type_id' => DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1])->id,
    ])->assertSessionHasNoErrors();
    $this->document = Document::where('barcode', 'SIG-1')->firstOrFail();
    $this->actingAs($this->staff)->post(route('transactions.receive', $this->document))->assertSessionHasNoErrors();
});

function endAtHealth($test, array $extra = [])
{
    return $test->actingAs($test->staff)->post(route('transactions.process', $test->document), [
        'workflow_version' => $test->document->fresh()->workflow_version, 'action' => 'end', 'remarks' => 'Signed and released', ...$extra,
    ]);
}

function healthRelease($test): DocumentTrail
{
    return DocumentTrail::where('document_id', $test->document->id)->where('department_id', $test->health->id)->whereNotNull('released_at')->firstOrFail();
}

test('staff record the Department Head wet signature with an optional scan', function () {
    endAtHealth($this, ['head_signed' => '1', 'signed_scan' => UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf')])
        ->assertRedirect()->assertSessionHasNoErrors();

    $release = healthRelease($this);
    expect($release->signed_by)->toBe($this->head->id)
        ->and($release->signature_mode)->toBe('physical')
        ->and($release->is_digitally_signed)->toBeFalse()
        ->and($release->released_by)->toBe($this->staff->id);
    Storage::disk('local')->assertExists($release->signed_file_path);
});

test('the scan of the signed page is optional', function () {
    endAtHealth($this, ['head_signed' => '1'])->assertSessionHasNoErrors();
    expect(healthRelease($this)->signed_by)->toBe($this->head->id)->and(healthRelease($this)->signed_file_path)->toBeNull();
});

test('without the tick no signer is recorded and a stray scan is ignored', function () {
    endAtHealth($this, ['signed_scan' => UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();

    expect(healthRelease($this)->signed_by)->toBeNull()->and(healthRelease($this)->signature_mode)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('an office without an active Department Head cannot record one, and nothing is saved', function () {
    $this->head->update(['state' => 0]);

    endAtHealth($this, ['head_signed' => '1', 'signed_scan' => UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('head_signed');
    expect($this->document->fresh()->status)->not->toBe('Completed');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
