<?php

namespace App\Http\Middleware;

use App\Models\Document;
use App\Services\IncomingDocumentService;
use App\Services\SidebarCountService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                // ADM Approvals depends on the office (every ADM user receives), not role alone.
                'canViewAdmApprovals' => (bool) $request->user()?->can('viewAdmApprovals', Document::class),
                // OCM Approvals: every OCM user records the Mayor's paper approvals.
                'canViewOcmApprovals' => (bool) $request->user()?->can('viewOcmApprovals', Document::class),
                // Delinquency Monitor (FGD Phase 5): the City Administrator's office.
                'canMonitorDelinquency' => (bool) $request->user()?->can('monitorDelinquency', Document::class),
                // Sidebar office card.
                'office' => $request->user()?->department?->only(['code', 'name']),
            ],
            'features' => ['paperless' => (bool) config('services.paperless.enabled')],
            // Header clock (PhTimeClock) starts from the server's time, not the PC's.
            'serverNow' => fn () => now()->getTimestampMs(),
            // Lazy so the bell's polling can request it alone (`only: ['incoming']`).
            'incoming' => fn () => app(IncomingDocumentService::class)->forUser($request->user()),
            // Sidebar badges: deferred so they never slow the page itself; refreshed with the bell's poll.
            'sidebarCounts' => $request->user()
                ? Inertia::defer(fn () => app(SidebarCountService::class)->forUser($request->user()), 'sidebar')
                : null,
        ]);
    }
}
