<?php

use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

test('framework timestamps are stored in Manila time, like the workflow ones', function () {
    $this->travelTo(Carbon::parse('2026-11-26 07:30:00', 'Asia/Manila'));

    $office = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);

    expect(config('app.timezone'))->toBe('Asia/Manila')
        ->and(DB::table('departments')->where('id', $office->id)->value('created_at'))->toBe('2026-11-26 07:30:00');
});

test('the migration moves UTC-era timestamps forward 8 hours and leaves workflow times alone', function () {
    $office = Department::create(['code' => 'ENG', 'name' => 'Engineering', 'state' => 1]);
    DB::table('departments')->where('id', $office->id)->update(['created_at' => '2026-11-25 23:30:00', 'updated_at' => '2026-11-25 23:30:00']);

    $migration = require database_path('migrations/2026_11_26_000000_store_utc_timestamps_in_manila_time.php');
    $migration->up();

    $row = DB::table('departments')->where('id', $office->id)->first();
    expect($row->created_at)->toBe('2026-11-26 07:30:00')
        ->and($row->updated_at)->toBe('2026-11-26 07:30:00');

    $migration->down();
    expect(DB::table('departments')->where('id', $office->id)->value('created_at'))->toBe('2026-11-25 23:30:00');
});
