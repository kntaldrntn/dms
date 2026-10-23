<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
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

test('clicking a dashboard category opens that category report', function () {
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
        ->get(route('dashboard.report', [
            'month' => 'October',
            'year' => 2026,
            'transaction_category' => 'Simple',
            'pipeline_status' => 'on_going',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Report')
            ->where('title', 'On-Going Simple Documents')
            ->where('dateRange', '10/01/2026 to 10/31/2026')
            ->has('rows', 1)
            ->where('rows.0.barcode', 'DB-SIMPLE'));

    $this->get(route('dashboard.report', [
        'month' => 'October',
        'transaction_category' => 'Simple',
        'pipeline_status' => 'on_time',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('title', 'On-Time Simple Documents')
        ->has('rows', 0));
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

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('delinquent.value', 1)->where('delinquent.todo', 1));
    $this->get(route('dashboard.report', ['report' => 'delinquent']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('title', 'Delinquent Documents (All Departments)')
            ->has('rows', 1)
            ->where('rows.0.barcode', 'GLOBAL-DELINQUENT'));
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

    $this->actingAs($staff)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('delinquent.scope', 'office')
            ->where('delinquent.value', 1)
            ->where('delinquent.todo', 1));
    $this->get(route('dashboard.report', ['report' => 'delinquent']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('title', 'Delinquent Documents (Your Office)')
            ->has('rows', 1)
            ->where('rows.0.barcode', 'ENG-LATE'));

    $administrator = User::factory()->create(['role' => 'city_administrator', 'department_id' => $engineering->id]);
    $this->actingAs($administrator)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('delinquent.scope', 'all')->where('delinquent.value', 2));
});

test('the Due Tomorrow and To-Do cards open the documents they count', function () {
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Manila'));
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $user = User::factory()->create(['role' => 'records_manager', 'department_id' => $records->id]);
    $transactionType = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);
    foreach (['DUE-TOMORROW' => '2026-10-06 15:00:00', 'DUE-LATER' => '2026-10-09 15:00:00'] as $barcode => $dueDate) {
        Document::create([
            'barcode' => $barcode, 'access_code' => 'ACCESS1', 'transaction_type_id' => $transactionType->id,
            'department_id' => $records->id, 'source_name' => 'Test Client', 'subject_matter' => 'Due soon',
        ])->forceFill(['due_date' => Carbon::parse($dueDate, 'Asia/Manila')])->save();
    }

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('delinquent.dueTomorrow', 1)->where('delinquent.todo', 2));

    $this->get(route('dashboard.report', ['report' => 'due_tomorrow']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('title', 'Due Tomorrow (All Departments)')
            ->where('dateRange', 'Due 10/06/2026')
            ->has('rows', 1)
            ->where('rows.0.barcode', 'DUE-TOMORROW'));

    $this->get(route('dashboard.report', ['report' => 'todo']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('title', 'To-Do List (All Departments)')
            ->where('dateRange', 'As of 10/05/2026')
            ->has('rows', 2));

    $this->get(route('dashboard.report', ['report' => 'unknown']))->assertNotFound();
    $this->get(route('dashboard.report', ['transaction_category' => 'Simple', 'pipeline_status' => 'unknown']))->assertNotFound();
});

test('the year picker reports a past year and ignores years without documents', function () {
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Manila'));
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $user = User::factory()->create(['department_id' => $records->id]);
    Document::create([
        'barcode' => 'LAST-YEAR', 'access_code' => 'ACCESS1', 'department_id' => $records->id,
        'source_type' => 'External', 'source_name' => 'Test Client', 'subject_matter' => 'Old',
    ])->forceFill(['created_at' => Carbon::parse('2025-03-10 09:00:00', 'Asia/Manila')])->save();

    $this->actingAs($user)->get(route('dashboard', ['month' => 'March', 'year' => 2025]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('years', [2026, 2025])
            ->where('selectedYear', 2025)
            ->where('summaryCards.0.value', '1'));

    $this->get(route('dashboard', ['month' => 'March', 'year' => 2019]))
        ->assertInertia(fn (Assert $page) => $page->where('selectedYear', 2026)->where('summaryCards.0.value', '0'));
});

test('processing times list every document type of the chosen category, with zero days when none completed', function () {
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Manila'));
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $user = User::factory()->create(['department_id' => $records->id]);
    $simple = TransactionType::create(['name' => 'Simple Transaction', 'processing_days' => 3, 'state' => 1]);
    $complex = TransactionType::create(['name' => 'Complex Transaction', 'processing_days' => 7, 'state' => 1]);
    $voucher = DocumentType::create(['document_code' => 'VOU', 'document_type' => 'Voucher', 'state' => 1, 'default_transaction_type_id' => $simple->id]);
    DocumentType::create(['document_code' => 'PAY', 'document_type' => 'Payroll', 'state' => 1, 'default_transaction_type_id' => $simple->id]);
    DocumentType::create(['document_code' => 'PPR', 'document_type' => 'Project Proposal', 'state' => 1, 'default_transaction_type_id' => $complex->id]);
    foreach (['VOU-1' => 2, 'VOU-2' => 4] as $barcode => $days) {
        Document::create([
            'barcode' => $barcode, 'access_code' => 'ACCESS1', 'department_id' => $records->id, 'document_type_id' => $voucher->id,
            'transaction_type_id' => $simple->id, 'source_name' => 'Test Client', 'subject_matter' => 'Voucher',
        ])->forceFill([
            'status' => 'Completed',
            // As the app saves them: created_at in real UTC, completed_at in Manila wall-clock time.
            'created_at' => Carbon::parse('2026-10-05 00:00:00', 'UTC'),
            'completed_at' => Carbon::parse('2026-10-05 08:00:00', 'Asia/Manila')->addDays($days),
        ])->save();
    }

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('processingCategory', 'Simple')
            ->has('processingTimes', 2)
            ->where('processingTimes.0.label', 'Payroll')->where('processingTimes.0.value', 0)
            ->where('processingTimes.1.label', 'Voucher')->where('processingTimes.1.value', 3));

    $this->get(route('dashboard', ['processing_category' => 'Complex']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('processingTimes', 1)
            ->where('processingTimes.0.label', 'Project Proposal')->where('processingTimes.0.value', 0));
});

test('a report row carries the DTS columns and the footer names who generated it, in Manila time', function () {
    $this->travelTo(Carbon::parse('2026-10-23 23:30:00', 'Asia/Manila'));
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $user = User::factory()->create(['name' => 'Kent Aldrin Tan', 'role' => 'records_manager', 'department_id' => $records->id]);
    $type = DocumentType::create(['document_code' => 'AOC', 'document_type' => 'Abstract of Canvass', 'state' => 1]);
    Document::create([
        'barcode' => 'AOC0008027', 'access_code' => 'ACCESS1', 'department_id' => $records->id, 'document_type_id' => $type->id,
        'source_type' => 'External', 'source_name' => 'Astro Alley', 'gender' => 'Male', 'contact_no' => '09170000000',
        'email' => 'astro@example.com', 'subject_matter' => 'AOC on procurement',
    ])->forceFill(['created_at' => Carbon::parse('2026-10-22 23:30:00', 'Asia/Manila')->utc()])->save();

    $this->actingAs($user)->get(route('dashboard.report', ['report' => 'todo']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Report')
            ->where('rows.0', fn ($row) => $row['date'] === 'Oct. 22, 2026' && $row['document_code'] === 'AOC'
                && $row['source_type'] === 'EXT' && $row['gender'] === 'Male' && $row['contact_no'] === '09170000000'
                && $row['email'] === 'astro@example.com' && $row['department'] === 'REC' && $row['total_days'] == 1.0)
            ->where('generatedBy', 'Kent Aldrin Tan')
            ->where('generatedOn', 'Oct 23, 2026'));
});

test('guests cannot open a dashboard report', function () {
    $this->get(route('dashboard.report', ['report' => 'todo']))->assertRedirect('/login');
});
