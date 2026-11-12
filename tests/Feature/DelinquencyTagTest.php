<?php

use App\Models\DelinquencyTag;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Monday 10:00 Manila: inside working hours; one day later the Primary office is past its 24 hours.
    $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 9)->setTime(10, 0));
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->adm = Department::create(['code' => 'ADM', 'name' => 'City Administrator', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->engineering = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    $this->admStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->adm->id]);
    $this->healthStaff = User::factory()->create(['role' => 'staff', 'department_id' => $this->health->id]);
    $this->clerk = User::factory()->create(['role' => 'records_manager', 'department_id' => $records->id]);
    $this->register = function (string $barcode) {
        test()->actingAs($this->clerk)->post(route('documents.store'), [
            'barcode' => $barcode, 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
            'subject_matter' => 'Late permit', 'is_confidential' => false, 'route_mode' => 'paper',
            'routed_to' => [$this->health->id], 'primary_office_id' => $this->health->id,
            'transaction_type_id' => TransactionType::firstOrCreate(['name' => 'Simple'], ['processing_days' => 3, 'state' => 1])->id,
            'document_type_id' => DocumentType::firstOrCreate(['document_code' => 'COM'], ['document_type' => 'Communications', 'state' => 1])->id,
        ])->assertSessionHasNoErrors();

        return Document::where('barcode', $barcode)->firstOrFail();
    };
});

test('ADM tags a delinquent document and the holding office notes it from its inbox and bell', function () {
    $document = ($this->register)('LATE-1');
    $this->travel(25)->hours();

    $this->actingAs($this->admStaff)->get(route('delinquency.index'))->assertInertia(fn (Assert $page) => $page
        ->component('delinquency/Index')
        ->has('rows', 1)
        ->where('rows.0.barcode', 'LATE-1')
        ->where('rows.0.office.code', 'CHO')
        ->where('rows.0.tag', null));

    $this->post(route('delinquency.tag', $document), ['tag' => 'followed_up', 'note' => '  Called the CHO head; signing today.  '])
        ->assertRedirect()->assertSessionHasNoErrors();
    $tag = DelinquencyTag::firstOrFail();
    expect($tag->department_id)->toBe($this->health->id)
        ->and($tag->note)->toBe('Called the CHO head; signing today.')
        ->and($tag->tagged_by)->toBe($this->admStaff->id)
        // A note, not a custody change: the office's open forms stay valid.
        ->and($document->fresh()->workflow_version)->toBe($document->workflow_version);
    $this->get(route('delinquency.index'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.tag.label', 'Followed up')->where('rows.0.tag.acknowledged_at', null));

    // The holding office: tag in the inbox row and first in the bell.
    $this->actingAs($this->healthStaff)->get(route('transactions.inbox'))->assertInertia(fn (Assert $page) => $page
        ->where('documents.data.0.delinquency_tag.label', 'Followed up')
        ->where('documents.data.0.delinquency_tag.acknowledged', false)
        ->where('incoming.items.0.id', "tag-{$tag->id}")
        ->where('incoming.items.0.action', 'Delinquent, tagged by ADM: Followed up'));

    $this->post(route('delinquency.acknowledge', [$document, $tag]))->assertRedirect()->assertSessionHasNoErrors();
    expect($tag->fresh()->acknowledged_by)->toBe($this->healthStaff->id);
    $this->get(route('transactions.inbox'))->assertInertia(fn (Assert $page) => $page
        ->where('documents.data.0.delinquency_tag.acknowledged', true)
        ->where('incoming.items', fn ($items) => collect($items)->doesntContain('id', "tag-{$tag->id}")));
    // A second Noted is answered with a message, not an error page.
    $this->post(route('delinquency.acknowledge', [$document, $tag]))->assertSessionHasErrors('tag');

    // Kept as history on the document page.
    $this->get(route('documents.show', $document))->assertInertia(fn (Assert $page) => $page
        ->has('delinquencyTags', 1)->where('delinquencyTags.0.acknowledged_by', $this->healthStaff->name));
});

test('only the City Administrator\'s office monitors and tags, and only delinquent documents', function () {
    $document = ($this->register)('LATE-2');

    // Not delinquent yet: nothing to tag.
    $this->actingAs($this->admStaff)->post(route('delinquency.tag', $document), ['tag' => 'escalated', 'note' => 'Too early'])->assertForbidden();
    $this->get(route('delinquency.index'))->assertInertia(fn (Assert $page) => $page->has('rows', 0));

    $this->travel(25)->hours();
    $this->actingAs($this->healthStaff)->get(route('delinquency.index'))->assertForbidden();
    $this->post(route('delinquency.tag', $document), ['tag' => 'escalated', 'note' => 'Self-tag'])->assertForbidden();

    $this->actingAs($this->admStaff)->post(route('delinquency.tag', $document), ['tag' => 'unknown', 'note' => ''])
        ->assertSessionHasErrors(['tag', 'note']);
    expect(DelinquencyTag::count())->toBe(0);
});

test('another office cannot note a tag meant for the holding office', function () {
    $document = ($this->register)('LATE-3');
    $this->travel(25)->hours();
    $this->actingAs($this->admStaff)->post(route('delinquency.tag', $document), ['tag' => 'escalated', 'note' => 'Escalated to the Mayor'])
        ->assertSessionHasNoErrors();
    $tag = DelinquencyTag::firstOrFail();

    $outsider = User::factory()->create(['role' => 'staff', 'department_id' => $this->engineering->id]);
    $this->actingAs($outsider)->post(route('delinquency.acknowledge', [$document, $tag]))->assertForbidden();
    expect($tag->fresh()->acknowledged_at)->toBeNull();
});
