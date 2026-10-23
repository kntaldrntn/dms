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
    // Inside working hours: approval routes use the working_hours middleware.
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 23)->setTime(10, 0));
    Storage::fake('local');
    Storage::fake('public');
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->adm = Department::create(['code' => 'ADM', 'name' => 'City Administrator', 'state' => 1]);
    $this->ocm = Department::create(['code' => 'OCM', 'name' => 'City Mayor', 'state' => 1]);
    $this->ict = Department::create(['code' => 'CICTO', 'name' => 'ICT Office', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->admStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->adm->id]);
    $this->cityAdministrator = User::factory()->create(['role' => 'city_administrator', 'department_id' => $this->adm->id]);

    $this->actingAs($this->records)->post(route('documents.store'), [
        'barcode' => 'ADM-1', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'For approval', 'route_mode' => 'paper', 'is_confidential' => false,
        'department_id' => $this->adm->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
        'document_type_id' => DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1])->id,
    ])->assertSessionHasNoErrors();
    $this->document = Document::where('barcode', 'ADM-1')->firstOrFail();
});

function receiveAtAdm($test)
{
    return $test->actingAs($test->admStaff)->post(route('adm-approvals.receive', $test->document), [
        'workflow_version' => $test->document->fresh()->workflow_version,
    ]);
}

function approveAtAdm($test, array $data)
{
    return $test->actingAs($test->cityAdministrator)->post(route('adm-approvals.approve', $test->document), [
        'workflow_version' => $test->document->fresh()->workflow_version, ...$data,
    ]);
}

test('ADM users see the queue and other offices do not', function () {
    foreach ([$this->admStaff, $this->cityAdministrator] as $user) {
        $this->actingAs($user)->get(route('adm-approvals.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)->where('documents.data.0.id', $this->document->id));
    }
    $ictStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->ict->id]);
    $this->actingAs($ictStaff)->get(route('adm-approvals.index'))->assertForbidden();
});

