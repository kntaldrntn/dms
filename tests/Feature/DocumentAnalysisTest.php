<?php

use App\Models\Department;
use App\Models\DocumentClassification;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Models\User;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;

beforeEach(function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 9, 24)->setTime(10, 0));
    $office = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $office->id]);
});

function fakeAnalysis(array|string $content): void
{
    OpenAI::fake([CreateResponse::fake([
        'choices' => [['message' => ['role' => 'assistant', 'content' => is_string($content) ? $content : json_encode($content)]]],
    ])]);
}

test('analysis validates input before calling the provider', function () {
    $fake = OpenAI::fake();
    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['extracted_text']);
    $fake->assertNothingSent();
});

test('analysis rejects staff and inactive accounts without calling the provider', function () {
    $fake = OpenAI::fake();
    $this->records->update(['role' => 'staff']);
    $this->actingAs($this->records)->postJson(route('documents.analyze'))->assertForbidden();
    $this->records->update(['role' => 'records_manager', 'state' => 0]);
    $this->actingAs($this->records)->postJson(route('documents.analyze'))->assertForbidden();
    $fake->assertNothingSent();
});

test('analysis returns normalized barcode and usage from a mocked response', function () {
    fakeAnalysis(['barcode' => 'c-123', 'source_name' => 'Test Sender']);
    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => "  Sender\n Test Sender  "])
        ->assertOk()->assertJsonPath('barcode', 'C000000123')->assertJsonPath('source_name', 'Test Sender')
        ->assertJsonStructure(['meta' => ['total_tokens', 'prompt_tokens', 'completion_tokens']]);
    $this->assertDatabaseCount('documents', 0);
});

test('analysis sends only OCR text with a strict schema and the configured model', function () {
    config(['services.intake_ai.model' => 'gpt-test-model']);
    $fake = OpenAI::fake([CreateResponse::fake([
        'choices' => [['message' => ['role' => 'assistant', 'content' => '{"source_name":"Test Sender"}']]],
    ])]);

    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => "--- Page 1 ---\nLetter"])->assertOk();

    $fake->assertSent(Chat::class, fn (string $method, array $parameters) => $parameters['model'] === 'gpt-test-model'
        && $parameters['response_format']['json_schema']['strict'] === true
        && is_string($parameters['messages'][1]['content'])
        && str_contains($parameters['messages'][1]['content'], "--- Page 1 ---\nLetter"));
});

test('reasoning models get a reasoning effort instead of a temperature', function (string $model, array $expected, array $absent) {
    config(['services.intake_ai.model' => $model, 'services.intake_ai.reasoning_effort' => 'none']);
    $fake = OpenAI::fake([CreateResponse::fake([
        'choices' => [['message' => ['role' => 'assistant', 'content' => '{"source_name":null}']]],
    ])]);

    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => 'Letter'])->assertOk();

    $fake->assertSent(Chat::class, fn (string $method, array $parameters) => array_intersect_assoc($parameters, $expected) === $expected
        && array_diff_key(array_flip($absent), $parameters) === array_flip($absent));
})->with([
    'gpt-6' => ['gpt-6-luna', ['model' => 'gpt-6-luna', 'reasoning_effort' => 'none'], ['temperature']],
    'gpt-4o' => ['gpt-4o-mini', ['model' => 'gpt-4o-mini', 'temperature' => 0], ['reasoning_effort']],
]);

