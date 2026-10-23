<?php

use App\Models\Department;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

beforeEach(function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 23)->setTime(10, 0));
    $records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->administrator = Department::create(['code' => 'ADM', 'name' => 'Office of the City Administrator', 'state' => 1]);
    $this->records = User::factory()->create(['role' => 'records_manager', 'department_id' => $records->id]);
    $this->caloza = Person::create([
        'title' => 'Atty.', 'first_name' => 'Gilbert', 'middle_initial' => 'L', 'last_name' => 'Caloza',
        'position' => 'City Administrator', 'department_id' => $this->administrator->id,
    ]);
});

function analyzeSender(string $sender, string $text = "MEMORANDUM\nATTY. R. L. CALOZA\nCity Administrator")
{
    OpenAI::fake([CreateResponse::fake([
        'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['source_name' => $sender, 'source_type' => 'External'])]]],
    ])]);

    return test()->actingAs(test()->records)->postJson(route('documents.analyze'), ['extracted_text' => $text])->assertOk();
}

test('Records manages the directory and staff cannot', function () {
    $this->actingAs($this->records)->post(route('people.store'), [
        'title' => 'Engr.', 'first_name' => 'Germie', 'middle_initial' => 'O.', 'last_name' => 'Deang', 'state' => 1,
    ])->assertRedirect(route('people.index'))->assertSessionHasNoErrors();
    expect(Person::where('last_name', 'Deang')->first()->full_name)->toBe('Engr. Germie O. Deang');

    $this->post(route('people.store'), ['first_name' => 'X', 'last_name' => 'Y', 'middle_initial' => 'too long', 'state' => 1])
        ->assertSessionHasErrors('middle_initial');

    $this->delete(route('people.destroy', $this->caloza))->assertRedirect();
    expect($this->caloza->fresh()->state)->toBe(0);

    $staff = User::factory()->create(['role' => 'staff', 'department_id' => $this->administrator->id]);
    $this->actingAs($staff)->get(route('people.index'))->assertForbidden();
});

test('a sender name garbled under a signature is completed from the directory with its office', function () {
    analyzeSender('Atty. R. L. Caloza')
        ->assertJsonPath('source_name', 'Atty. Gilbert L. Caloza')
        ->assertJsonPath('source_type', 'Internal')
        ->assertJsonPath('source_location', $this->administrator->id);
});

test('names that do not fit a directory entry are left as read', function (string $sender) {
    Person::create(['first_name' => 'Jose', 'last_name' => 'Santos']);

    analyzeSender($sender, "Letter\n{$sender}")->assertJsonPath('source_name', $sender);
})->with([
    'different readable first name' => ['Maria L. Caloza'],
    'initials that all disagree' => ['M. Santos'],
    'surname not in the directory' => ['Junalyn F. Pangayan'],
]);

test('autofill still works when the directory cannot be read', function () {
    Schema::drop('people');

    analyzeSender('Atty. R. L. Caloza')->assertJsonPath('source_name', 'Atty. R. L. Caloza');
});

test('an inactive entry or two equally good matches are not used', function () {
    Person::create(['first_name' => 'Gina', 'middle_initial' => 'L', 'last_name' => 'Caloza']);
    analyzeSender('Atty. R. L. Caloza')->assertJsonPath('source_name', 'Atty. R. L. Caloza');

    Person::where('first_name', 'Gina')->update(['state' => 0]);
    $this->caloza->update(['state' => 0]);
    analyzeSender('Atty. R. L. Caloza')->assertJsonPath('source_name', 'Atty. R. L. Caloza');
});
