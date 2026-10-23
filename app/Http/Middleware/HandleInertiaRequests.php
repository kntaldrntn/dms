<?php

namespace App\Http\Middleware;

use App\Models\Document;
use App\Services\IncomingDocumentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
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
                // Referral access depends on office (ADM Department Head), not role alone.
                'canViewReferrals' => (bool) $request->user()?->can('viewReferrals', Document::class),
                'canViewAdmApprovals' => (bool) $request->user()?->can('viewAdmApprovals', Document::class),
            ],
            // Lazy so the bell's polling can request it alone (`only: ['incoming']`).
            'incoming' => fn () => app(IncomingDocumentService::class)->forUser($request->user()),
        ]);
    }
}