test('an attached referral slip reaches the model as its From line only, and its signer is never the sender', function () {
    $fake = OpenAI::fake([CreateResponse::fake([
        'choices' => [['message' => ['role' => 'assistant', 'content' => '{"source_name":"Atty. RT L. Caloza"}']]],
    ])]);
    $text = "--- Page 1 ---\nREFERRAL / ACTION SLIP\nFrom: JUNALYN PANGAYAN\n[x] CICTO\nATTY. GILBERT L. CALOZA\nCity Administrator\n"
        ."--- Page 2 ---\nDear Hon. Mayor Gualberto:\nVery truly yours,\nJUNALYNF. P. AYAN";

    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => $text])
        ->assertOk()->assertJsonPath('source_name', null);

    $fake->assertSent(Chat::class, fn (string $method, array $parameters) => ! str_contains($parameters['messages'][1]['content'], 'CALOZA')
        && str_contains($parameters['messages'][1]['content'], "--- Page 1 (attached referral slip, not the document) ---\nFrom: JUNALYN PANGAYAN")
        && str_contains($parameters['messages'][1]['content'], "--- Page 2 ---\nDear Hon. Mayor"));
});

test('a surname split by OCR under the signature is repaired from the From line', function (string $answer, string $expected) {
    fakeAnalysis(['source_name' => $answer]);
    $text = "--- Page 1 ---\nRouting Slip\nFrom: JUNALYN PANGAYAN\n--- Page 2 ---\nVery truly yours,\nJUNALYNF. P. AYAN\nProgram Chair";

    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => $text])
        ->assertOk()->assertJsonPath('source_name', $expected);
})->with([
    'split surname' => ['Junalyn F. Ayan', 'Junalyn F. Pangayan'],
    'degree suffix kept' => ['Ms. Junalyn F. Ayan, MIT', 'Ms. Junalyn F. Pangayan, MIT'],
    'already correct' => ['Junalyn F. Pangayan', 'Junalyn F. Pangayan'],
]);

test('analysis answers are checked against the document-type rulebook', function () {
    $complex = TransactionType::create(['name' => 'Complex Transaction', 'processing_days' => 7, 'state' => 1]);
    $simple = TransactionType::create(['name' => 'Simple Transaction', 'processing_days' => 3, 'state' => 1]);
    $invitation = DocumentClassification::create(['name' => 'Invitation', 'state' => 1]);
    $financial = DocumentClassification::create(['name' => 'Financial', 'state' => 1]);
    $communications = DocumentType::create(['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1, 'default_transaction_type_id' => $complex->id]);
    $communications->classifications()->attach($invitation);
    $health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);

    fakeAnalysis([
        'source_type' => 'External', 'source_location' => $health->id, 'document_type_id' => $communications->id,
        'classification_id' => $financial->id, 'transaction_type_id' => $simple->id,
    ]);

    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => 'Invitation letter'])
        ->assertOk()
        ->assertJsonPath('classification_id', null)
        ->assertJsonPath('transaction_type_id', $complex->id)
        ->assertJsonPath('source_location', null);
});

test('routing suggestions keep only offices a referral slip can target', function () {
    $administrator = Department::create(['code' => 'ADM', 'name' => 'Administrator', 'state' => 1]);
    $health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $closed = Department::create(['code' => 'OLD', 'name' => 'Closed office', 'state' => 0]);
    fakeAnalysis([
        'department_id' => $administrator->id,
        'routing_suggestions' => [
            ['office' => 'ADM', 'priority' => 'Primary', 'reason' => 'Addressed to ADM'],
            ['office' => 'CHO', 'priority' => 'Primary', 'reason' => 'Health concern'],
            ['office' => 'CHO', 'priority' => 'FYI', 'reason' => 'Duplicate'],
            ['office' => $closed->code, 'priority' => 'Secondary', 'reason' => 'Inactive'],
            ['office' => 'XYZ', 'priority' => 'Urgent', 'reason' => ['not', 'a', 'string']],
            ['department_id' => $health->id, 'priority' => 'FYI', 'reason' => 'Ids are not accepted'],
        ],
    ]);

    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => 'Request to the City Administrator'])
        ->assertOk()->assertJsonPath('routing_suggestions', [
            ['department_id' => $health->id, 'priority' => 'Primary', 'reason' => 'Health concern'],
        ]);
});

