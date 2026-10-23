<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Inside working hours: intake routes use the working_hours middleware.
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 23)->setTime(10, 0));
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->administrator = Department::create(['code' => 'ADM', 'name' => 'City Administrator', 'state' => 1]);
    $this->ict = Department::create(['code' => 'CICTO', 'name' => 'ICT Office', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->payload = [
        'barcode' => 'DEPT-1', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Straight to departments', 'route_mode' => 'paper', 'is_confidential' => false,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
        'document_type_id' => DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1])->id,
    ];
});

function registerTo(array $extra)
{
    return test()->actingAs(test()->records)->post(route('documents.store'), [...test()->payload, ...$extra]);
}

test('intake can send a document straight to a Primary office with view-only copies', function () {
    registerTo(['routed_to' => [$this->health->id, $this->ict->id], 'primary_office_id' => $this->ict->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    $document = Document::where('barcode', 'DEPT-1')->firstOrFail();
    expect($document->department_id)->toBe($this->ict->id)
        ->and($document->stage)->toBeNull();

    $open = DocumentTrail::where('document_id', $document->id)->whereNull('released_at')->get();
    expect($open->pluck('copy_role', 'department_id')->all())->toEqual([
        $this->ict->id => DocumentTrail::PRIMARY,
        $this->health->id => DocumentTrail::COPY,
    ]);
    $registration = DocumentTrail::where('document_id', $document->id)->where('department_id', $this->recordsOffice->id)->firstOrFail();
    expect($registration->released_to)->toBe($this->ict->id)
        ->and($registration->released_to_offices)->toEqual([$this->health->id, $this->ict->id]);

    // The Primary office acts on it from its inbox; the copy office only sees its copy.
    $ictStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->ict->id]);
    $this->actingAs($ictStaff)->get(route('transactions.inbox'))
        ->assertInertia(fn (Assert $page) => $page->where('documents.data.0.id', $document->id));
    $healthStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->health->id]);
    $this->actingAs($healthStaff)->get(route('transactions.inbox'))
        ->assertInertia(fn (Assert $page) => $page->has('documents.data', 0)->where('copies.data.0.id', $document->id));
});

test('intake to departments is checked before anything is saved', function (Closure $extra, string $error) {
    registerTo($extra($this))->assertSessionHasErrors($error);
    $this->assertDatabaseCount('documents', 0);
    $this->assertDatabaseCount('document_trails', 0);
})->with([
    'both an executive office and departments' => [fn ($test) => ['department_id' => $test->administrator->id, 'routed_to' => [$test->ict->id], 'primary_office_id' => $test->ict->id], 'department_id'],
    'ADM picked as a department' => [fn ($test) => ['routed_to' => [$test->administrator->id], 'primary_office_id' => $test->administrator->id], 'routed_to.0'],
    'Records picked as a department' => [fn ($test) => ['routed_to' => [$test->recordsOffice->id], 'primary_office_id' => $test->recordsOffice->id], 'routed_to.0'],
    'Primary not among the offices' => [fn ($test) => ['routed_to' => [$test->ict->id], 'primary_office_id' => $test->health->id], 'primary_office_id'],
    'no Primary chosen' => [fn ($test) => ['routed_to' => [$test->ict->id]], 'primary_office_id'],
]);

test('a confidential document goes to one department only', function () {
    registerTo(['is_confidential' => true, 'routed_to' => [$this->ict->id, $this->health->id], 'primary_office_id' => $this->ict->id])
        ->assertSessionHasErrors('routed_to');
    $this->assertDatabaseCount('documents', 0);

    registerTo(['is_confidential' => true, 'routed_to' => [$this->ict->id], 'primary_office_id' => $this->ict->id])
        ->assertSessionHasNoErrors();
    expect(Document::firstOrFail()->department_id)->toBe($this->ict->id);
});

test('editing a registration cannot route it to departments', function () {
    // Kept by Records (no target), so Records may still edit the registration.
    registerTo([])->assertSessionHasNoErrors();
    $document = Document::firstOrFail();
    expect($document->department_id)->toBe($this->recordsOffice->id);

    $this->put(route('documents.update', $document), [
        ...$this->payload, 'workflow_version' => $document->workflow_version,
        'routed_to' => [$this->ict->id], 'primary_office_id' => $this->ict->id,
    ])->assertSessionHasErrors(['routed_to', 'primary_office_id']);
});
