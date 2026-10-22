<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Inside working hours: processing routes use the working_hours middleware.
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 22)->setTime(10, 0));
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->primaryOffice = Department::create(['code' => 'PRI', 'name' => 'Primary Office', 'state' => 1]);
    $this->copyOffice = Department::create(['code' => 'CPY', 'name' => 'Copy Office', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->primaryStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->primaryOffice->id]);
    $this->copyStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->copyOffice->id]);
    $this->document = Document::create([
        'barcode' => 'PC-001', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Primary and copies', 'department_id' => $this->recordsOffice->id,
        'document_type_id' => DocumentType::create(['document_code' => 'T', 'document_type' => 'Test', 'state' => 1])->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
    ]);
    DocumentTrail::create([
        'document_id' => $this->document->id, 'department_id' => $this->recordsOffice->id,
        'received_by' => $this->records->id, 'received_at' => now('Asia/Manila'), 'received_action' => 'Registered',
    ]);
});

function dispatchToBoth($test, array $extra = [])
{
    return $test->actingAs($test->records)->postJson(route('documents.route', $test->document), [
        'workflow_version' => 0, 'routed_to' => [$test->primaryOffice->id, $test->copyOffice->id],
        'primary_office_id' => $test->primaryOffice->id, ...$extra,
    ]);
}

test('the Primary office must be one of the selected offices', function () {
    dispatchToBoth($this, ['primary_office_id' => $this->recordsOffice->id])
        ->assertUnprocessable()->assertJsonValidationErrors('primary_office_id');
    $this->assertDatabaseCount('document_trails', 1);
});

test('a copy office is pinged, receives its copy once, and is never pinged again', function () {
    dispatchToBoth($this)->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($this->copyStaff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('incoming.count', 1)
        ->where('incoming.items.0.action', 'Copy from Records')
        ->where('incoming.items.0.url', route('transactions.inbox', ['copy' => $this->document->id])));

    $this->postJson(route('transactions.receive-copy', $this->document))->assertRedirect()->assertSessionHasNoErrors();
    $copy = DocumentTrail::where('department_id', $this->copyOffice->id)->firstOrFail();
    expect($copy->received_by)->toBe($this->copyStaff->id)
        ->and($copy->released_at)->toBeNull()
        ->and($copy->received_action)->toBe('Copy received');

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('incoming.count', 0));
    $this->get(route('transactions.inbox'))->assertInertia(fn (Assert $page) => $page->where('copies.data.0.copy_received', true));
    $this->postJson(route('transactions.receive-copy', $this->document))->assertForbidden();

    // The Primary office cannot "receive a copy"; it acts on the document instead.
    $this->actingAs($this->primaryStaff)->postJson(route('transactions.receive-copy', $this->document))->assertForbidden();
});

test('confidential documents go to the Primary office only', function () {
    $this->document->update(['is_confidential' => true]);
    dispatchToBoth($this)->assertUnprocessable()->assertJsonValidationErrors('routed_to');
    $this->assertDatabaseCount('document_trails', 1);

    $this->actingAs($this->records)->postJson(route('documents.route', $this->document), [
        'workflow_version' => 0, 'routed_to' => [$this->primaryOffice->id],
    ])->assertRedirect()->assertSessionHasNoErrors();
});

test('Records or the Supervisory Level can change the Primary office and the old one keeps a copy', function () {
    dispatchToBoth($this)->assertSessionHasNoErrors();

    // Workstation users cannot change it.
    $this->actingAs($this->copyStaff)->postJson(route('documents.primary-office', $this->document), ['primary_office_id' => $this->copyOffice->id])
        ->assertForbidden();

    $head = User::factory()->create(['role' => 'department_head', 'department_id' => $this->copyOffice->id]);
    $this->actingAs($head)->postJson(route('documents.primary-office', $this->document), ['primary_office_id' => $this->copyOffice->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    $document = $this->document->fresh();
    expect($document->department_id)->toBe($this->copyOffice->id);
    $open = DocumentTrail::where('document_id', $document->id)->whereNull('released_at')->get();
    expect($open->pluck('copy_role', 'department_id')->all())->toEqual([
        $this->primaryOffice->id => 'copy',
        $this->copyOffice->id => 'primary',
    ]);
    // Both hand-offs are new trail entries; the change is visible in the history.
    expect(DocumentTrail::where('released_action', 'Primary changed to CPY')->where('released_by', $head->id)->exists())->toBeTrue();

    $this->actingAs($this->primaryStaff)->postJson(route('transactions.process', $document), [
        'workflow_version' => $document->workflow_version, 'action' => 'end', 'remarks' => 'Not mine anymore',
    ])->assertForbidden();
    $this->actingAs($this->copyStaff)->postJson(route('transactions.process', $document), [
        'workflow_version' => $document->workflow_version, 'action' => 'end', 'remarks' => 'Done',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($document->fresh()->status)->toBe('Completed');
});

test('delinquency and the inbox count only the Primary office', function () {
    dispatchToBoth($this)->assertSessionHasNoErrors();
    $this->travel(25)->hours();

    expect($this->document->fresh()->is_delinquent)->toBeTrue();
    expect(Document::heldBy($this->primaryOffice->id)->count())->toBe(1)
        ->and(Document::heldBy($this->copyOffice->id)->count())->toBe(0)
        ->and(Document::copiedTo($this->copyOffice->id)->count())->toBe(1);
});