test('an ADM user receives first, then the City Administrator is pinged to approve', function () {
    approveAtAdm($this, ['target' => 'ocm', 'signed_scan' => UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf')])
        ->assertForbidden();

    receiveAtAdm($this)->assertRedirect()->assertSessionHasNoErrors();
    $entry = DocumentTrail::where('document_id', $this->document->id)->where('department_id', $this->adm->id)->whereNull('released_at')->firstOrFail();
    expect($entry->received_by)->toBe($this->admStaff->id)
        ->and($entry->stage)->toBe(DocumentTrail::STAGE_ADM_APPROVAL);
    receiveAtAdm($this)->assertForbidden();

    $this->actingAs($this->admStaff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('incoming.count', 0));
    $this->actingAs($this->cityAdministrator)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('incoming.count', 1)->where('incoming.items.0.action', 'Ready for City Administrator approval'));

    // Staff receive but never approve.
    $this->actingAs($this->admStaff)->post(route('adm-approvals.approve', $this->document), [
        'workflow_version' => $this->document->fresh()->workflow_version, 'target' => 'ocm',
        'signed_scan' => UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf'),
    ])->assertForbidden();
});

test('a paper approval keeps the wet-signed scan and routes straight to the departments', function () {
    receiveAtAdm($this);
    approveAtAdm($this, [
        'target' => 'departments', 'routed_to' => [$this->ict->id, $this->health->id], 'primary_office_id' => $this->ict->id,
        'remarks' => 'Please act', 'signed_scan' => UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $document = $this->document->fresh();
    expect($document->department_id)->toBe($this->ict->id)
        ->and($document->stage)->toBeNull()
        ->and($document->requires_signature_from)->toBe('none');

    $approval = DocumentTrail::where('document_id', $document->id)->where('released_action', 'Approved by City Administrator')->firstOrFail();
    expect($approval->signed_by)->toBe($this->cityAdministrator->id)
        ->and($approval->signature_mode)->toBe('physical')
        ->and($approval->is_digitally_signed)->toBeFalse()
        ->and($approval->remarks)->toBe('Please act')
        ->and($approval->released_to)->toBe($this->ict->id);
    Storage::disk('local')->assertExists($approval->signed_file_path);

    $open = DocumentTrail::where('document_id', $document->id)->whereNull('released_at')->get();
    expect($open->pluck('copy_role', 'department_id')->all())->toEqual([
        $this->ict->id => DocumentTrail::PRIMARY,
        $this->health->id => DocumentTrail::COPY,
    ]);
    // It never goes back to Records.
    expect($open->pluck('department_id'))->not->toContain($this->recordsOffice->id);
});

test('approving to the City Mayor opens the OCM approval stage', function () {
    receiveAtAdm($this);
    approveAtAdm($this, ['target' => 'ocm', 'signed_scan' => UploadedFile::fake()->image('signed.jpg')])
        ->assertRedirect()->assertSessionHasNoErrors();

    $document = $this->document->fresh();
    expect($document->department_id)->toBe($this->ocm->id)
        ->and($document->stage)->toBe(DocumentTrail::STAGE_OCM_APPROVAL);
    $mayor = User::factory()->create(['role' => 'city_mayor', 'department_id' => $this->ocm->id]);
    $this->actingAs($mayor)->get(route('ocm-approvals.index'))
        ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
});

test('invalid approvals change nothing and leave no files behind', function (Closure $data, string $error) {
    receiveAtAdm($this);
    $version = $this->document->fresh()->workflow_version;

    approveAtAdm($this, $data($this))->assertSessionHasErrors($error);
    expect($this->document->fresh()->workflow_version)->toBe($version)
        ->and($this->document->fresh()->stage)->toBe(DocumentTrail::STAGE_ADM_APPROVAL);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'paper without the signed scan' => [fn ($test) => ['target' => 'ocm'], 'signed_scan'],
    'ADM picked as a department' => [fn ($test) => ['target' => 'departments', 'routed_to' => [$test->adm->id], 'primary_office_id' => $test->adm->id, 'signed_scan' => UploadedFile::fake()->image('s.jpg')], 'routed_to.0'],
    'a stamp placement on the paper route' => [fn ($test) => ['target' => 'ocm', 'signed_scan' => UploadedFile::fake()->image('s.jpg'), 'stamp_placement' => ['page' => 1, 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 100]], 'stamp_placement'],
    'confidential to two offices' => [function ($test) {
        $test->document->update(['is_confidential' => true]);

        return ['target' => 'departments', 'routed_to' => [$test->ict->id, $test->health->id], 'primary_office_id' => $test->ict->id, 'signed_scan' => UploadedFile::fake()->image('s.jpg')];
    }, 'routed_to'],
]);

test('a paperless approval stamps the City Administrator e-signature on the PDF without a certificate', function () {
    $pdf = new TCPDF;
    $pdf->AddPage();
    $pdf->Write(0, 'Letter for approval');
    Storage::disk('public')->put('documents/scan.pdf', $pdf->Output('scan.pdf', 'S'));
    $this->cityAdministrator->update(['signature_path' => UploadedFile::fake()->image('signature.png', 300, 120)->store('signatures', 'public')]);
    $this->document->update(['route_mode' => 'paperless', 'attached_file' => 'documents/scan.pdf']);
    receiveAtAdm($this);

    approveAtAdm($this, ['target' => 'ocm', 'stamp_placement' => ['page' => 1, 'x' => 600, 'y' => 800, 'width' => 300, 'height' => 105]])
        ->assertRedirect()->assertSessionHasNoErrors();

    $approval = DocumentTrail::where('document_id', $this->document->id)->where('released_action', 'Approved by City Administrator')->firstOrFail();
    expect($approval->signature_mode)->toBe('electronic')
        ->and($approval->signed_file_path)->toStartWith('signed-documents/ADM-1-adm-approved-');
    expect(Storage::disk('local')->get($approval->signed_file_path))->toStartWith('%PDF');
    // OCM will stamp (and measure placement on) this ADM-signed PDF, not the original scan.
    expect($this->document->fresh()->stampSourceUrl())->toBe(route('documents.ocm-approved-pdf', $this->document));
});

test('a paperless approval needs the City Administrator e-signature on file', function () {
    $this->document->update(['route_mode' => 'paperless', 'attached_file' => 'documents/scan.pdf']);
    Storage::disk('public')->put('documents/scan.pdf', '%PDF-1.4');
    receiveAtAdm($this);

    approveAtAdm($this, ['target' => 'ocm', 'stamp_placement' => ['page' => 1, 'x' => 600, 'y' => 800, 'width' => 300, 'height' => 105]])
        ->assertSessionHasErrors('stamp_placement');
    expect($this->document->fresh()->stage)->toBe(DocumentTrail::STAGE_ADM_APPROVAL);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
