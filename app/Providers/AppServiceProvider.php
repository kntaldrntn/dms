<?php

namespace App\Providers;

use App\Models\Document;
use App\Policies\DocumentPolicy;
use App\Services\MailSettingsService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);

        // Web requests (e.g. password reset links) use the SMTP account saved in Email Settings. Console
        // commands skip it so migrations run before the table exists; queued mail jobs apply it themselves.
        if (! $this->app->runningInConsole()) {
            $this->app->make(MailSettingsService::class)->apply();
        }
    }
}
