<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            // Every office a release went to; released_to keeps the first one for single-office consumers.
            $table->json('released_to_offices')->nullable()->after('released_to');
        });

        // Backfill past multi-office dispatches: their recipients are the "Dispatched from Records"
        // arrivals recorded within a few seconds of the dispatch release.
        $dispatches = DB::table('document_trails')
            ->where('released_action', 'Dispatched to target office(s)')
            ->whereNotNull('released_at')
            ->get(['id', 'document_id', 'released_at', 'released_to']);

        foreach ($dispatches as $dispatch) {
            $releasedAt = Carbon::parse($dispatch->released_at);
            $recipients = DB::table('document_trails')
                ->where('document_id', $dispatch->document_id)
                ->where('received_action', 'Dispatched from Records')
                ->whereBetween('received_at', [$releasedAt->copy()->subSeconds(5), $releasedAt->copy()->addSeconds(5)])
                ->orderBy('id')
                ->pluck('department_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($recipients === []) {
                $recipients = $dispatch->released_to ? [(int) $dispatch->released_to] : [];
            }
            if ($recipients !== []) {
                DB::table('document_trails')->where('id', $dispatch->id)->update(['released_to_offices' => json_encode($recipients)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('document_trails', function (Blueprint $table) {
            $table->dropColumn('released_to_offices');
        });
    }
};
