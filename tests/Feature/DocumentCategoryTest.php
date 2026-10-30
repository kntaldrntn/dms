<?php

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\TransactionType;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'Asia/Manila'));
    $this->records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->engineering = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    $this->simple = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);
    $this->complex = TransactionType::create(['name' => 'Complex', 'processing_days' => 7, 'state' => 1]);
    $this->document = Document::create([
        'barcode' => 'C000000001', 'access_code' => 'ABC123', 'transaction_type_id' => $this->simple->id,
        'department_id' => $this->engineering->id, 'source_name' => 'Client', 'subject_matter' => 'Request',
    ]);
    $this->document->update(['due_date' => $this->document->created_at->copy()->addWeekdays(3)]);
    // Engineering holds it as Primary: it is endorsed to Engineering.
    $this->document->trails()->create(['department_id' => $this->engineering->id, 'received_action' => 'Dispatched from Records']);
});

function changeCategoryAs(User $user, ?int $version = null)
{
    return test()->actingAs($user)->post(route('documents.category', test()->document), [
        'transaction_type_id' => test()->complex->id,
        'workflow_version' => $version ?? test()->document->fresh()->workflow_version,
    ]);
}

test('Records, the System Administrator and the Supervisory Level may change the category', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'department_id' => $this->engineering->id]);

    changeCategoryAs($user)->assertSessionHasNoErrors()->assertSessionHas('success', 'Category changed.');

    $document = $this->document->fresh();
    expect($document->transaction_type_id)->toBe($this->complex->id)
        ->and($document->due_date->toDateString())->toBe($document->created_at->copy()->addWeekdays(7)->toDateString());
    $log = AuditLog::where('model_type', Document::class)->where('model_id', $document->id)->sole();
    expect($log->action)->toBe('category_changed')
        ->and($log->actor_id)->toBe($user->id)
        ->and($log->old_values['transaction_type_id'])->toBe($this->simple->id)
        ->and($log->new_values['transaction_type_id'])->toBe($this->complex->id);
})->with(['records_manager', 'system_administrator', 'city_administrator', 'city_mayor', 'department_head']);

test('the Supervisory Level changes the category only on documents endorsed to them; Records on any', function () {
    $health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    foreach (['department_head', 'city_administrator', 'city_mayor'] as $role) {
        changeCategoryAs(User::factory()->create(['role' => $role, 'department_id' => $health->id]))->assertForbidden();
    }
    expect($this->document->fresh()->transaction_type_id)->toBe($this->simple->id);

    // Records works on any document, wherever it is.
    $records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->records->id]);
    changeCategoryAs($records)->assertSessionHasNoErrors();
});

test('Workstation staff cannot change the category, even in the office holding the document', function () {
    $staff = User::factory()->create(['role' => 'staff', 'department_id' => $this->engineering->id]);

    changeCategoryAs($staff)->assertForbidden();
    expect($this->document->fresh()->transaction_type_id)->toBe($this->simple->id);

    $this->get(route('documents.show', $this->document))
        ->assertInertia(fn (Assert $page) => $page->where('canChangeCategory', false));
});

test('a stale form, the same category and a completed document are refused', function () {
    $head = User::factory()->create(['role' => 'department_head', 'department_id' => $this->engineering->id]);
    $staleVersion = $this->document->fresh()->workflow_version;
    changeCategoryAs($head)->assertSessionHasNoErrors();

    changeCategoryAs($head, $staleVersion)->assertSessionHasErrors('workflow_version');
    changeCategoryAs($head)->assertSessionHasErrors(['transaction_type_id' => 'The document already has this category.']);

    $this->document->update(['status' => 'Completed']);
    changeCategoryAs($head)->assertForbidden();
});

test('the document page offers the change to allowed roles with the active categories', function () {
    $records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->records->id]);

    $this->actingAs($records)->get(route('documents.show', $this->document))
        ->assertInertia(fn (Assert $page) => $page->where('canChangeCategory', true)->has('categories', 2));
});
