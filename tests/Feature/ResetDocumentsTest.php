<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\Person;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    Storage::fake('certificates');
    $this->office = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->user = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->office->id, 'signature_path' => 'signatures/records.png']);
    $this->person = Person::create(['first_name' => 'Gilbert', 'last_name' => 'Caloza']);
    $this->type = DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1]);
    $document = Document::create([
        'barcode' => 'RESET-1', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Old data', 'department_id' => $this->office->id, 'attached_file' => 'documents/scan.pdf',
        'document_type_id' => $this->type->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
    ]);
    DocumentTrail::create([
        'document_id' => $document->id, 'department_id' => $this->office->id, 'signed_file_path' => 'signed-documents/old.pdf',
        'received_at' => now('Asia/Manila'), 'received_action' => 'Registered',
    ]);
    Storage::disk('public')->put('documents/scan.pdf', 'scan');
    Storage::disk('public')->put('signatures/records.png', 'signature');
    Storage::disk('local')->put('signed-documents/old.pdf', 'signed');
    Storage::disk('certificates')->put('users/1.p12', 'certificate');
});

function documentDataIsIntact(): void
{
    expect(Document::count())->toBe(1)->and(DocumentTrail::count())->toBe(1);
    Storage::disk('public')->assertExists('documents/scan.pdf');
    Storage::disk('local')->assertExists('signed-documents/old.pdf');
}

test('a dry run lists what would be deleted and changes nothing', function () {
    $this->artisan('dms:reset-documents', ['--dry-run' => true])
        ->expectsOutputToContain('This permanently deletes')
        ->expectsTable(['What', 'Count'], [
            ['documents', 1], ['document trail entries', 1],
            ['uploaded scans (storage/app/public/documents)', 1], ['signed files (storage/app/private/signed-documents)', 1],
        ])
        ->expectsOutput('Dry run: nothing was changed.')
        ->assertSuccessful()
        ->run();

    documentDataIsIntact();
});

test('nothing is deleted unless RESET is typed', function () {
    $this->artisan('dms:reset-documents')
        ->expectsQuestion('Type RESET to delete all document data', 'reset')
        ->expectsOutput('Cancelled: nothing was changed.')
        ->assertFailed()
        ->run();

    documentDataIsIntact();
});

test('a confirmed reset deletes documents, trails and their files and keeps everything else', function (array $options, ?string $answer) {
    $command = $this->artisan('dms:reset-documents', $options);
    if ($answer) {
        $command->expectsQuestion('Type RESET to delete all document data', $answer);
    }
    $command->expectsOutput('Done: all document data was deleted.')->assertSuccessful()->run();

    expect(Document::count())->toBe(0)->and(DocumentTrail::count())->toBe(0);
    Storage::disk('public')->assertMissing('documents/scan.pdf');
    Storage::disk('local')->assertMissing('signed-documents/old.pdf');

    // Kept: offices, users, registries, People Directory, e-signatures, certificates.
    expect(Department::count())->toBe(1)
        ->and(User::count())->toBe(1)
        ->and(DocumentType::count())->toBe(1)
        ->and(Person::count())->toBe(1);
    Storage::disk('public')->assertExists('signatures/records.png');
    Storage::disk('certificates')->assertExists('users/1.p12');
})->with([
    'typed confirmation' => [[], 'RESET'],
    '--force' => [['--force' => true], null],
]);

test('the unused referral slip column is dropped', function () {
    expect(Schema::hasColumn('documents', 'approved_routing_slip'))->toBeFalse();
});
