<?php

use App\Models\Department;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\MailSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $office = Department::create(['code' => 'CICTO', 'name' => 'ICT Office', 'state' => 1]);
    $this->admin = User::factory()->create(['role' => 'system_administrator', 'department_id' => $office->id]);
    $this->settings = [
        'host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'dms@example.com', 'password' => 'app-password-1',
        'from_address' => 'dms@example.com', 'from_name' => 'City of San Fernando DMS',
    ];
});

test('only the System Administrator can open or change email settings', function () {
    $records = User::factory()->create(['role' => 'records_manager']);

    $this->actingAs($records)->get(route('mail-settings.edit'))->assertForbidden();
    $this->actingAs($records)->put(route('mail-settings.update'), $this->settings)->assertForbidden();
    $this->actingAs($records)->post(route('mail-settings.test'), ['test_address' => 'a@example.com'])->assertForbidden();
    $this->assertDatabaseCount('mail_settings', 0);
});

test('the password is stored encrypted and never sent to the page', function () {
    $this->actingAs($this->admin)->put(route('mail-settings.update'), $this->settings)->assertSessionHasNoErrors();

    expect(DB::table('mail_settings')->value('password'))->not->toContain('app-password-1')
        ->and(MailSetting::first()->password)->toBe('app-password-1');

    $this->actingAs($this->admin)->get(route('mail-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('mail-settings/Index')
            ->where('setting.username', 'dms@example.com')
            ->where('setting.has_password', true)
            ->missing('setting.password'));
});

test('a blank password keeps the saved one; the first save requires it when .env has none', function () {
    config(['mail.mailers.smtp.password' => null]);
    $this->actingAs($this->admin)->put(route('mail-settings.update'), [...$this->settings, 'password' => ''])
        ->assertSessionHasErrors('password');

    $this->actingAs($this->admin)->put(route('mail-settings.update'), $this->settings);
    $this->actingAs($this->admin)->put(route('mail-settings.update'), [...$this->settings, 'password' => '', 'from_name' => 'DMS'])
        ->assertSessionHasNoErrors();

    expect(MailSetting::count())->toBe(1)
        ->and(MailSetting::first()->only(['password', 'from_name']))->toBe(['password' => 'app-password-1', 'from_name' => 'DMS']);
});

test('a blank first save takes the .env password, which is never sent to the page', function () {
    config(['mail.mailers.smtp.password' => 'env-app-password']);

    $this->actingAs($this->admin)->get(route('mail-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('defaults.has_password', true)->missing('defaults.password'));
    expect($this->actingAs($this->admin)->get(route('mail-settings.edit'))->getContent())->not->toContain('env-app-password');

    $this->actingAs($this->admin)->put(route('mail-settings.update'), [...$this->settings, 'password' => ''])
        ->assertSessionHasNoErrors();
    expect(MailSetting::first()->password)->toBe('env-app-password');

    // Later blank saves keep the saved password, even if .env changes.
    config(['mail.mailers.smtp.password' => 'other-env-password']);
    $this->actingAs($this->admin)->put(route('mail-settings.update'), [...$this->settings, 'password' => '']);
    expect(MailSetting::first()->password)->toBe('env-app-password');
});

test('saved settings override the .env mail settings', function () {
    $this->actingAs($this->admin)->put(route('mail-settings.update'), [...$this->settings, 'port' => 465]);
    config(['mail.mailers.smtp.host' => 'env-host']);

    app(MailSettingsService::class)->apply();

    expect(config('mail.mailers.smtp'))->toMatchArray([
        'host' => 'smtp.gmail.com', 'port' => 465, 'scheme' => 'smtps', 'username' => 'dms@example.com', 'password' => 'app-password-1',
    ])->and(config('mail.from'))->toBe(['address' => 'dms@example.com', 'name' => 'City of San Fernando DMS']);
});

test('the test email reports the SMTP error next to the field', function () {
    Mail::shouldReceive('raw')->andThrow(new RuntimeException('Username and Password not accepted'));
    Mail::shouldReceive('purge');

    $this->actingAs($this->admin)->post(route('mail-settings.test'), ['test_address' => 'admin@example.com'])
        ->assertSessionHasErrors(['test_address' => 'The test email could not be sent: Username and Password not accepted']);
});

test('the test email is sent to the given address', function () {
    Mail::fake();

    $this->actingAs($this->admin)->post(route('mail-settings.test'), ['test_address' => 'admin@example.com'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Test email sent to admin@example.com.');
});
