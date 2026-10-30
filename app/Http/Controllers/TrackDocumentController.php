<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrackDocumentRequest;
use App\Services\ClientStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TrackDocumentController extends Controller
{
    public function __invoke(TrackDocumentRequest $request, ClientStatusService $statuses): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        $result = $statuses->track($request->validated('barcode'), $request->validated('access_code'));
        if ($result === null) {
            $request->recordFailedAttempt();

            // One message for every miss, so a visitor cannot tell a real barcode from a wrong access code.
            throw ValidationException::withMessages(['tracking' => 'No document matches that barcode number and access code.']);
        }

        // Flashed, not stored: the status shows once and is gone on refresh.
        return to_route('login', ['tab' => 'track'])->with('tracking', $result);
    }
}
