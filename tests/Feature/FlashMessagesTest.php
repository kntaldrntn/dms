<?php

use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'system_administrator', 'department_id' => null]);
});

test('every page receives the one-time success and error messages for its toast', function () {
    // Pages whose controllers never passed `flash` themselves (they showed no toast before) get it too.
    $this->actingAs($this->admin)->withSession(['success' => 'Saved.'])->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('flash', ['success' => 'Saved.', 'error' => null]));

    $this->withSession(['error' => 'Could not save.'])->get(route('performance-standards.index'))
        ->assertInertia(fn (Assert $page) => $page->where('flash.error', 'Could not save.'));
});

test('a redirect message is shown once, on the page it lands on', function () {
    Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);

    $this->actingAs($this->admin)->post(route('departments.store'), ['code' => 'ENG', 'name' => 'Engineering', 'state' => 1])
        ->assertRedirect();
    $this->get(route('departments.index'))->assertInertia(fn (Assert $page) => $page->where('flash.success', fn ($message) => filled($message)));
    $this->get(route('departments.index'))->assertInertia(fn (Assert $page) => $page->where('flash.success', null));
});
