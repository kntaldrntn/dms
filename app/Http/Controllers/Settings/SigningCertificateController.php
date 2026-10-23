<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SigningCertificateRequest;
use App\Models\User;
use App\Services\SigningCertificateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
