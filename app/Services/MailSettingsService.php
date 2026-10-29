<?php

namespace App\Services;

use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Outgoing-mail settings edited in the app (user decision 2026-10-26: no .env editing for the SMTP account).
 * The saved row overrides the MAIL_* values from .env; without a row, .env applies unchanged.
 */
class MailSettingsService
{
    public function current(): ?MailSetting
    {
        return MailSetting::query()->latest('id')->first();
    }

    /**
     * Point the SMTP mailer at the saved account. Called per web request and again by queued mail jobs,
     * because a long-running queue worker would otherwise keep the account it booted with.
     */
    public function apply(): void
    {
        try {
            $setting = $this->current();
        } catch (Throwable $exception) {
            Log::warning('Mail settings could not be read; using the .env mail settings.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return;
        }
        if (! $setting) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $setting->host,
            'mail.mailers.smtp.port' => $setting->port,
            // Port 465 is implicit TLS; other ports (587) upgrade with STARTTLS automatically.
            'mail.mailers.smtp.scheme' => $setting->port === 465 ? 'smtps' : null,
            'mail.mailers.smtp.username' => $setting->username,
            'mail.mailers.smtp.password' => $setting->password,
            'mail.from.address' => $setting->from_address,
            'mail.from.name' => $setting->from_name,
        ]);
        // Drop any mailer built with the previous settings.
        Mail::purge('smtp');
    }

    /** A blank password keeps the saved one, so the page never needs to send it back to the browser. */
    public function save(array $data, User $user): MailSetting
    {
        $setting = $this->current() ?? new MailSetting;
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }
        $setting->fill([...$data, 'updated_by' => $user->id])->save();

        Log::info('Mail settings updated.', ['user_id' => $user->id, 'host' => $setting->host, 'username' => $setting->username]);
        $this->apply();

        return $setting;
    }

    public function sendTest(string $address): void
    {
        $this->apply();
        Mail::raw(
            'This is a test email from the City Government of San Fernando Document Management System. '
            .'If you received it, client status emails will be delivered.',
            fn ($message) => $message->to($address)->subject('DMS test email'),
        );
    }
}
