<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MailSettingRequest;
use App\Http\Requests\Settings\SendTestMailRequest;
use App\Services\MailSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MailSettingController extends Controller
{
    public function edit(Request $request, MailSettingsService $settings): Response
    {
        $setting = $settings->current();

        return Inertia::render('mail-settings/Index', [
            // Never the password: the page only learns whether one is saved.
            'setting' => $setting ? [
                ...$setting->only(['host', 'port', 'username', 'from_address', 'from_name']),
                'has_password' => true,
                'updated_at' => $setting->updated_at?->timezone('Asia/Manila')->format('M j, Y g:i A'),
                'updated_by' => $setting->updatedBy?->name,
            ] : null,
            'defaults' => [
                'host' => config('mail.mailers.smtp.host'),
                'port' => (int) config('mail.mailers.smtp.port'),
                'username' => config('mail.mailers.smtp.username'),
                'from_address' => config('mail.from.address'),
                'from_name' => config('mail.from.name'),
                // Only whether .env has one; the value stays on the server.
                'has_password' => $settings->envPassword() !== null,
            ],
            'flash' => ['success' => $request->session()->get('success')],
        ]);
    }

    public function update(MailSettingRequest $request, MailSettingsService $settings): RedirectResponse
    {
        $settings->save($request->validated(), $request->user());

        return back()->with('success', 'Email settings saved.');
    }

    public function test(SendTestMailRequest $request, MailSettingsService $settings): RedirectResponse
    {
        try {
            $settings->sendTest($request->validated('test_address'));
        } catch (Throwable $exception) {
            Log::warning('Test email failed.', [
                'user_id' => $request->user()->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            // The SMTP reply (e.g. "Username and Password not accepted") is what the administrator needs to fix it.
            throw ValidationException::withMessages(['test_address' => 'The test email could not be sent: '.str($exception->getMessage())->limit(300)]);
        }

        return back()->with('success', 'Test email sent to '.$request->validated('test_address').'.');
    }
}
