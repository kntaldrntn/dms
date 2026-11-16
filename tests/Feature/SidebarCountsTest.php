<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Monday 10:00 Manila, inside working hours.
    $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 16)->setTime(10, 0));
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->adm = Department::create(['code' => 'ADM', 'name' => 'City Administrator', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->admStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->adm->id]);
    $this->healthStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->health->id]);
    $this->clerk = User::factory()->create(['role' => 'records_manager', 'department_id' => $records->id]);
    $this->register = function (string $barcode) {
        test()->actingAs($this->clerk)->post(route('documents.store'), [
            'barcode' => $barcode, 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
            'subject_matter' => 'Permit', 'is_confidential' => false, 'route_mode' => 'paper',
            'routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id,
            'transaction_type_id' => TransactionType::firstOrCreate(['name' => 'Simple'], ['processing_days' => 3, 'state' => 1])->id,
            'document_type_id' => DocumentType::firstOrCreate(['document_code' => 'COM'], ['document_type' => 'Communications', 'state' => 1])->id,
        ])->assertSessionHasNoErrors();

        return Document::where('barcode', $barcode)->firstOrFail();
    };
});

test('sidebar counts load after the page and show the office card', function () {
    ($this->register)('SIDE-1');

    $this->actingAs($this->healthStaff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.office', ['code' => 'CHO', 'name' => 'Health'])
        ->missing('sidebarCounts')
        ->loadDeferredProps('sidebar', fn (Assert $reload) => $reload
            ->where('sidebarCounts.transactions', ['open' => 1, 'delinquent' => 0])
            ->where('sidebarCounts.admApprovals', null)
            ->where('sidebarCounts.untaggedDelinquent', null)));
});

test('the inbox badge turns delinquent and the monitor counts untagged delinquent documents', function () {
    $document = ($this->register)('SIDE-2');
    $this->travel(25)->hours();

    $this->actingAs($this->healthStaff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('sidebar', fn (Assert $reload) => $reload
            ->where('sidebarCounts.transactions', ['open' => 1, 'delinquent' => 1])));

    $this->actingAs($this->admStaff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('sidebar', fn (Assert $reload) => $reload
            ->where('sidebarCounts.admApprovals', 0)
            ->where('sidebarCounts.untaggedDelinquent', 1)));

    // Once tagged it is no longer on the monitor's to-do.
    $this->post(route('delinquency.tag', $document), ['tag' => 'followed_up', 'note' => 'Called the office.'])->assertSessionHasNoErrors();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('sidebar', fn (Assert $reload) => $reload->where('sidebarCounts.untaggedDelinquent', 0)));
});
