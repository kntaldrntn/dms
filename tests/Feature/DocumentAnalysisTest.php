<?php

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

beforeEach(function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 9, 24)->setTime(10, 0));
    $office = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $office->id]);
});

test('analysis validates input before calling the provider', function () {
    $fake = OpenAI::fake();
    $this->actingAs($this->records)->postJson(route('documents.analyze'), [
        'extracted_text' => '', 'ai_thumbnail' => UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['extracted_text', 'ai_thumbnail']);
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
    OpenAI::fake([CreateResponse::fake([
        'choices' => [['message' => ['role' => 'assistant', 'content' => '{"barcode":"c-123", "source_name":"Test Sender"}']]],
    ])]);
    $this->actingAs($this->records)->postJson(route('documents.analyze'), [
        'extracted_text' => "  Sender\n Test Sender  ",
        'ai_thumbnail' => UploadedFile::fake()->image('thumb.jpg'),
    ])->assertOk()->assertJsonPath('barcode', 'C000000123')->assertJsonPath('source_name', 'Test Sender')
        ->assertJsonStructure(['meta' => ['total_tokens', 'prompt_tokens', 'completion_tokens']]);
    $this->assertDatabaseCount('documents', 0);
});

test('routing suggestions keep only offices a referral slip can target', function () {
    $administrator = Department::create(['code' => 'ADM', 'name' => 'Administrator', 'state' => 1]);
    $health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $closed = Department::create(['code' => 'OLD', 'name' => 'Closed office', 'state' => 0]);
    OpenAI::fake([CreateResponse::fake([
        'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode([
            'department_id' => $administrator->id,
            'routing_suggestions' => [
                ['department_id' => (string) $administrator->id, 'priority' => 'Primary', 'reason' => 'Addressed to ADM'],
                ['department_id' => (string) $health->id, 'priority' => 'Primary', 'reason' => 'Health concern'],
                ['department_id' => $health->id, 'priority' => 'FYI', 'reason' => 'Duplicate'],
                ['department_id' => $closed->id, 'priority' => 'Secondary', 'reason' => 'Inactive'],
                ['department_id' => 9999, 'priority' => 'Urgent', 'reason' => ['not', 'a', 'string']],
            ],
        ])]]],
    ])]);

    $this->actingAs($this->records)->postJson(route('documents.analyze'), [
        'extracted_text' => 'Request to the City Administrator', 'ai_thumbnail' => UploadedFile::fake()->image('thumb.jpg'),
    ])->assertOk()->assertJsonPath('routing_suggestions', [
        ['department_id' => $health->id, 'priority' => 'Primary', 'reason' => 'Health concern'],
    ]);
});

test('invalid analysis responses return a safe failure without exposing provider output', function (string $content) {
    OpenAI::fake([CreateResponse::fake([
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
    ])]);
    $this->actingAs($this->records)->postJson(route('documents.analyze'), [
        'extracted_text' => 'Test document', 'ai_thumbnail' => UploadedFile::fake()->image('thumb.jpg'),
    ])->assertStatus(502)->assertExactJson(['error' => 'Document analysis is unavailable. Please try again later.']);
})->with(['not-json secret-provider-details', '[]', 'null']);

test('provider failures return a safe error without leaking exception details', function () {
    $client = Mockery::mock(ClientContract::class);
    $client->shouldReceive('chat')->once()->andThrow(new RuntimeException('Private provider error details'));
    OpenAI::swap($client);
    $this->actingAs($this->records)->postJson(route('documents.analyze'), [
        'extracted_text' => 'Test document', 'ai_thumbnail' => UploadedFile::fake()->image('thumb.jpg'),
    ])->assertStatus(502)->assertExactJson(['error' => 'Document analysis is unavailable. Please try again later.']);
});
