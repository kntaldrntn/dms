<?php

use App\Models\Department;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('the Users list shows each signer\'s certificate and signature-image status, never the certificate file', function () {
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00', 'Asia/Manila'));
    $office = Department::create(['code' => 'CHO', 'name' => 'Health', 'state' => 1]);
    $admin = User::factory()->create(['role' => 'system_administrator', 'department_id' => $office->id]);
    $signer = fn (string $role, ?string $validUntil, ?string $image = null) => tap(
        User::factory()->create(['role' => $role, 'department_id' => $office->id, 'signature_path' => $image]),
        fn (User $user) => $validUntil ? $user->forceFill(['certificate_path' => "users/{$user->id}.p12", 'certificate_valid_until' => $validUntil])->save() : null,
    );
    $users = [
        'valid' => $signer('department_head', '2027-05-15', 'signatures/head.png'),
        'expiring' => $signer('city_mayor', '2026-11-20'),
        'expired' => $signer('city_administrator', '2026-10-01'),
        'none' => $signer('department_head', null),
        'staff' => User::factory()->create(['role' => 'staff', 'department_id' => $office->id]),
    ];

    $this->actingAs($admin)->get(route('users.index'))->assertInertia(function (Assert $page) use ($users) {
        $rows = collect($page->toArray()['props']['users']['data'])->keyBy('id');
        expect($rows[$users['valid']->id]['signing_status'])->toBe(['certificate' => 'valid', 'valid_until' => '2027-05-15', 'signature_image' => true])
            ->and($rows[$users['expiring']->id]['signing_status']['certificate'])->toBe('expiring')
            ->and($rows[$users['expired']->id]['signing_status']['certificate'])->toBe('expired')
            ->and($rows[$users['none']->id]['signing_status'])->toBe(['certificate' => 'none', 'valid_until' => null, 'signature_image' => false])
            ->and($rows[$users['staff']->id]['signing_status'])->toBeNull()
            ->and($rows[$users['valid']->id])->not->toHaveKey('certificate_path');
    });

    // Only the System Administrator manages users.
    $this->actingAs($users['valid'])->get(route('users.index'))->assertForbidden();
});
