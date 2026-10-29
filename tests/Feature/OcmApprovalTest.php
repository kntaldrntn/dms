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
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 29)->setTime(10, 0));
    Storage::fake('local');
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    Department::create(['code' => 'ADM', 'name' => 'City Administrator', 'state' => 1]);
    $this->ocm = Department::create(['code' => 'OCM', 'name' => 'City Mayor', 'state' => 1]);
    $this->ict = Department::create(['code' => 'CICTO', 'name' => 'ICT Office', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->ocmStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->ocm->id]);
    $this->mayor = User::factory()->create(['role' => 'city_mayor', 'department_id' => $this->ocm->id]);

    $this->actingAs($records)->post(route('documents.store'), [
        'barcode' => 'OCM-1', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'For the Mayor', 'is_confidential' => false, 'department_id' => $this->ocm->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
        'document_type_id' => DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1])->id,
    ])->assertSessionHasNoErrors();
    $this->document = Document::where('barcode', 'OCM-1')->firstOrFail();
});

function receiveAtOcm($test, ?User $user = null)
{
    return $test->actingAs($user ?? $test->ocmStaff)->post(route('ocm-approvals.receive', $test->document), [
        'workflow_version' => $test->document->fresh()->workflow_version,
    ]);
}

function approveAtOcm($test, User $user, array $data)
{
    return $test->actingAs($user)->post(route('ocm-approvals.approve', $test->document), [
        'workflow_version' => $test->document->fresh()->workflow_version, ...$data,
    ]);
}

test('OCM receives first, then the Mayor is pinged and any OCM user can record the paper approval', function () {
    foreach ([$this->ocmStaff, $this->mayor] as $user) {
        $this->actingAs($user)->get(route('ocm-approvals.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('documents.data.0.can_receive', true)
                ->where('documents.data.0.can_approve', false)->where('auth.canViewOcmApprovals', true));
        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('incoming.count', 1));
    }
    approveAtOcm($this, $this->ocmStaff, ['routed_to' => [$this->ict->id], 'primary_office_id' => $this->ict->id])->assertForbidden();

    receiveAtOcm($this)->assertRedirect()->assertSessionHasNoErrors();
    $entry = DocumentTrail::where('document_id', $this->document->id)->where('department_id', $this->ocm->id)->whereNull('released_at')->firstOrFail();
    expect($entry->received_by)->toBe($this->ocmStaff->id)->and($entry->received_action)->toBe('Received by OCM');
    receiveAtOcm($this)->assertForbidden();

    $this->actingAs($this->ocmStaff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('incoming.count', 0));
    $this->actingAs($this->mayor)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('incoming.count', 1)->where('incoming.items.0.action', 'Ready for City Mayor approval'));
    foreach ([$this->ocmStaff, $this->mayor] as $user) {
        $this->actingAs($user)->get(route('ocm-approvals.index'))
            ->assertInertia(fn (Assert $page) => $page->where('documents.data.0.can_approve', true)->where('documents.data.0.is_paperless', false));
    }
    $ictStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->ict->id]);
    $this->actingAs($ictStaff)->get(route('ocm-approvals.index'))->assertForbidden();
    approveAtOcm($this, $ictStaff, ['routed_to' => [$this->ict->id], 'primary_office_id' => $this->ict->id])->assertForbidden();
});

test('OCM staff record the Mayor paper approval and it goes straight to the departments', function () {
    receiveAtOcm($this);
    approveAtOcm($this, $this->ocmStaff, [
        'routed_to' => [$this->ict->id, $this->health->id], 'primary_office_id' => $this->ict->id, 'remarks' => 'Signed by the Mayor',
        'signed_scan' => UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $document = $this->document->fresh();
    expect($document->department_id)->toBe($this->ict->id)->and($document->stage)->toBeNull();

    $approval = DocumentTrail::where('document_id', $document->id)->where('released_action', 'Approved by City Mayor')->firstOrFail();
    expect($approval->released_by)->toBe($this->ocmStaff->id)
        ->and($approval->signed_by)->toBeNull()
        ->and($approval->signature_mode)->toBe('physical')
        ->and($approval->remarks)->toBe('Signed by the Mayor');
    Storage::disk('local')->assertExists($approval->signed_file_path);

    $open = DocumentTrail::where('document_id', $document->id)->whereNull('released_at')->get();
    expect($open->pluck('copy_role', 'department_id')->all())->toEqual([
        $this->ict->id => DocumentTrail::PRIMARY,
        $this->health->id => DocumentTrail::COPY,
    ])->and($open->pluck('department_id'))->not->toContain($this->recordsOffice->id);
});

test('the scan is optional and the Mayor recording it is the signer', function () {
    receiveAtOcm($this);
    approveAtOcm($this, $this->mayor, ['routed_to' => [$this->ict->id], 'primary_office_id' => $this->ict->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    $approval = DocumentTrail::where('document_id', $this->document->id)->where('released_action', 'Approved by City Mayor')->firstOrFail();
    expect($approval->signed_by)->toBe($this->mayor->id)->and($approval->signed_file_path)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('invalid OCM approvals change nothing', function (array $data, string $error) {
    receiveAtOcm($this);
    $version = $this->document->fresh()->workflow_version;

    approveAtOcm($this, $this->ocmStaff, $data)->assertSessionHasErrors($error);
    expect($this->document->fresh()->workflow_version)->toBe($version)
        ->and($this->document->fresh()->stage)->toBe(DocumentTrail::STAGE_OCM_APPROVAL);
})->with([
    'no departments' => [[], 'routed_to'],
    'OCM picked as a department' => [['routed_to' => [3], 'primary_office_id' => 3], 'routed_to.0'],
    'Primary not among the offices' => [['routed_to' => [4], 'primary_office_id' => 5], 'primary_office_id'],
    'a PIN on the paper route' => [['routed_to' => [4], 'primary_office_id' => 4, 'certificate_pin' => '1234'], 'certificate_pin'],
]);

test('a paperless OCM approval stays with the City Mayor and needs the certificate PIN', function () {
    config(['services.paperless.enabled' => true]);
    $this->document->update(['route_mode' => 'paperless']);
    receiveAtOcm($this);

    approveAtOcm($this, $this->ocmStaff, ['routed_to' => [$this->ict->id], 'primary_office_id' => $this->ict->id])->assertForbidden();
    approveAtOcm($this, $this->mayor, ['routed_to' => [$this->ict->id], 'primary_office_id' => $this->ict->id])
        ->assertSessionHasErrors(['certificate_pin', 'stamp_placement']);
    expect($this->document->fresh()->stage)->toBe(DocumentTrail::STAGE_OCM_APPROVAL);
});
