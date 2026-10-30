<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Public client lookup (no account; user-approved exception to the auth rule, 2026-10-30). The barcode +
 * access code pair is the credential, so wrong guesses are rate limited per visitor like sign-in attempts.
 */
class TrackDocumentRequest extends FormRequest
{
    public const MAX_FAILED_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', 'max:20'],
            'access_code' => ['required', 'string', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return ['barcode' => 'barcode number'];
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_FAILED_ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([
            'tracking' => 'Too many tries. Please wait '.ceil(RateLimiter::availableIn($this->throttleKey()) / 60).' minute(s) and try again.',
        ]);
    }

    public function recordFailedAttempt(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    /** Per visitor, not per barcode: the limit must also slow down guessing across many barcodes. */
    public function throttleKey(): string
    {
        return 'track-document|'.$this->ip();
    }
}
