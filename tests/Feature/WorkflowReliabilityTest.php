<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\DispatchService;
use App\Services\DocumentService;
use App\Services\ReferralService;
use App\Services\TransactionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 9, 24)->setTime(10, 0));
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->adminOffice = Department::create(['code' => 'ADM', 'name' => 'Administrator', 'state' => 1]);
    $this->mayorOffice = Department::create(['code' => 'OCM', 'name' => 'City Mayor', 'state' => 1]);
    $this->target = Department::create(['code' => 'DST', 'name' => 'Destination', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->administrator = User::factory()->create(['role' => 'city_administrator', 'department_id' => $this->adminOffice->id]);
    $this->admHead = User::factory()->create(['role' => 'department_head', 'department_id' => $this->adminOffice->id]);
    $this->mayor = User::factory()->create(['role' => 'city_mayor', 'department_id' => $this->mayorOffice->id]);
    $this->systemAdministrator = User::factory()->create(['role' => 'system_administrator', 'department_id' => $this->adminOffice->id]);
    $this->staff = User::factory()->create(['department_id' => $this->target->id]);
    $type = DocumentType::create(['document_code' => 'TEST', 'document_type' => 'Test', 'state' => 1]);
    $transaction = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);
    $this->data = [
        'barcode' => 'WF-001', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Workflow test', 'department_id' => $this->recordsOffice->id,
        'document_type_id' => $type->id, 'transaction_type_id' => $transaction->id,
    ];
    $this->document = Document::create($this->data);
    $this->trail = DocumentTrail::create([
        'document_id' => $this->document->id, 'department_id' => $this->recordsOffice->id,
        'received_by' => $this->records->id, 'received_at' => now()->subHour(), 'received_action' => 'Registered',
    ]);
});

test('staff cannot use records intake or management actions even in their own office', function () {
    $this->staff->update(['department_id' => $this->recordsOffice->id]);
    $this->actingAs($this->staff)->postJson(route('documents.store'), $this->data)->assertForbidden();
    $this->get(route('documents.edit', $this->document))->assertForbidden();
    $this->putJson(route('documents.update', $this->document), [...$this->data, 'workflow_version' => 0])->assertForbidden();
    $this->deleteJson(route('documents.destroy', $this->document), ['workflow_version' => 0])->assertForbidden();
    $this->postJson(route('documents.route', $this->document), ['workflow_version' => 0, 'routed_to' => [$this->target->id]])->assertForbidden();
    $this->assertDatabaseCount('document_trails', 1);
    expect($this->document->fresh()->workflow_version)->toBe(0);
});

test('records management requires current custody', function () {
    $this->document->update(['department_id' => $this->target->id]);
    $this->trail->update(['department_id' => $this->target->id]);
    $this->actingAs($this->records)->get(route('documents.edit', $this->document))->assertForbidden();
    $this->putJson(route('documents.update', $this->document), [...$this->data, 'workflow_version' => 0])->assertForbidden();
    $this->deleteJson(route('documents.destroy', $this->document), ['workflow_version' => 0])->assertForbidden();
    $this->postJson(route('documents.route', $this->document), ['workflow_version' => 0, 'routed_to' => [$this->adminOffice->id]])->assertForbidden();
});

test('inactive users cannot read or mutate document workflows', function () {
    $this->records->update(['state' => 0]);
    $this->actingAs($this->records)->get(route('documents.index'))->assertForbidden();
    $this->get(route('documents.show', $this->document))->assertForbidden();
    $this->get(route('transactions.inbox'))->assertForbidden();
    $this->getJson(route('documents.searchBarcodes', ['q' => 'WF']))->assertForbidden();
    $this->postJson(route('documents.store'), $this->data)->assertForbidden();
    $this->postJson(route('transactions.process', $this->document), ['workflow_version' => 0, 'action' => 'end', 'remarks' => 'Done'])->assertForbidden();
});

