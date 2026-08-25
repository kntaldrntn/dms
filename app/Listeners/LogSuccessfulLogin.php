<?php

namespace App\Listeners;

use App\Models\LoginHistory;
use Carbon\Carbon;
use Illuminate\Auth\Events\Login;

class LogSuccessfulLogin
{
    public function handle(Login $event): void
    {
        if ($event->user) {
            LoginHistory::create([
                'user_id' => $event->user->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'login_at' => Carbon::now(),
            ]);
        }
    }
}