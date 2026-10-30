<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\TransactionType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->engineering = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    $simple = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);

    // One document per case, each with its open Primary trail entry where it is held.
    foreach ([['AT-RECORDS', $this->recordsOffice, 'Pending'], ['AT-ENG', $this->engineering, 'Pending'], ['DONE', $this->recordsOffice, 'Completed']] as [$barcode, $office, $status]) {
        $document = Document::create([
            'barcode' => $barcode, 'access_code' => 'ABC123', 'transaction_type_id' => $simple->id,
            'department_id' => $office->id, 'source_name' => 'Client', 'subject_matter' => 'Request',
        ]);
        $document->forceFill(['status' => $status])->save();
        $document->trails()->create(['department_id' => $office->id, 'received_action' => 'Registered', 'copy_role' => DocumentTrail::PRIMARY]);
    }
});

function rowActionsByBarcode(User $user, string $action): array
{
    $documents = [];
    test()->actingAs($user)->get(route('documents.index'))
        ->assertInertia(function (Assert $page) use (&$documents, $action) {
            $documents = collect($page->toArray()['props']['documents']['data'])->pluck($action, 'barcode')->all();
        });

    return $documents;
}

test('Records sees Edit and Route only on active documents its office holds as Primary', function (string $action) {
    $records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);

    expect(rowActionsByBarcode($records, $action))->toEqualCanonicalizing(['DONE' => false, 'AT-ENG' => false, 'AT-RECORDS' => true]);
})->with(['can_edit', 'can_route']);

test('users outside Records never see Edit or Route, even on documents their office holds', function (string $action) {
    $engineeringStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->engineering->id]);
    $recordsStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->recordsOffice->id]);

    expect(array_filter(rowActionsByBarcode($engineeringStaff, $action)))->toBe([])
        ->and(array_filter(rowActionsByBarcode($recordsStaff, $action)))->toBe([]);
})->with(['can_edit', 'can_route']);

test('a completed document with no open trail entry offers neither Route nor Edit to its last office', function () {
    // Like C003192158: every entry closed, department_id still the office that ended it.
    $done = Document::where('barcode', 'DONE')->first();
    $done->trails()->update(['released_at' => now()]);
    $done->update(['department_id' => $this->recordsOffice->id]);
    $records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);

    expect(rowActionsByBarcode($records, 'can_route')['DONE'])->toBeFalse()
        ->and(rowActionsByBarcode($records, 'can_edit')['DONE'])->toBeFalse();
});
