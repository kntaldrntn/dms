<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// What the Smart gateway answered for a sent message on 2026-11-04.
const OK_REPLY = '0 001 OK Message-ID: 55264562250';

beforeEach(function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 4)->setTime(10, 0));
    Mail::fake();
    Http::preventStrayRequests();
    config(['services.smart_sms' => ['url' => 'https://sms.test/sendmsg', 'api_id' => 'test-id', 'api_key' => 'test-key']]);
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $records->id]);
    $this->payload = [
        'barcode' => 'C000000001', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Juan Dela Cruz',
        'subject_matter' => 'Request for medical records', 'is_confidential' => false,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
        'document_type_id' => DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1])->id,
        'routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id,
    ];
});

function registerWithContact(?string $contact): Document
{
    test()->actingAs(test()->records)->post(route('documents.store'), [...test()->payload, 'contact_no' => $contact])->assertSessionHasNoErrors();

    return Document::where('barcode', 'C000000001')->firstOrFail();
}

test('the client gets their barcode and access code by SMS through the Smart gateway', function () {
    Http::fake(['sms.test/*' => Http::response(OK_REPLY, 200)]);

    registerWithContact('0917 123 4567');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://sms.test/sendmsg'
        && $request->method() === 'POST'
        && $request->hasHeader('X-MEMS-API-ID', 'test-id')
        && $request->hasHeader('X-MEMS-API-KEY', 'test-key')
        && $request->hasHeader('Accept', 'text/plain')
        && $request->isForm()
        && $request['destination'] === '639171234567'
        && str_contains($request['text'], 'Barcode: C000000001')
        && str_contains($request['text'], 'Access code: ABC123')
        && str_contains($request['text'], 'DMS sign-in page, Guest tab')
        // Networks drop texts with a link, so the SMS never carries one.
        && ! preg_match('~https?://|www\.~i', $request['text']));
});

test('landlines, missing numbers and an unconfigured gateway send nothing', function (?string $contact, bool $configured) {
    if (! $configured) {
        config(['services.smart_sms.api_key' => '']);
    }
    Http::fake();

    registerWithContact($contact);

    Http::assertNothingSent();
})->with([
    'landline' => ['(072) 888-1234', true],
    'no number' => [null, true],
    'gateway not configured' => ['09171234567', false],
]);

test('a gateway error is logged and never fails the registration', function (int $status, string $body) {
    Http::fake(['sms.test/*' => Http::response($body, $status)]);
    Log::spy();

    $document = registerWithContact('+63 917 123 4567');

    expect($document->exists)->toBeTrue();
    Log::shouldHaveReceived('warning')->with('Tracking code SMS failed.', Mockery::on(fn ($context) => str_contains($context['message'], "HTTP {$status}")));
})->with([
    'HTTP error' => [401, 'Invalid API key'],
    'refused with HTTP 200' => [200, '1 101 ERROR'],
]);

test('editing sends the SMS again only when the mobile number changes', function () {
    Http::fake(['sms.test/*' => Http::response(OK_REPLY, 200)]);
    $document = registerWithContact('09171234567');
    // Records edits only what it holds: as if CHO had routed it back to Records.
    $document->trails()->whereNull('released_at')->update(['released_at' => now('Asia/Manila')]);
    $document->trails()->create(['department_id' => $this->records->department_id, 'received_at' => now('Asia/Manila'), 'received_action' => 'Returned to Records']);
    $document->update(['department_id' => $this->records->department_id]);

    $edit = fn (array $changes) => $this->put(route('documents.update', $document), [
        ...collect($this->payload)->except(['routed_to', 'primary_office_id'])->all(),
        'contact_no' => '09171234567', 'workflow_version' => $document->fresh()->workflow_version, ...$changes,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $edit(['subject_matter' => 'Corrected subject']);
    $edit(['contact_no' => '0917-123-4567']);
    Http::assertSentCount(1);

    $edit(['contact_no' => '09281234567']);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['destination'] === '639281234567');
});

test('mobile numbers are normalized to the 639XXXXXXXXX form', function (?string $input, ?string $expected) {
    expect(app(SmsService::class)->mobileNumber($input))->toBe($expected);
})->with([
    ['09171234567', '639171234567'],
    ['+63 917 123 4567', '639171234567'],
    ['9171234567', '639171234567'],
    ['0917-123-4567 / 072-888-1234', null],
    ['072 888 1234', null],
    ['', null],
]);
