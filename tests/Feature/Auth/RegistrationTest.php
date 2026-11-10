<?php

use App\Models\User;

// Accounts are created by the System Administrator in User Management only (FGD access matrix).
test('there is no self-registration page', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test User', 'email' => 'test@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});
