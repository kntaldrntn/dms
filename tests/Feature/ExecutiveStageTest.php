<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\DocumentWorkflow;
use Illuminate\Support\Facades\Route;
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
        'subject_matter' => 'Stage test', 'department_id' => $this->admin->id,
        'document_type_id' => DocumentType::create(['document_code' => 'T', 'document_type' => 'Test', 'state' => 1])->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
    ]);
    $this->entry = DocumentTrail::create([
        'document_id' => $this->document->id, 'department_id' => $this->admin->id, 'stage' => DocumentTrail::STAGE_ADM_APPROVAL,
        'received_at' => now('Asia/Manila'), 'received_action' => 'Awaiting City Administrator approval',
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

test('the notification bell pings the whole office holding a staged entry, which records the paper approval', function () {
    $mayor = User::factory()->create(['role' => 'city_mayor', 'department_id' => $this->other->id]);
    $otherStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->other->id]);
    $elsewhere = User::factory()->create(['role' => 'staff', 'department_id' => $this->recordsManager->department_id]);
    $this->entry->update(['department_id' => $this->other->id, 'stage' => DocumentTrail::STAGE_OCM_APPROVAL]);

    foreach ([$mayor, $otherStaff] as $user) {
        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('incoming.count', 1)->where('incoming.items.0.url', route('ocm-approvals.index')));
    }
    $this->actingAs($elsewhere)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('incoming.count', 0));
});

test('retiring referral slips moves a slip waiting for a signature to the City Administrator approval', function () {
    $migration = require database_path('migrations/2026_10_23_030000_retire_referral_slips.php');
    $migration->down();
    $this->entry->update(['stage' => 'adm_signature']);
    $closed = DocumentTrail::create([
        'document_id' => $this->document->id, 'department_id' => $this->admin->id, 'stage' => 'adm_signature',
        'received_at' => now('Asia/Manila'), 'released_at' => now('Asia/Manila'), 'received_action' => 'Old entry',
    ]);

    $migration->up();

    expect($this->entry->fresh()->stage)->toBe(DocumentTrail::STAGE_ADM_APPROVAL)
        // History is never rewritten: closed entries keep what they recorded.
        ->and($closed->fresh()->stage)->toBe('adm_signature')
        ->and(Schema::hasColumn('documents', 'requires_signature_from'))->toBeFalse();
    expect(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'referral-slips.')))->toBeEmpty();
});
