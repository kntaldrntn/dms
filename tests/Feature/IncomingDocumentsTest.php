<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\DispatchService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Inside working hours: processing routes use the working_hours middleware.
    $this->travelTo(now('Asia/Manila')->setDate(2026, 9, 24)->setTime(10, 0));
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->adminOffice = Department::create(['code' => 'ADM', 'name' => 'Administrator', 'state' => 1]);
    $this->target = Department::create(['code' => 'DST', 'name' => 'Destination', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->administrator = User::factory()->create(['role' => 'city_administrator', 'department_id' => $this->adminOffice->id]);
    $this->admHead = User::factory()->create(['role' => 'department_head', 'department_id' => $this->adminOffice->id]);
    $this->staff = User::factory()->create(['department_id' => $this->target->id]);
    $this->data = [
        'barcode' => 'IN-001', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Incoming test', 'department_id' => $this->recordsOffice->id,
        'document_type_id' => DocumentType::create(['document_code' => 'T', 'document_type' => 'Test', 'state' => 1])->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
    ];
    $this->document = Document::create($this->data);
    DocumentTrail::create([
        'document_id' => $this->document->id, 'department_id' => $this->recordsOffice->id,
        'received_by' => $this->records->id, 'received_at' => now()->subHour(), 'received_action' => 'Registered',
    ]);
});

test('a dispatched document pings the receiving office until it acts on its copy', function () {
    $this->actingAs($this->staff)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('incoming.count', 0));

    app(DispatchService::class)->dispatch($this->document, $this->records, ['workflow_version' => 0, 'routed_to' => [$this->target->id]]);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('incoming.count', 1)
        ->where('incoming.items.0.barcode', 'IN-001')
        ->where('incoming.items.0.action', 'Dispatched from Records')
        ->where('incoming.items.0.url', route('transactions.inbox', ['document' => $this->document->id])));
    // The sender's office is not pinged.
    $this->actingAs($this->records)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('incoming.count', 0));

    // Following the ping opens the inbox with that document ready for its Receive modal.
    $this->actingAs($this->staff)->get(route('transactions.inbox', ['document' => $this->document->id]))
        ->assertInertia(fn (Assert $page) => $page->where('focusDocument.id', $this->document->id));

    $this->postJson(route('transactions.process', $this->document), [
        'workflow_version' => 1, 'action' => 'end', 'remarks' => 'Received and done',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('incoming.count', 0));
    $this->get(route('transactions.inbox', ['document' => $this->document->id]))
        ->assertInertia(fn (Assert $page) => $page->where('focusDocument', null));
});

test('executive steps ping only the role that acts on them and stay out of the office inbox', function () {
    $this->actingAs($this->records)->postJson(route('documents.store'), [
        ...$this->data, 'barcode' => 'ADM-001', 'department_id' => $this->adminOffice->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $adm = Document::where('barcode', 'ADM-001')->firstOrFail();

    $this->actingAs($this->administrator)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('incoming.count', 1)
        ->where('incoming.items.0.url', route('referral-slips.index')));
    $this->actingAs($this->admHead)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('incoming.count', 0));

    // The Office Inbox neither lists nor processes a document awaiting its referral slip.
    $this->actingAs($this->administrator)->get(route('transactions.inbox'))
        ->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
    $this->postJson(route('transactions.process', $adm), [
        'workflow_version' => 0, 'action' => 'route', 'remarks' => 'Skip the slip', 'routed_to' => $this->target->id,
    ])->assertForbidden();
    expect($adm->fresh()->department_id)->toBe($this->adminOffice->id);
});
