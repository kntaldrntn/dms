<?php

use App\Http\Requests\TrackDocumentRequest;
use App\Mail\DocumentTrackingCode;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 30)->setTime(10, 0));
    Mail::fake();
    $this->recordsOffice = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    Department::create(['code' => 'ADM', 'name' => 'Office of the City Administrator', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Office of the City Health Officer', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $this->recordsOffice->id]);
    $this->payload = [
        'barcode' => 'C000000001', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Juan Dela Cruz',
        'email' => 'client@example.com', 'subject_matter' => 'Request for medical records', 'route_mode' => 'paper',
        'is_confidential' => false,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
        'document_type_id' => DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1])->id,
        // Intake always needs a destination.
        'routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id,
    ];
});

function registerTracked(array $extra = []): Document
{
    test()->actingAs(test()->records)->post(route('documents.store'), [...test()->payload, ...$extra])->assertSessionHasNoErrors();

    return Document::where('barcode', 'C000000001')->firstOrFail();
}

function trackDocument(string $barcode, string $accessCode)
{
    auth()->logout();

    return test()->post(route('track'), ['barcode' => $barcode, 'access_code' => $accessCode]);
}

test('registration emails the client the barcode and access code once; later workflow steps email nothing', function () {
    $document = registerTracked(['routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id]);

    Mail::assertSent(DocumentTrackingCode::class, 1);
    Mail::assertSent(DocumentTrackingCode::class, function (DocumentTrackingCode $mail) {
        $html = $mail->render();

        return $mail->hasTo('client@example.com') && $mail->hasSubject('Document C000000001 received: your tracking code')
            && str_contains($html, 'C000000001') && str_contains($html, 'ABC123') && str_contains($html, 'tab=track');
    });

    $healthStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->health->id]);
    $this->actingAs($healthStaff)->post(route('transactions.receive', $document))->assertSessionHasNoErrors();
    $this->post(route('transactions.process', $document), [
        'action' => 'end', 'remarks' => 'Released to client', 'workflow_version' => $document->fresh()->workflow_version,
    ])->assertSessionHasNoErrors();

    Mail::assertSent(DocumentTrackingCode::class, 1);
});

test('the sent email carries the city seal inline instead of the framework logo', function () {
    $document = registerTracked();
    // Send for real through the in-memory transport (a manager of its own, since this file fakes Mail): the fake
    // never builds the message, so nothing would be embedded.
    $mailer = (new MailManager(app()))->mailer('array');
    $mailer->to('client@example.com')->send(new DocumentTrackingCode($document));

    $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    $html = $sent->getHtmlBody();
    $parts = collect($sent->getAttachments());
    $seal = $parts->first();

    expect($parts)->toHaveCount(1)
        ->and($seal->getMediaSubtype())->toBe('png')
        ->and($seal->getDisposition())->toBe('inline')
        ->and($html)->toContain('src="cid:'.$seal->getContentId().'"')
        ->and($html)->toContain('City Government of San Fernando')
        ->and($html)->not->toContain('laravel.com')
        ->and($sent->getTextBody())->not->toContain('cid:');
});

test('no client email, no tracking email; a confidential document email leaves out the subject', function () {
    registerTracked(['email' => null]);
    Mail::assertNothingSent();

    Document::query()->delete();
    registerTracked(['is_confidential' => true]);
    Mail::assertSent(DocumentTrackingCode::class, fn (DocumentTrackingCode $mail) => ! str_contains($mail->render(), 'Request for medical records'));
});

test('an email added or changed on Edit gets the tracking code; other edits send nothing', function () {
    $document = registerTracked(['email' => null]);
    // Records edits only what it holds: as if CHO had routed it back to Records.
    $document->trails()->whereNull('released_at')->update(['released_at' => now('Asia/Manila')]);
    $document->trails()->create(['department_id' => $this->recordsOffice->id, 'received_at' => now('Asia/Manila'), 'received_action' => 'Returned to Records']);
    $document->update(['department_id' => $this->recordsOffice->id]);
    $editable = collect($this->payload)->except(['routed_to', 'primary_office_id'])->all();
    $update = fn (array $changes) => $this->actingAs($this->records)->put(route('documents.update', $document), [
        ...$editable, ...$changes, 'workflow_version' => $document->fresh()->workflow_version,
    ])->assertSessionHasNoErrors();

    $update(['email' => 'client@example.com']);
    Mail::assertSent(DocumentTrackingCode::class, 1);

    $update(['subject_matter' => 'Request for medical records (corrected)']);
    Mail::assertSent(DocumentTrackingCode::class, 1);

    $update(['email' => 'other@example.com']);
    Mail::assertSent(DocumentTrackingCode::class, fn (DocumentTrackingCode $mail) => $mail->hasTo('other@example.com'));
});

test('a queueing failure is logged and never fails the registration', function () {
    Log::spy();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    $document = registerTracked();

    expect($document->exists)->toBeTrue();
    Log::shouldHaveReceived('warning')->with('Tracking code email failed.', Mockery::type('array'));
});

test('the barcode and access code show the latest status only', function () {
    registerTracked(['routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id]);

    trackDocument(' c000000001 ', 'abc123')
        ->assertRedirect(route('login', ['tab' => 'track']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('tracking', [
            'barcode' => 'C000000001',
            'subject' => 'Request for medical records',
            'received_at' => 'Oct 30, 2026 10:00 AM',
            'status' => 'Being processed at the Office of the City Health Officer',
            'updated_at' => 'Oct 30, 2026 10:00 AM',
        ]);

    $this->withSession(['tracking' => session('tracking')])->get(route('login', ['tab' => 'track']))
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login')
            ->where('initialTab', 'guest')
            ->where('tracking.status', 'Being processed at the Office of the City Health Officer'));
});

test('a confidential document shows the status without the subject', function () {
    registerTracked(['is_confidential' => true]);

    trackDocument('C000000001', 'ABC123')->assertSessionHas('tracking.status', 'Being processed at the Office of the City Health Officer');

    expect(session('tracking'))->toHaveKey('subject', null);
});

test('a wrong access code, an unknown barcode and a deactivated document all get the same message', function () {
    $document = registerTracked();
    $message = 'No document matches that barcode number and access code.';

    trackDocument('C000000001', 'WRONG1')->assertSessionHasErrors(['tracking' => $message])->assertSessionMissing('tracking');
    trackDocument('C999999999', 'ABC123')->assertSessionHasErrors(['tracking' => $message]);

    $document->update(['state' => 0]);
    trackDocument('C000000001', 'ABC123')->assertSessionHasErrors(['tracking' => $message]);
});

test('too many wrong tries lock the visitor out, even with the right codes', function () {
    registerTracked();

    foreach (range(1, TrackDocumentRequest::MAX_FAILED_ATTEMPTS) as $attempt) {
        trackDocument('C000000001', 'WRONG'.$attempt);
    }

    trackDocument('C000000001', 'ABC123')->assertSessionHasErrors('tracking')->assertSessionMissing('tracking');
    expect(session('errors')->first('tracking'))->toStartWith('Too many tries.');
});

test('the login page opens the employee tab unless the tracking link is used', function () {
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('initialTab', 'staff')->where('tracking', null));
});
