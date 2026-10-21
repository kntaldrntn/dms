<?php

use App\Models\Department;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('seeder gives every office one department head and one staff account', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class); // re-running must not duplicate accounts

    expect(DocumentType::count())->toBe(12);

    Department::all()->each(function (Department $department) {
        foreach (['department_head', 'staff'] as $role) {
            expect(User::where('department_id', $department->id)->where('role', $role)->count())
                ->toBe(1, "{$department->code} should have exactly one {$role}");
        }
    });

    expect(User::where('email', 'cho.dh@gmail.com')->value('department_id'))
        ->toBe(Department::where('code', 'CHO')->value('id'));

    foreach (['sysadm@gmail.com' => 'CICTO', 'rm@gmail.com' => 'REC', 'admin@gmail.com' => 'ADM', 'mayor@gmail.com' => 'OCM'] as $email => $code) {
        expect(User::where('email', $email)->first()->department_id)->toBe(Department::where('code', $code)->value('id'));
    }
});
