<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

beforeEach(function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 9, 24)->setTime(10, 0));
    // Stamping is the paperless route; OCM has already received the document.
    config(['services.paperless.enabled' => true]);
    $office = Department::create(['code' => 'OCM', 'name' => 'City Mayor', 'state' => 1]);
    $this->mayor = User::factory()->create(['role' => 'city_mayor', 'department_id' => $office->id]);
    $this->document = Document::create([
        'barcode' => 'OCM-STAMP', 'access_code' => 'ABC123', 'source_type' => 'External', 'source_name' => 'Client',
        'subject_matter' => 'For signature', 'department_id' => $office->id, 'route_mode' => 'paperless',
        'document_type_id' => DocumentType::create(['document_code' => 'T', 'document_type' => 'Test', 'state' => 1])->id,
        'transaction_type_id' => TransactionType::create(['name' => 'Simple', 'processing_days' => 3, 'state' => 1])->id,
    ]);
    DocumentTrail::create([
        'document_id' => $this->document->id, 'department_id' => $office->id, 'stage' => DocumentTrail::STAGE_OCM_APPROVAL,
        'received_at' => now('Asia/Manila'), 'received_action' => 'Awaiting City Mayor approval', 'received_by' => $this->mayor->id,
    ]);
});

test('AI stamp placements that run past the page edge are rejected', function () {
    OpenAI::fake([CreateResponse::fake([
        'choices' => [['message' => ['role' => 'assistant', 'content' => '{"page":1,"x":900,"y":850,"width":260,"height":105}']]],
    ])]);

    $this->actingAs($this->mayor)->post(route('approvals.stamp-placement', $this->document), [
        'thumbnails' => [UploadedFile::fake()->image('page-1.jpg')],
        'pages' => [['page' => 1]],
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonStructure(['error']);
});

test('OCM approval refuses a stamp that does not fit inside the page', function () {
    $ict = Department::create(['code' => 'CICTO', 'name' => 'ICT Office', 'state' => 1]);
    $this->actingAs($this->mayor)->postJson(route('ocm-approvals.approve', $this->document), [
        'workflow_version' => 0, 'certificate_pin' => '2468', 'routed_to' => [$ict->id], 'primary_office_id' => $ict->id,
        'stamp_placement' => ['page' => 1, 'x' => 800, 'y' => 100, 'width' => 300, 'height' => 105],
    ])->assertUnprocessable()->assertJsonValidationErrors(['stamp_placement']);

    expect($this->document->fresh()->stage)->toBe(DocumentTrail::STAGE_OCM_APPROVAL);
});
