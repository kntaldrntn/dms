<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SignatureImageRequest;
use App\Http\Requests\Settings\SigningCertificateRequest;
use App\Models\User;
use App\Services\SigningCertificateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SigningCertificateController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Certificate', [
            'canSign' => in_array($user->role, User::SIGNER_ROLES, true),
            'certificate' => $user->certificate_path ? [
                'subject' => $user->certificate_subject,
                'valid_until' => $user->certificate_valid_until?->toDateString(),
                'is_valid' => $user->hasValidCertificate(),
            ] : null,
            'hasSignatureImage' => (bool) $user->signature_path,
            // Private disk, served only to its owner; the version busts the browser cache after a replacement.
            'signatureImageUrl' => $user->signature_path
                ? route('signature-image.show', ['v' => substr(md5($user->signature_path), 0, 8)])
                : null,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(SigningCertificateRequest $request, SigningCertificateService $certificates): RedirectResponse
    {
        $certificates->store($request->user(), $request->file('certificate'), (string) $request->input('certificate_pin'));

        return back()->with('status', 'certificate-saved');
    }

    public function destroy(Request $request, SigningCertificateService $certificates): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, User::SIGNER_ROLES, true), 403);
        $certificates->remove($request->user());

        return back()->with('status', 'certificate-removed');
    }

    /** The signer's own signature image only: no user can open another's. */
    public function showSignatureImage(Request $request)
    {
        $path = $request->user()->signature_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'signature.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }

    public function updateSignatureImage(SignatureImageRequest $request, SigningCertificateService $certificates): RedirectResponse
    {
        $certificates->storeSignatureImage($request->user(), $request->file('signature_image'));

        return back()->with('status', 'signature-image-saved');
    }

    public function destroySignatureImage(Request $request, SigningCertificateService $certificates): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, User::SIGNER_ROLES, true), 403);
        $certificates->removeSignatureImage($request->user());

        return back()->with('status', 'signature-image-removed');
    }
}
