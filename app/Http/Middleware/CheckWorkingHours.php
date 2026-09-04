<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class CheckWorkingHours
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Bypass check for System Administrators
        // NOTE: Adjust 'role' and 'System Admin' to match your actual database column and value.
        if (Auth::check() && Auth::user()->role === 'system_administrator') {
            return $next($request);
        }

        // 2. Set strict local timezone
        $now = Carbon::now('Asia/Manila');

        // 3. Block weekend access
        if ($now->isWeekend()) {
            return redirect()->route('documents.index')->with('error', 'System Locked: Document processing is only available Monday to Friday.');
        }

        // 4. Define 8:00 AM and 5:00 PM boundaries
        $start = Carbon::createFromTime(8, 0, 0, 'Asia/Manila');
        $end = Carbon::createFromTime(17, 0, 0, 'Asia/Manila');

        // 5. Block outside working hours
        if (!$now->between($start, $end)) {
            return redirect()->route('documents.index')->with('error', 'System Locked: Document processing is restricted to working hours (8:00 AM - 5:00 PM).');
        }

        return $next($request);
    }
}