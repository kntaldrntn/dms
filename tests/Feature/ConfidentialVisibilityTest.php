<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\IncomingDocumentService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Inside working hours: the inbox and approval queues use the working_hours middleware.
    $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'Asia/Manila'));
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->administrator = Department::create(['code' => 'ADM', 'name' => 'City Administrator', 'state' => 1]);
    $this->engineering = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    $simple = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);

    $this->makeDocument = function (string $barcode, Department $office, bool $confidential, ?string $stage = null) use ($simple): Document {
        $document = Document::create([
            'barcode' => $barcode, 'access_code' => 'SECRET', 'transaction_type_id' => $simple->id, 'is_confidential' => $confidential,
            'department_id' => $office->id, 'source_name' => 'Juan Dela Cruz', 'email' => 'juan@example.com',
            'subject_matter' => 'Sensitive personnel complaint',
        ]);
        $document->trails()->create([
            'department_id' => $this->recordsOffice->id, 'received_action' => 'Document Scanned & Registered', 'released_at' => now('Asia/Manila'),
        ]);
        $document->trails()->create([
            'department_id' => $office->id, 'received_at' => now('Asia/Manila'), 'received_action' => 'Dispatched from Records',
            'copy_role' => DocumentTrail::PRIMARY, 'stage' => $stage,
        ]);

        return $document;
    };
    $this->secret = ($this->makeDocument)('CONF-1', $this->engineering, true);
    $this->open = ($this->makeDocument)('OPEN-1', $this->engineering, false);
});

test('Workstation staff never see a confidential document their office holds, but can still act on it', function () {
    $staff = User::factory()->create(['role' => 'staff', 'department_id' => $this->engineering->id]);

    $this->actingAs($staff)->get(route('documents.show', $this->secret))->assertForbidden();
    $this->get(route('documents.show', $this->open))->assertOk();
    $this->get(route('documents.index'))->assertInertia(fn (Assert $page) => $page
        ->has('documents.data', 1)->where('documents.data.0.barcode', 'OPEN-1'));
    $this->getJson(route('documents.searchBarcodes', ['q' => 'CONF']))->assertExactJson([]);

    // The inbox keeps the row so the office can receive and route it, without its details.
    $this->get(route('transactions.inbox'))->assertInertia(fn (Assert $page) => $page
        ->has('documents.data', 2)
        ->where('documents.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['barcode'] === 'CONF-1'
            && ($row['details_hidden'] ?? false) === true
            && ! array_key_exists('subject_matter', $row) && ! array_key_exists('source_name', $row)
            && ! array_key_exists('email', $row) && ! array_key_exists('access_code', $row))
            && collect($rows)->contains(fn ($row) => $row['barcode'] === 'OPEN-1' && $row['subject_matter'] === 'Sensitive personnel complaint')));
    $this->post(route('transactions.receive', $this->secret))->assertSessionHasNoErrors();

    $bell = collect(app(IncomingDocumentService::class)->forUser($staff)['items'])->pluck('subject_matter', 'barcode');
    expect($bell->get('OPEN-1'))->toBe('Sensitive personnel complaint');
});

test('Records, the System Administrator and the Supervisory Level see confidential documents in full', function (string $role, string $office) {
    $user = User::factory()->create(['role' => $role, 'department_id' => Department::where('code', $office)->value('id')]);

    $this->actingAs($user)->get(route('documents.show', $this->secret))->assertOk();
    $this->get(route('documents.index'))->assertInertia(fn (Assert $page) => $page->has('documents.data', 2));
})->with([
    ['records_manager', 'REC'], ['staff', 'REC'], ['system_administrator', 'ENG'],
    ['city_administrator', 'ADM'], ['city_mayor', 'ADM'], ['department_head', 'ADM'],
]);

test('the bell and the ADM queue hide a confidential document\'s subject from ADM staff, not from the City Administrator', function () {
    $atAdm = ($this->makeDocument)('CONF-ADM', $this->administrator, true, DocumentTrail::STAGE_ADM_APPROVAL);
    $admStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->administrator->id]);
    $cityAdministrator = User::factory()->create(['role' => 'city_administrator', 'department_id' => $this->administrator->id]);

    $this->actingAs($admStaff)->get(route('adm-approvals.index'))->assertInertia(fn (Assert $page) => $page
        ->has('documents.data', 1)
        ->where('documents.data.0.barcode', 'CONF-ADM')
        ->where('documents.data.0.details_hidden', true)
        ->missing('documents.data.0.subject_matter'));
    $bell = collect(app(IncomingDocumentService::class)->forUser($admStaff)['items'])->firstWhere('barcode', 'CONF-ADM');
    expect($bell['subject_matter'])->toBe('Confidential document');

    $this->actingAs($cityAdministrator)->get(route('adm-approvals.index'))->assertInertia(fn (Assert $page) => $page
        ->where('documents.data.0.subject_matter', 'Sensitive personnel complaint')
        ->missing('documents.data.0.details_hidden'));
    expect($atAdm->fresh()->is_confidential)->toBeTrue();
});

test('a Workstation dashboard report counts its confidential documents without their details', function () {
    $staff = User::factory()->create(['role' => 'staff', 'department_id' => $this->engineering->id]);

    $this->actingAs($staff)->get(route('dashboard.report', ['report' => 'todo']))->assertInertia(fn (Assert $page) => $page
        ->has('rows', 2)
        ->where('rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['barcode'] === 'CONF-1' && $row['details_hidden'] === true
            && $row['subject_matter'] === 'Confidential document' && $row['source_name'] === null && $row['email'] === null)));
});
