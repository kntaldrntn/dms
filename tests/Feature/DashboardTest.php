<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\TransactionType;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get('/dashboard');
    $response->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200);
});

test('clicking a dashboard category returns that category report on the dashboard', function () {
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Manila'));

    $department = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $user = User::factory()->create(['department_id' => $department->id]);
    $simple = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);
    $complex = TransactionType::create(['name' => 'Complex', 'processing_days' => 5, 'state' => 1]);

    foreach ([['DB-SIMPLE', $simple], ['DB-COMPLEX', $complex]] as [$barcode, $transactionType]) {
        Document::create([
            'barcode' => $barcode,
            'access_code' => 'ACCESS1',
            'transaction_type_id' => $transactionType->id,
            'department_id' => $department->id,
            'source_name' => 'Test Client',
            'subject_matter' => 'Dashboard report test',
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard', [
            'month' => 'October',
            'transaction_category' => 'Simple',
            'pipeline_status' => 'on_going',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('selectedCategory', 'Simple')
            ->where('selectedPipeline', 'on_going')
            ->where('reportTitle', 'On-Going Simple Documents')
            ->has('drillDownDocuments.data', 1)
            ->where('drillDownDocuments.data.0.barcode', 'DB-SIMPLE'));

    $this->get(route('dashboard', [
        'month' => 'October',
        'transaction_category' => 'Simple',
        'pipeline_status' => 'on_time',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('reportTitle', 'On-Time Simple Documents')
        ->has('drillDownDocuments.data', 0));
});

test('the delinquent dashboard card and report include documents from every department for oversight roles', function () {
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Manila'));

    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $engineering = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    $user = User::factory()->create(['role' => 'records_manager', 'department_id' => $records->id]);
    $transactionType = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);

    $document = Document::create([
        'barcode' => 'GLOBAL-DELINQUENT',
        'access_code' => 'ACCESS1',
        'transaction_type_id' => $transactionType->id,
        'department_id' => $engineering->id,
        'source_name' => 'Test Client',
        'subject_matter' => 'A delinquent document held outside the viewer department',
    ]);
    $document->forceFill([
        'created_at' => Carbon::now('Asia/Manila')->subDays(6),
        'updated_at' => Carbon::now('Asia/Manila')->subDays(6),
    ])->save();

    $this->actingAs($user)
        ->get(route('dashboard', ['delinquent' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('delinquent.value', 1)
            ->where('delinquent.todo', 1)
            ->where('reportTitle', 'Delinquent Documents (All Departments)')
            ->has('drillDownDocuments.data', 1)
            ->where('drillDownDocuments.data.0.barcode', 'GLOBAL-DELINQUENT'));
});

test('other users see only delinquent documents their office holds', function () {
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Manila'));

    $engineering = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    $health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $staff = User::factory()->create(['role' => 'staff', 'department_id' => $engineering->id]);
    $transactionType = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);

    foreach ([['ENG-LATE', $engineering], ['CHO-LATE', $health]] as [$barcode, $office]) {
        $document = Document::create([
            'barcode' => $barcode, 'access_code' => 'ACCESS1', 'transaction_type_id' => $transactionType->id,
            'department_id' => $office->id, 'source_name' => 'Test Client', 'subject_matter' => 'Delinquent',
        ]);
        $document->forceFill(['created_at' => Carbon::now('Asia/Manila')->subDays(6)])->save();
        $document->trails()->create(['department_id' => $office->id, 'received_at' => Carbon::now('Asia/Manila')->subDays(6), 'received_action' => 'Dispatched from Records']);
    }

    $this->actingAs($staff)
        ->get(route('dashboard', ['delinquent' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('delinquent.scope', 'office')
            ->where('delinquent.value', 1)
            ->where('delinquent.todo', 1)
            ->where('reportTitle', 'Delinquent Documents (Your Office)')
            ->has('drillDownDocuments.data', 1)
            ->where('drillDownDocuments.data.0.barcode', 'ENG-LATE'));

    $administrator = User::factory()->create(['role' => 'city_administrator', 'department_id' => $engineering->id]);
    $this->actingAs($administrator)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('delinquent.scope', 'all')->where('delinquent.value', 2));
});
