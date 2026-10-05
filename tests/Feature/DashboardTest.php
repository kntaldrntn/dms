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