test('active users can view the document registry while transactions remain department-scoped', function () {
    $this->actingAs($this->staff)->get(route('documents.show', $this->document))->assertOk();
    $this->getJson(route('documents.searchBarcodes', ['q' => 'WF']))->assertJsonPath('0.barcode', 'WF-001');
    $this->get(route('documents.index'))->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)->where('canCreate', false));
    $this->get(route('transactions.inbox'))->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));

    $this->document->update(['department_id' => $this->target->id]);
    $this->trail->update(['department_id' => $this->target->id]);
    $this->get(route('transactions.inbox'))->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));

    $this->document->update(['state' => 0]);
    $this->get(route('documents.show', $this->document))->assertForbidden();
    $this->getJson(route('documents.searchBarcodes', ['q' => 'WF']))->assertExactJson([]);
});

test('system administrators can view every active document', function () {
    $this->actingAs($this->systemAdministrator)->get(route('documents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
    $this->get(route('documents.show', $this->document))->assertOk();
    $this->getJson(route('documents.searchBarcodes', ['q' => 'WF']))
        ->assertJsonPath('0.barcode', 'WF-001');
});

test('dispatch rejects duplicate inactive self and empty target lists without writes', function () {
    $this->target->update(['state' => 0]);
    foreach ([[], [$this->recordsOffice->id], [$this->target->id], [$this->adminOffice->id, $this->adminOffice->id], [9999]] as $targets) {
        $this->actingAs($this->records)->postJson(route('documents.route', $this->document), [
            'workflow_version' => 0, 'routed_to' => $targets,
        ])->assertUnprocessable();
    }
    $this->assertDatabaseCount('document_trails', 1);
    expect($this->trail->fresh()->released_at)->toBeNull();
    expect($this->document->fresh()->workflow_version)->toBe(0);
});

test('inactive or completed documents cannot be edited dispatched processed or deleted', function (array $state) {
    $this->document->update($state);
    $this->actingAs($this->records)->get(route('documents.edit', $this->document))->assertStatus(409);
    $this->putJson(route('documents.update', $this->document), [...$this->data, 'workflow_version' => 0])->assertStatus(409);
    $this->postJson(route('documents.route', $this->document), ['workflow_version' => 0, 'routed_to' => [$this->target->id]])->assertStatus(409);
    $this->postJson(route('transactions.process', $this->document), ['workflow_version' => 0, 'action' => 'end', 'remarks' => 'Done'])->assertStatus(409);
    $this->deleteJson(route('documents.destroy', $this->document), ['workflow_version' => 0])->assertStatus(409);
    $this->assertDatabaseCount('document_trails', 1);
})->with([[['state' => 0]], [['status' => 'Completed']]]);

test('referrals resolve office codes rather than fixed ids and accept omitted remarks', function () {
    $this->document->update(['department_id' => $this->adminOffice->id]);
    $this->trail->update(['department_id' => $this->adminOffice->id]);
    $this->actingAs($this->administrator)->postJson(route('referral-slips.generate', $this->document), [
        'workflow_version' => 0, 'targets' => [$this->target->id],
        'required_actions' => ['please_handle', 'other'], 'other_action' => 'Coordinate with legal',
        'concerned_personnel' => 'Records Officer',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $document = $this->document->fresh();
    expect($document->department_id)->toBe($this->adminOffice->id);
    expect($document->requires_signature_from)->toBe('department_head');
    expect($document->approved_routing_slip)->toBe([$this->target->id]);
    expect($document->workflow_version)->toBe(1);
    $approvalTrail = DocumentTrail::where('document_id', $document->id)->where('released_action', 'Referral Slip Generated')->firstOrFail();
    expect($approvalTrail->required_actions)->toBe(['please_handle', 'other']);
    expect($approvalTrail->other_action)->toBe('Coordinate with legal');
    expect($approvalTrail->concerned_personnel)->toBe('Records Officer');
    $this->actingAs($this->administrator)->get(route('referral-slips.print', $document))
        ->assertOk()->assertSee('REFERRAL / ACTION SLIP')->assertSee('Coordinate with legal');
    $this->actingAs($this->records)->get(route('referral-slips.print', $document))->assertForbidden();
    $count = DocumentTrail::count();
    // A generated slip cannot be regenerated while it waits for the Department Head.
    $this->actingAs($this->administrator)->postJson(route('referral-slips.generate', $this->document), ['workflow_version' => 1, 'targets' => [$this->target->id], 'required_actions' => ['please_handle']])->assertForbidden();
    $this->assertDatabaseCount('document_trails', $count);

    $this->actingAs($this->admHead)->postJson(route('referral-slips.sign', $this->document), ['workflow_version' => 1, 'signature_mode' => 'physical'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $document = $this->document->fresh();
    expect($document->department_id)->toBe($this->recordsOffice->id);
    expect($document->requires_signature_from)->toBe('none');
    $this->actingAs($this->records)->get(route('referral-slips.print', $document))
        ->assertOk()->assertSee('REFERRAL / ACTION SLIP');
});

test('referrals enforce role custody active state and signature availability', function () {
    $payload = ['workflow_version' => 0, 'targets' => [$this->target->id], 'required_actions' => ['please_handle']];
    $this->actingAs($this->records)->postJson(route('referral-slips.generate', $this->document), $payload)->assertForbidden();
    $this->actingAs($this->administrator)->postJson(route('referral-slips.generate', $this->document), $payload)->assertForbidden();
    $this->document->update(['department_id' => $this->adminOffice->id]);
    $this->actingAs($this->admHead)->postJson(route('referral-slips.generate', $this->document), $payload)->assertForbidden();
    $this->actingAs($this->administrator);
    $this->document->update(['state' => 0]);
    $this->postJson(route('referral-slips.generate', $this->document), $payload)->assertStatus(409);
    $this->assertDatabaseCount('document_trails', 1);
});

test('referral configuration failure leaves no partial trails or custody changes', function () {
    $this->document->update(['department_id' => $this->adminOffice->id]);
    Department::create(['code' => 'ADM', 'name' => 'Duplicate Administrator', 'state' => 1]);
    $this->actingAs($this->administrator)->postJson(route('referral-slips.generate', $this->document), [
        'workflow_version' => 0, 'targets' => [$this->target->id], 'required_actions' => ['please_handle'],
    ])->assertUnprocessable()->assertJsonValidationErrors('department_id');
    $this->assertDatabaseCount('document_trails', 1);
    expect($this->document->fresh()->department_id)->toBe($this->adminOffice->id);
});

test('dispatch and referral failures roll back trail modifications and new arrivals', function (string $operation) {
    $user = $this->records;
    if ($operation === 'referral') {
        $this->document->update(['department_id' => $this->adminOffice->id]);
        $this->trail->update(['department_id' => $this->adminOffice->id]);
        $user = $this->administrator;
    }
    $originalOffice = $this->document->department_id;
    $event = 'eloquent.updating: '.Document::class;
    app('events')->listen($event, fn () => throw new RuntimeException('Simulated write failure'));
    try {
        $action = fn () => $operation === 'dispatch'
            ? app(DispatchService::class)->dispatch($this->document, $user, ['workflow_version' => 0, 'routed_to' => [$this->target->id]])
            : app(ReferralService::class)->generate($this->document, $user, ['workflow_version' => 0, 'targets' => [$this->target->id], 'required_actions' => ['please_handle']]);
        expect($action)->toThrow(RuntimeException::class, 'Simulated write failure');
    } finally {
        app('events')->forget($event);
    }
    $this->assertDatabaseCount('document_trails', 1);
    expect($this->trail->fresh()->released_at)->toBeNull();
    expect($this->document->fresh()->department_id)->toBe($originalOffice);
    expect($this->document->fresh()->workflow_version)->toBe(0);
})->with(['dispatch', 'referral']);

test('stale edits cannot overwrite a newer edit or deactivate the document', function () {
    $this->actingAs($this->records)->putJson(route('documents.update', $this->document), [
        ...$this->data, 'workflow_version' => 0, 'subject_matter' => 'First edit',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->putJson(route('documents.update', $this->document), [
        ...$this->data, 'workflow_version' => 0, 'subject_matter' => 'Stale edit',
    ])->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->deleteJson(route('documents.destroy', $this->document), ['workflow_version' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    expect($this->document->fresh()->subject_matter)->toBe('First edit');
    expect($this->document->fresh()->state)->toBe(1);
    expect($this->document->fresh()->workflow_version)->toBe(1);
});

test('stale dispatch is rejected even after custody returns to the original office', function () {
    app(DispatchService::class)->dispatch($this->document, $this->records, ['workflow_version' => 0, 'routed_to' => [$this->target->id]]);
    app(TransactionService::class)->process($this->document, $this->staff, [
        'workflow_version' => 1, 'action' => 'route', 'remarks' => 'Return', 'routed_to' => $this->recordsOffice->id,
    ]);
    $count = DocumentTrail::count();
    $this->actingAs($this->records)->postJson(route('documents.route', $this->document), [
        'workflow_version' => 0, 'routed_to' => [$this->target->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->assertDatabaseCount('document_trails', $count);
    expect($this->document->fresh()->workflow_version)->toBe(2);
});

test('destination and user state are rechecked inside the service', function () {
    $this->target->update(['state' => 0]);
    expect(fn () => app(DispatchService::class)->dispatch($this->document, $this->records, [
        'workflow_version' => 0, 'routed_to' => [$this->target->id],
    ]))->toThrow(ValidationException::class);
    User::whereKey($this->records->id)->update(['state' => 0]);
    expect(fn () => app(DispatchService::class)->dispatch($this->document, $this->records, [
        'workflow_version' => 0, 'routed_to' => [$this->target->id],
    ]))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('document_trails', 1);
});

test('document creation cleans uploaded files and rows if its initial trail fails', function () {
    Storage::fake('public');
    $event = 'eloquent.creating: '.DocumentTrail::class;
    app('events')->listen($event, fn () => throw new RuntimeException('Trail failed'));
    try {
        expect(fn () => app(DocumentService::class)->save(null, $this->records, [
            ...$this->data, 'barcode' => 'NEW', 'attached_file' => UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf'),
        ]))->toThrow(RuntimeException::class, 'Trail failed');
    } finally {
        app('events')->forget($event);
    }
    $this->assertDatabaseCount('documents', 1);
    $this->assertDatabaseCount('document_trails', 1);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('reassignment appends custody history without rewriting the original office', function () {
    $this->actingAs($this->records)->putJson(route('documents.update', $this->document), [
        ...$this->data, 'workflow_version' => 0, 'department_id' => $this->target->id, 'linked_documents' => 'A001 B002',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->trail->fresh()->department_id)->toBe($this->recordsOffice->id);
    expect($this->trail->fresh()->received_by)->toBe($this->records->id);
    expect($this->trail->fresh()->released_to)->toBe($this->target->id);
    expect($this->document->fresh()->linked_documents)->toBe(['A001', 'B002']);
    expect($this->document->fresh()->workflow_version)->toBe(1);
    $this->assertDatabaseCount('document_trails', 2);
});

test('creation without a destination retains records custody', function () {
    $payload = [...$this->data, 'barcode' => 'NEW', 'department_id' => null];
    $this->actingAs($this->records)->postJson(route('documents.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $created = Document::where('barcode', 'NEW')->firstOrFail();
    expect($created->department_id)->toBe($this->recordsOffice->id);
    expect($created->trails()->count())->toBe(1);
    expect($created->workflow_version)->toBe(0);
});

test('every existing-document mutation requires the version from the form', function () {
    $this->actingAs($this->records)->putJson(route('documents.update', $this->document), $this->data)
        ->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->deleteJson(route('documents.destroy', $this->document))->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->postJson(route('documents.route', $this->document), ['routed_to' => [$this->target->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->postJson(route('transactions.process', $this->document), ['action' => 'end', 'remarks' => 'Done'])
        ->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->document->update(['department_id' => $this->adminOffice->id]);
    $this->actingAs($this->administrator)->postJson(route('referral-slips.generate', $this->document), ['targets' => [$this->target->id], 'required_actions' => ['please_handle']])
        ->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->assertDatabaseCount('document_trails', 1);
});

test('deactivation closes custody and increments the version exactly once', function () {
    $this->actingAs($this->records)->deleteJson(route('documents.destroy', $this->document), ['workflow_version' => 0])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($this->document->fresh()->state)->toBe(0);
    expect($this->document->fresh()->workflow_version)->toBe(1);
    expect($this->trail->fresh()->released_action)->toBe('Document deactivated');
    $this->deleteJson(route('documents.destroy', $this->document), ['workflow_version' => 0])->assertStatus(409);
    expect($this->document->fresh()->workflow_version)->toBe(1);
});

test('reassignment failure preserves metadata history and the previous attachment', function () {
    Storage::fake('public');
    Storage::disk('public')->put('documents/original.pdf', 'existing attachment');
    $this->document->update(['attached_file' => 'documents/original.pdf']);
    $event = 'eloquent.updating: '.Document::class;
    app('events')->listen($event, fn () => throw new RuntimeException('Update failed'));
    try {
        expect(fn () => app(DocumentService::class)->save($this->document, $this->records, [
            ...$this->data, 'workflow_version' => 0, 'department_id' => $this->target->id,
            'attached_file' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf'),
        ]))->toThrow(RuntimeException::class, 'Update failed');
    } finally {
        app('events')->forget($event);
    }
    expect(Storage::disk('public')->allFiles())->toBe(['documents/original.pdf']);
    expect($this->document->fresh()->attached_file)->toBe('documents/original.pdf');
    expect($this->document->fresh()->department_id)->toBe($this->recordsOffice->id);
    expect($this->trail->fresh()->released_at)->toBeNull();
    $this->assertDatabaseCount('document_trails', 1);
});

test('signed referral attribution survives dispatch and the approved slip is consumed', function () {
    $this->document->update(['department_id' => $this->adminOffice->id]);
    $this->trail->update(['department_id' => $this->adminOffice->id, 'received_by' => $this->administrator->id]);
    $this->admHead->update(['signature_path' => 'signatures/head.png']);
    $this->actingAs($this->administrator)->postJson(route('referral-slips.generate', $this->document), [
        'workflow_version' => 0, 'targets' => [$this->target->id], 'required_actions' => ['please_handle'],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->admHead)->postJson(route('referral-slips.sign', $this->document), [
        'workflow_version' => 1, 'signature_mode' => 'electronic',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->records)->postJson(route('documents.route', $this->document), [
        'workflow_version' => 2, 'routed_to' => [$this->target->id],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $signing = DocumentTrail::where('document_id', $this->document->id)->whereNotNull('signature_mode')->firstOrFail();
    expect($signing->signed_by)->toBe($this->admHead->id);
    expect($signing->is_digitally_signed)->toBeTrue();
    expect($signing->signature_mode)->toBe('electronic');
    expect($signing->released_to)->toBe($this->recordsOffice->id);
    expect($this->document->fresh()->approved_routing_slip)->toBeNull();
    expect($this->document->fresh()->workflow_version)->toBe(3);
});

test('only the ADM Department Head signs a generated slip and E-Signature requires an uploaded signature', function () {
    $this->document->update(['department_id' => $this->adminOffice->id]);
    app(ReferralService::class)->generate($this->document, $this->administrator, [
        'workflow_version' => 0, 'targets' => [$this->target->id], 'required_actions' => ['please_handle'],
    ]);
    $otherHead = User::factory()->create(['role' => 'department_head', 'department_id' => $this->target->id]);
    $payload = ['workflow_version' => 1, 'signature_mode' => 'electronic'];
    foreach ([$this->administrator, $this->records, $otherHead] as $user) {
        $this->actingAs($user)->postJson(route('referral-slips.sign', $this->document), $payload)->assertForbidden();
    }
    $count = DocumentTrail::count();
    $this->actingAs($this->admHead)->postJson(route('referral-slips.sign', $this->document), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('signature_mode');
    $this->postJson(route('referral-slips.sign', $this->document), ['workflow_version' => 1, 'signature_mode' => 'stamp'])
        ->assertUnprocessable()->assertJsonValidationErrors('signature_mode');
    $this->assertDatabaseCount('document_trails', $count);
    expect($this->document->fresh()->requires_signature_from)->toBe('department_head');

    $this->actingAs($this->records)->postJson(route('documents.route', $this->document), [
        'workflow_version' => 1, 'routed_to' => [$this->target->id],
    ])->assertForbidden();

    $this->actingAs($this->admHead)->get(route('referral-slips.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
    $this->actingAs($this->administrator)->get(route('referral-slips.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
    $this->actingAs($otherHead)->get(route('referral-slips.index'))->assertForbidden();
});

test('a referral slip shows the ADM Department Head e-signature only after signing', function () {
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('signatures');
    $signature = UploadedFile::fake()->image('admin.png', 100, 30);
    Storage::disk('public')->put('signatures/admin.png', file_get_contents($signature->getPathname()));

    $this->document->update(['department_id' => $this->adminOffice->id]);
    $this->trail->update(['department_id' => $this->adminOffice->id, 'received_by' => $this->administrator->id]);
    $this->admHead->update(['signature_path' => 'signatures/admin.png']);

    $this->actingAs($this->administrator)->postJson(route('referral-slips.generate', $this->document), [
        'workflow_version' => 0, 'targets' => [$this->target->id], 'required_actions' => ['please_handle'],
    ])->assertRedirect()->assertSessionHasNoErrors();
    // Unsigned until the Department Head decides.
    $this->get(route('referral-slips.print', $this->document))->assertOk()->assertDontSee('/storage/signatures/admin.png');

    $this->actingAs($this->admHead)->postJson(route('referral-slips.sign', $this->document), [
        'workflow_version' => 1, 'signature_mode' => 'electronic',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($this->administrator)->get(route('referral-slips.print', $this->document))
        ->assertOk()
        ->assertSee('/storage/signatures/admin.png');

    $this->get(route('referral-slips.preview', $this->document))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('intake accepts an internal office id and hands the document to the City Administrator', function () {
    $this->actingAs($this->records)->postJson(route('documents.store'), [
        ...$this->data, 'barcode' => 'INTERNAL', 'source_type' => 'Internal',
        'source_location' => $this->target->id, 'department_id' => $this->adminOffice->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $created = Document::where('barcode', 'INTERNAL')->firstOrFail();
    expect($created->source_location)->toBe((string) $this->target->id);
    expect($created->department_id)->toBe($this->adminOffice->id);
    expect($created->requires_signature_from)->toBe('city_administrator');
    expect($created->trails()->count())->toBe(2);
    expect($created->trails()->oldest('id')->first()->released_to)->toBe($this->adminOffice->id);
    expect($created->trails()->latest('id')->first()->received_action)->toBe('Awaiting referral slip');
    $this->actingAs($this->administrator)->get(route('referral-slips.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
    expect($created->due_date->toDateString())->toBe('2026-09-29');
});

test('intake transfers an OCM approval document to the City Mayor office and queue', function () {
    $this->actingAs($this->records)->postJson(route('documents.store'), [
        ...$this->data,
        'barcode' => 'OCM-001',
        'department_id' => $this->mayorOffice->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $created = Document::where('barcode', 'OCM-001')->firstOrFail();

    expect($created->department_id)->toBe($this->mayorOffice->id)
        ->and($created->requires_signature_from)->toBe('city_mayor')
        ->and($created->trails()->count())->toBe(2);
    expect($created->trails()->oldest('id')->first()->released_to)->toBe($this->mayorOffice->id)
        ->and($created->trails()->oldest('id')->first()->released_action)->toBe('Submitted for City Mayor approval');
    expect($created->trails()->latest('id')->first()->department_id)->toBe($this->mayorOffice->id)
        ->and($created->trails()->latest('id')->first()->received_action)->toBe('Awaiting City Mayor approval');

    $this->actingAs($this->mayor)->get(route('ocm-approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)->where('documents.data.0.id', $created->id));
    $this->actingAs($this->administrator)->get(route('referral-slips.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
});

test('Records cannot dispatch a document while City Administrator review is pending', function () {
    $this->document->update(['requires_signature_from' => 'city_administrator']);

    $this->actingAs($this->records)->postJson(route('documents.route', $this->document), [
        'workflow_version' => 0,
        'routed_to' => [$this->target->id],
    ])->assertForbidden();

    expect($this->document->fresh()->department_id)->toBe($this->recordsOffice->id);
    expect($this->document->fresh()->workflow_version)->toBe(0);
    $this->assertDatabaseCount('document_trails', 1);
});

test('a multi-office dispatch gives every office its own actionable copy', function () {
    $second = Department::create(['code' => 'SEC', 'name' => 'Second Office', 'state' => 1]);
    $third = Department::create(['code' => 'THD', 'name' => 'Third Office', 'state' => 1]);
    $secondStaff = User::factory()->create(['department_id' => $second->id]);
    $thirdStaff = User::factory()->create(['department_id' => $third->id]);

    $this->actingAs($this->records)->postJson(route('documents.route', $this->document), [
        'workflow_version' => 0, 'routed_to' => [$this->target->id, $second->id, $third->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    // The dispatch release records every receiving office, and the trail table receives all of them.
    expect($this->trail->fresh()->released_to_offices)->toBe([$this->target->id, $second->id, $third->id]);
    $this->actingAs($this->records)->get(route('documents.show', $this->document))
        ->assertInertia(fn (Assert $page) => $page->where('trails.0.released_to_list.2.code', 'THD'));

    foreach ([$this->staff, $secondStaff, $thirdStaff] as $user) {
        $this->actingAs($user)->get(route('transactions.inbox'))
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
    }

    // The second office ends its copy using the version it loaded; the others still hold theirs.
    $this->actingAs($secondStaff)->postJson(route('transactions.process', $this->document), [
        'workflow_version' => 1, 'action' => 'end', 'remarks' => 'Noted',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->document->fresh()->status)->not->toBe('Completed');
    $this->actingAs($secondStaff)->get(route('transactions.inbox'))
        ->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
    $this->postJson(route('transactions.process', $this->document), [
        'workflow_version' => 2, 'action' => 'end', 'remarks' => 'Again',
    ])->assertForbidden();

    // The third office routes its copy onward, but not to an office that already holds one.
    $this->actingAs($thirdStaff)->postJson(route('transactions.process', $this->document), [
        'workflow_version' => 1, 'action' => 'route', 'remarks' => 'For action', 'routed_to' => $this->target->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('routed_to');
    $this->postJson(route('transactions.process', $this->document), [
        'workflow_version' => 1, 'action' => 'route', 'remarks' => 'For action', 'routed_to' => $second->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($secondStaff)->get(route('transactions.inbox'))
        ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));

    // Completed only after the last open copy ends.
    $this->actingAs($this->staff)->postJson(route('transactions.process', $this->document), [
        'workflow_version' => 0, 'action' => 'end', 'remarks' => 'Done',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->document->fresh()->status)->not->toBe('Completed');
    $this->actingAs($secondStaff)->postJson(route('transactions.process', $this->document), [
        'workflow_version' => 0, 'action' => 'end', 'remarks' => 'Done',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->document->fresh()->status)->toBe('Completed');
    expect(DocumentTrail::where('document_id', $this->document->id)->whereNull('released_at')->count())->toBe(0);

    $this->actingAs($this->records)->get(route('documents.show', $this->document))
        ->assertInertia(fn (Assert $page) => $page->has('holders', 0));
});

test('deactivation closes every open copy of a multi-office document', function () {
    $second = Department::create(['code' => 'SEC', 'name' => 'Second Office', 'state' => 1]);
    app(DispatchService::class)->dispatch($this->document, $this->records, ['workflow_version' => 0, 'routed_to' => [$this->target->id, $second->id]]);
    // A Records-role user in a holding office may deactivate; every office's copy must close.
    $this->actingAs($this->records)->get(route('documents.show', $this->document))
        ->assertInertia(fn (Assert $page) => $page->has('holders', 2));
    $this->staff->update(['role' => 'records_manager']);
    $this->actingAs($this->staff)->deleteJson(route('documents.destroy', $this->document), ['workflow_version' => 1])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(DocumentTrail::where('document_id', $this->document->id)->whereNull('released_at')->count())->toBe(0);
});
