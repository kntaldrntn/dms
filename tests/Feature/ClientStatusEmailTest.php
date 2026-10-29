<?php

use App\Mail\ClientStatusUpdate;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 26)->setTime(10, 0));
    Mail::fake();
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    Department::create(['code' => 'ADM', 'name' => 'Office of the City Administrator', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Office of the City Health Officer', 'state' => 1]);
    $this->ict = Department::create(['code' => 'CICTO', 'name' => 'City ICT Office', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->payload = [
        'barcode' => 'C000000001', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Juan Dela Cruz',
        'email' => 'client@example.com', 'subject_matter' => 'Request for medical records', 'route_mode' => 'paper',
        'is_confidential' => false,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
        'document_type_id' => DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1])->id,
    ];
});

function registerForClient(array $extra = []): Document
{
    test()->actingAs(test()->records)->post(route('documents.store'), [...test()->payload, ...$extra])->assertSessionHasNoErrors();

    return Document::where('barcode', 'C000000001')->firstOrFail();
}

function sentStatuses(): array
{
    return Mail::sent(ClientStatusUpdate::class)->map(fn (ClientStatusUpdate $mail) => $mail->status)->all();
}

test('the client is emailed the latest status when Records registers and routes the document', function () {
    $document = registerForClient(['routed_to' => [$this->health->id, $this->ict->id], 'primary_office_id' => $this->health->id]);

    expect(sentStatuses())->toBe(['Being processed at the Office of the City Health Officer'])
        ->and($document->fresh()->client_notified_status)->toBe('Being processed at the Office of the City Health Officer');
    Mail::assertSent(ClientStatusUpdate::class, fn (ClientStatusUpdate $mail) => $mail->hasTo('client@example.com')
        && $mail->hasSubject('Document C000000001: Being processed at the Office of the City Health Officer'));
});

test('only status changes are emailed: a copy office receiving sends nothing, ending the transaction does', function () {
    $document = registerForClient(['routed_to' => [$this->health->id, $this->ict->id], 'primary_office_id' => $this->health->id]);

    $ictStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->ict->id]);
    $this->actingAs($ictStaff)->post(route('transactions.receive-copy', $document))->assertSessionHasNoErrors();
    expect(sentStatuses())->toHaveCount(1);

    $healthStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->health->id]);
    $this->actingAs($healthStaff)->post(route('transactions.receive', $document))->assertSessionHasNoErrors();
    expect(sentStatuses())->toHaveCount(1);
    $this->post(route('transactions.process', $document), [
        'action' => 'end', 'remarks' => 'Released to client', 'workflow_version' => $document->fresh()->workflow_version,
    ])
        ->assertSessionHasNoErrors();

    expect(sentStatuses())->toBe(['Being processed at the Office of the City Health Officer', 'Completed']);
});

test('documents for executive approval and those kept at Records get their own wording', function () {
    $administrator = Department::where('code', 'ADM')->first();
    registerForClient(['department_id' => $administrator->id]);
    expect(sentStatuses())->toBe(['For approval of the City Administrator']);

    Document::query()->delete();
    registerForClient();
    expect(sentStatuses())->toContain('Received by the Records Section');
});

test('documents without a client email send nothing', function () {
    registerForClient(['email' => null]);

    Mail::assertNothingSent();
});

test('a confidential document email gives the status but not the subject', function () {
    registerForClient(['is_confidential' => true, 'routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id]);

    Mail::assertSent(ClientStatusUpdate::class, function (ClientStatusUpdate $mail) {
        $html = $mail->render();

        return str_contains($html, 'C000000001') && ! str_contains($html, 'Request for medical records');
    });
});

test('a mail failure keeps the workflow step and leaves the status unclaimed for a retry', function () {
    Log::spy();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    $document = registerForClient(['routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id]);

    expect($document->department_id)->toBe($this->health->id)
        ->and($document->fresh()->client_notified_status)->toBeNull();
    Log::shouldHaveReceived('warning')->with('Client status email failed.', Mockery::type('array'));
});
