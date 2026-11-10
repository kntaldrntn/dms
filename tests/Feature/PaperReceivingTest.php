<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 6)->setTime(10, 0));
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->other = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    $this->staff = User::factory()->create(['role' => 'staff', 'department_id' => $this->health->id]);
    $this->register = function (string $barcode, string $routeMode) use ($records) {
        test()->actingAs(User::factory()->create(['role' => 'records_manager', 'department_id' => $records->id]))->post(route('documents.store'), [
            'barcode' => $barcode, 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
            'subject_matter' => 'Paper check', 'is_confidential' => false, 'route_mode' => $routeMode,
            'routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id,
            'transaction_type_id' => TransactionType::firstOrCreate(['name' => 'Simple'], ['processing_days' => 3, 'state' => 1])->id,
            // Signed digitally in the rulebook, so it may take the paperless route when that is switched on.
            'document_type_id' => DocumentType::firstOrCreate(['document_code' => 'COM'], ['document_type' => 'Communications', 'state' => 1, 'signature_type' => 'digital'])->id,
            // Paperless documents travel as their scanned copy.
            ...($routeMode === 'paperless' ? ['attached_file' => UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf')] : []),
        ])->assertSessionHasNoErrors();

        return Document::where('barcode', $barcode)->firstOrFail();
    };
});

test('a paper document is an incoming notice until its barcode is scanned', function () {
    $document = ($this->register)('PAPER-1', 'paper');

    $this->actingAs($this->staff)->get(route('transactions.inbox'))->assertInertia(fn (Assert $page) => $page
        ->where('documents.data.0.receive_by_scan', true)
        ->where('documents.data.0.primary_entry.received_by', null));

    // The one-click Receive is refused for paper: nothing is received without the paper in hand.
    $this->post(route('transactions.receive', $document))->assertSessionHasErrors('document');
    expect($document->primaryEntry()->first()->received_by)->toBeNull();

    $this->post(route('transactions.receive-paper'), ['barcode' => '  PAPER-1 '])->assertSessionHasNoErrors();
    expect($document->primaryEntry()->first()->received_by)->toBe($this->staff->id);
});

test('a scan finds only documents waiting at the scanner\'s own office', function () {
    ($this->register)('PAPER-2', 'paper');

    $this->actingAs($this->staff)->post(route('transactions.receive-paper'), ['barcode' => 'NO-SUCH'])->assertSessionHasErrors('barcode');
    $this->post(route('transactions.receive-paper'), ['barcode' => ''])->assertSessionHasErrors('barcode');

    $elsewhere = User::factory()->create(['role' => 'staff', 'department_id' => $this->other->id]);
    $this->actingAs($elsewhere)->post(route('transactions.receive-paper'), ['barcode' => 'PAPER-2'])->assertSessionHasErrors('barcode');
    expect(DocumentTrail::whereNotNull('received_by')->where('department_id', $this->other->id)->exists())->toBeFalse();
});

test('a paperless document is still received with one click', function () {
    config(['services.paperless.enabled' => true]);
    $document = ($this->register)('DIGITAL-1', 'paperless');

    $this->actingAs($this->staff)->get(route('transactions.inbox'))
        ->assertInertia(fn (Assert $page) => $page->where('documents.data.0.receive_by_scan', false));
    $this->post(route('transactions.receive', $document))->assertSessionHasNoErrors();
    expect($document->primaryEntry()->first()->received_by)->toBe($this->staff->id);
});
