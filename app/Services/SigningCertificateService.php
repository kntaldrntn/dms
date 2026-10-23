<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Each signer's own PNPKI certificate (FGD Phase 2c-2). The .p12 is kept on the private `certificates` disk;
 * its PIN is only used in memory to open it, at upload (to check it) and at each signing. It is never stored.
 */
class SigningCertificateService
{
    public function store(User $user, UploadedFile $file, string $pin): void
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $certificate = $this->certificate($this->open($contents, $pin, 'certificate_pin')['cert']);
        if ($certificate['validTo_time_t'] <= now()->timestamp) {
            throw ValidationException::withMessages(['certificate' => 'This certificate has expired. Upload a current PNPKI certificate.']);
        }

        $path = "users/{$user->id}.p12";
        if (! Storage::disk('certificates')->put($path, $contents)) {
            throw ValidationException::withMessages(['certificate' => 'The certificate could not be saved. Try again.']);
        }
        $user->forceFill([
            'certificate_path' => $path,
            'certificate_subject' => $certificate['subject']['CN'] ?? $user->name,
            'certificate_valid_until' => now()->setTimestamp($certificate['validTo_time_t']),
        ])->save();
        Log::info('Signing certificate uploaded.', ['user_id' => $user->id, 'valid_until' => $user->certificate_valid_until]);
    }

    public function remove(User $user): void
    {
        if ($user->certificate_path) {
            Storage::disk('certificates')->delete($user->certificate_path);
        }
        $user->forceFill(['certificate_path' => null, 'certificate_subject' => null, 'certificate_valid_until' => null])->save();
        Log::info('Signing certificate removed.', ['user_id' => $user->id]);
    }

    /**
     * Open the signer's certificate for one signing.
     *
     * @return array{cert:string, pkey:string, extracerts:list<string>, subject:string}
     */
    public function credentials(User $user, string $pin): array
    {
        if (! $user->hasValidCertificate() || ! Storage::disk('certificates')->exists($user->certificate_path)) {
            throw ValidationException::withMessages(['certificate_pin' => 'You have no valid signing certificate. Upload your PNPKI certificate in Settings → Signing certificate.']);
        }
        $credentials = $this->open((string) Storage::disk('certificates')->get($user->certificate_path), $pin, 'certificate_pin');

        return [
            'cert' => $credentials['cert'],
            'pkey' => $credentials['pkey'],
            'extracerts' => array_values($credentials['extracerts'] ?? []),
            'subject' => $user->certificate_subject ?? $user->name,
        ];
    }

    /** @return array{cert:string, pkey:string, extracerts?:list<string>} */
    private function open(string $contents, string $pin, string $field): array
    {
        while (openssl_error_string() !== false) {
            // Clear errors left by earlier OpenSSL calls so the message below describes this one.
        }
        if (openssl_pkcs12_read($contents, $credentials, $pin) && ! empty($credentials['cert']) && ! empty($credentials['pkey'])) {
            return $credentials;
        }

        $error = (string) openssl_error_string();
        Log::warning('Signing certificate could not be opened.', ['openssl' => $error]);
        throw ValidationException::withMessages([$field => match (true) {
            str_contains($error, 'mac verify') => 'The PIN does not match this certificate.',
            str_contains($error, 'unsupported') => 'This certificate uses an older encryption the server cannot open. Ask the System Administrator to re-export it.',
            default => 'This file is not a readable PNPKI certificate (.p12) with a private key.',
        }]);
    }

    /** @return array<string, mixed> */
    private function certificate(string $pem): array
    {
        $parsed = openssl_x509_parse($pem);
        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            throw ValidationException::withMessages(['certificate' => 'The certificate details could not be read.']);
        }

        return $parsed;
    }
}