test('straight-to-department routing never suggests Records or the Mayor, and the Primary is a kept office', function () {
    Department::create(['code' => 'OCM', 'name' => 'Office of the City Mayor', 'state' => 1]);
    $health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $budget = Department::create(['code' => 'CBO', 'name' => 'Budget', 'state' => 1]);
    fakeAnalysis([
        'department_id' => null,
        'routing_suggestions' => [
            ['office' => 'OCM Office of the City Mayor', 'priority' => 'Primary', 'reason' => 'Mentions the Mayor'],
            ['office' => 'REC Records', 'priority' => 'Secondary', 'reason' => 'Filing'],
            ['office' => 'CBO Budget', 'priority' => 'FYI', 'reason' => 'Budget copy'],
            ['office' => 'CHO Health', 'priority' => 'Secondary', 'reason' => 'Health request'],
        ],
    ]);

    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => 'Request to the Health Office'])
        ->assertOk()->assertJsonPath('routing_suggestions', [
            ['department_id' => $budget->id, 'priority' => 'Primary', 'reason' => 'Budget copy'],
            ['department_id' => $health->id, 'priority' => 'Secondary', 'reason' => 'Health request'],
        ]);
});

test('a document for the City Administrator may still suggest the Mayor for after approval', function () {
    $administrator = Department::create(['code' => 'ADM', 'name' => 'Administrator', 'state' => 1]);
    $mayor = Department::create(['code' => 'OCM', 'name' => 'Office of the City Mayor', 'state' => 1]);
    fakeAnalysis([
        'department_id' => $administrator->id,
        'routing_suggestions' => [['office' => 'OCM Office of the City Mayor', 'priority' => 'Primary', 'reason' => 'Final approval']],
    ]);

    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => 'Request to the City Administrator'])
        ->assertOk()->assertJsonPath('department_id', $administrator->id)
        ->assertJsonPath('routing_suggestions', [['department_id' => $mayor->id, 'priority' => 'Primary', 'reason' => 'Final approval']]);
});

test('an intake target other than the City Administrator or the Mayor is discarded', function () {
    $health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    fakeAnalysis(['department_id' => $health->id]);

    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => 'Request'])
        ->assertOk()->assertJsonPath('department_id', null);
});

test('routing suggestions always carry exactly one Primary office', function (array $priorities, array $expected) {
    $offices = collect(['CHO', 'CBO', 'CTO'])->map(fn ($code) => Department::create(['code' => $code, 'name' => $code, 'state' => 1]));
    fakeAnalysis(['routing_suggestions' => $offices->map(fn ($office, $index) => [
        'office' => $office->code, 'priority' => $priorities[$index], 'reason' => 'Fits',
    ])->all()]);

    $response = $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => 'Request'])->assertOk();

    expect(array_column($response->json('routing_suggestions'), 'priority'))->toBe($expected);
})->with([
    'none named' => [['Secondary', 'FYI', 'FYI'], ['Primary', 'FYI', 'FYI']],
    'several named' => [['Secondary', 'Primary', 'Primary'], ['Secondary', 'Primary', 'Secondary']],
]);

test('invalid analysis responses return a safe failure without exposing provider output', function (string $content) {
    fakeAnalysis($content);
    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => 'Test document'])
        ->assertStatus(502)->assertExactJson(['error' => 'Document analysis is unavailable. Please try again later.']);
})->with(['not-json secret-provider-details', '[]', 'null']);

test('provider failures return a safe error without leaking exception details', function () {
    $client = Mockery::mock(ClientContract::class);
    $client->shouldReceive('chat')->once()->andThrow(new RuntimeException('Private provider error details'));
    OpenAI::swap($client);
    $this->actingAs($this->records)->postJson(route('documents.analyze'), ['extracted_text' => 'Test document'])
        ->assertStatus(502)->assertExactJson(['error' => 'Document analysis is unavailable. Please try again later.']);
});
