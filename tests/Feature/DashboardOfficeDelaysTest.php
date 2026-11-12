<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00:00', 'Asia/Manila'));
    $this->records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->engineering = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    $this->viewer = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->records->id]);
    $simple = TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1]);
    $complex = TransactionType::create(['name' => 'Complex', 'processing_days' => 7, 'state' => 1]);
    $type = DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1]);

    // Registered this month; "late" ones are past their due date and still open.
    $make = fn (string $barcode, Department $office, TransactionType $category, bool $late) => Document::create([
        'barcode' => $barcode, 'access_code' => 'ABC123', 'source_name' => 'Client', 'subject_matter' => "Subject {$barcode}",
        'transaction_type_id' => $category->id, 'document_type_id' => $type->id, 'department_id' => $office->id,
        'due_date' => $late ? now('Asia/Manila')->subDay() : now('Asia/Manila')->addDays(5),
    ]);
    $make('CHO-S1', $this->health, $simple, true);
    $make('CHO-S2', $this->health, $simple, true);
    $make('CHO-C1', $this->health, $complex, true);
    $make('ENG-S1', $this->engineering, $simple, true);
    $make('ENG-OK', $this->engineering, $simple, false);
});

test('the dashboard lists delayed documents per office and category, worst office first', function () {
    $this->actingAs($this->viewer)->get(route('dashboard', ['from' => '2026-11', 'to' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('officeDelays', 2)
            ->where('officeDelays.0.code', 'CHO')
            ->where('officeDelays.0.delayed', ['Simple' => 2, 'Complex' => 1, 'Highly Technical' => 0])
            ->where('officeDelays.0.delayed_total', 3)
            ->where('officeDelays.1.code', 'ENG')
            ->where('officeDelays.1.delayed_total', 1));
});

test('a by-office number opens that office\'s list', function () {
    $this->actingAs($this->viewer)->get(route('dashboard.report', [
        'from' => '2026-11', 'to' => '2026-11', 'transaction_category' => 'Simple', 'pipeline_status' => 'delayed', 'office' => $this->health->id,
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('title', 'Delayed Simple Documents (CHO)')
        ->has('rows', 2)
        ->where('rows', fn ($rows) => collect($rows)->pluck('department')->unique()->values()->all() === ['CHO']));
});

test('a report downloads as an Excel file with the same rows', function () {
    $response = $this->actingAs($this->viewer)->get(route('dashboard.report', [
        'from' => '2026-11', 'to' => '2026-11', 'transaction_category' => 'Simple', 'pipeline_status' => 'delayed', 'format' => 'xlsx',
    ]));

    $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('content-disposition'))->toContain('delayed-simple-documents-20261112.xlsx');

    $reader = new Reader;
    $reader->open($response->getFile()->getPathname());
    $values = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $values[] = $row->toArray();
        }
    }
    $reader->close();

    $headings = ['Date', 'Barcode', 'Document type', 'Source', 'Subject matter', 'Office', 'Total time (days)'];
    $headingRow = array_search($headings, $values, true);
    expect($values[1][0])->toBe('Delayed Simple Documents')
        ->and($headingRow)->not->toBeFalse()
        ->and(collect(array_slice($values, $headingRow + 1, 3))->pluck(1)->sort()->values()->all())->toBe(['CHO-S1', 'CHO-S2', 'ENG-S1']);
});

test('the report page links to its Excel download', function () {
    $this->actingAs($this->viewer)->get(route('dashboard.report', ['report' => 'todo']))
        ->assertInertia(fn (Assert $page) => $page->where('downloadUrl', fn (string $url) => str_contains($url, 'format=xlsx') && str_contains($url, 'report=todo')));
});
