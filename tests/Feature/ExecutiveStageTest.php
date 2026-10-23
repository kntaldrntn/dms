<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\DocumentWorkflow;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 23)->setTime(10, 0));
    $this->records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->admin = Department::create(['code' => 'ADM', 'name' => 'City Administrator', 'state' => 1]);
    $this->other = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->recordsManager = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->records->id]);
    $this->document = Document::create([
        'barcode' => 'STAGE-1', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'Stage test', 'department_id' => $this->admin->id, 'requires_signature_from' => 'city_administrator',
        'document_type_id' => DocumentType::create(['document_code' => 'T', 'document_type' => 'Test', 'state' => 1])->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
    ]);
    $this->entry = DocumentTrail::create([
        'document_id' => $this->document->id, 'department_id' => $this->admin->id, 'stage' => DocumentTrail::STAGE_ADM_APPROVAL,
        'received_at' => now('Asia/Manila'), 'received_action' => 'Awaiting referral slip',
    ]);
});

test('the stage comes from the open Primary entry, eager-loaded or not', function () {
    expect($this->document->fresh()->stage)->toBe(DocumentTrail::STAGE_ADM_APPROVAL)
        ->and(Document::with('primaryEntry')->find($this->document->id)->stage)->toBe(DocumentTrail::STAGE_ADM_APPROVAL)
        ->and(Document::inStage([DocumentTrail::STAGE_ADM_APPROVAL])->count())->toBe(1)
        ->and(Document::outsideExecutiveStages()->count())->toBe(0);

    $this->entry->update(['released_at' => now('Asia/Manila')]);
    expect($this->document->fresh()->stage)->toBeNull()
        ->and(Document::outsideExecutiveStages()->count())->toBe(1);
});

test('a view-only copy never carries a stage', function () {
    app(DocumentWorkflow::class)->receive($this->document, $this->other->id, 'Copy', DocumentTrail::COPY, DocumentTrail::STAGE_ADM_APPROVAL);

    expect(DocumentTrail::where('department_id', $this->other->id)->value('stage'))->toBeNull();
});

test('the Primary office cannot be changed while an approval is pending', function () {
    $this->actingAs($this->recordsManager)->postJson(route('documents.primary-office', $this->document), ['primary_office_id' => $this->other->id])
        ->assertForbidden();
    expect($this->document->fresh()->department_id)->toBe($this->admin->id);

    // The same change is allowed once nothing is pending.
    $this->entry->update(['stage' => null]);
    $this->postJson(route('documents.primary-office', $this->document), ['primary_office_id' => $this->other->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($this->document->fresh()->department_id)->toBe($this->other->id);
});

test('the notification bell pings only the role that acts on a staged entry', function () {
    $mayor = User::factory()->create(['role' => 'city_mayor', 'department_id' => $this->other->id]);
    $otherStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->other->id]);
    $this->entry->update(['department_id' => $this->other->id, 'stage' => DocumentTrail::STAGE_OCM_APPROVAL]);

    $this->actingAs($mayor)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('incoming.count', 1)->where('incoming.items.0.url', route('ocm-approvals.index')));
    $this->actingAs($otherStaff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('incoming.count', 0));
});

test('the migration backfills the stage from requires_signature_from', function () {
    $migration = require database_path('migrations/2026_10_23_010000_add_stage_to_document_trails_table.php');
    $migration->down();
    $migration->up();

    expect($this->entry->fresh()->stage)->toBe(DocumentTrail::STAGE_ADM_APPROVAL);
    expect(Schema::hasColumn('document_trails', 'stage'))->toBeTrue();
});
