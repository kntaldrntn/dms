<?php

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->records = Department::create(['code' => 'REC', 'name' => 'Records', 'state' => 1]);
    $this->adm = Department::create(['code' => 'ADM', 'name' => 'City Administrator', 'state' => 1]);
    $this->ocm = Department::create(['code' => 'OCM', 'name' => 'Office of the City Mayor', 'state' => 1]);
    $this->health = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $this->admin = User::factory()->create(['role' => 'system_administrator', 'department_id' => null]);
    $this->newUser = fn (array $changes = []) => [
        'name' => 'New User', 'email' => 'new.user@example.com', 'role' => 'staff', 'state' => 1,
        'department_id' => $this->health->id, 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123',
        ...$changes,
    ];
});

test('staff and department heads need a department; a system administrator may have none', function () {
    $this->actingAs($this->admin)->post(route('users.store'), ($this->newUser)(['department_id' => '']))
        ->assertSessionHasErrors(['department_id' => 'Choose the department this user works in. Only a System Administrator may have none.']);
    $this->post(route('users.store'), ($this->newUser)(['role' => 'department_head', 'department_id' => '']))->assertSessionHasErrors('department_id');

    $this->post(route('users.store'), ($this->newUser)(['role' => 'system_administrator', 'department_id' => '']))->assertSessionHasNoErrors();
    expect(User::where('email', 'new.user@example.com')->value('department_id'))->toBeNull();
});

test('the City Administrator, City Mayor and Records Manager must be in their own office', function (string $role, string $code, string $message) {
    $this->actingAs($this->admin)->post(route('users.store'), ($this->newUser)(['role' => $role]))
        ->assertSessionHasErrors(['department_id' => $message]);

    $office = Department::where('code', $code)->firstOrFail();
    $this->post(route('users.store'), ($this->newUser)(['role' => $role, 'department_id' => $office->id]))->assertSessionHasNoErrors();
})->with([
    ['city_administrator', 'ADM', 'A City Administrator must belong to City Administrator (ADM).'],
    ['city_mayor', 'OCM', 'A City Mayor must belong to Office of the City Mayor (OCM).'],
    ['records_manager', 'REC', 'A Records Manager must belong to Records (REC).'],
]);

test('editing keeps the rules, and a blank password keeps the current one', function () {
    $mayor = User::factory()->create(['role' => 'city_mayor', 'department_id' => $this->ocm->id]);
    $password = $mayor->password;
    $fields = ['name' => $mayor->name, 'email' => $mayor->email, 'role' => 'city_mayor', 'state' => 1, 'department_id' => $this->ocm->id];

    $this->actingAs($this->admin)->put(route('users.update', $mayor), [...$fields, 'department_id' => $this->health->id])
        ->assertSessionHasErrors('department_id');
    $this->put(route('users.update', $mayor), [...$fields, 'name' => 'Renamed Mayor', 'password' => ''])->assertSessionHasNoErrors();

    expect($mayor->fresh())->name->toBe('Renamed Mayor')->password->toBe($password);
});

test('only users who sign may get a signature image', function () {
    Storage::fake('local');
    $image = fn () => UploadedFile::fake()->image('signature.png');

    $this->actingAs($this->admin)->post(route('users.store'), ($this->newUser)(['signature' => $image()]))
        ->assertSessionHasErrors(['signature' => 'Only users who sign documents need a signature image.']);

    // Staff authorized to sign for the head may have one.
    $this->post(route('users.store'), ($this->newUser)(['signature' => $image(), 'can_sign_for_head' => true]))->assertSessionHasNoErrors();
    expect(User::where('email', 'new.user@example.com')->value('signature_path'))->not->toBeNull();
});

test('only the system administrator manages users', function () {
    $head = User::factory()->create(['role' => 'department_head', 'department_id' => $this->health->id]);

    $this->actingAs($head)->post(route('users.store'), ($this->newUser)())->assertForbidden();
});
