<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('overlapping processes cannot both commit the same document version', function () {
    $database = tempnam(sys_get_temp_dir(), 'dms-concurrency-');
    $processes = [];
    config(['database.connections.concurrency' => [
        'driver' => 'sqlite', 'database' => $database, 'prefix' => '',
        'foreign_key_constraints' => true, 'journal_mode' => 'WAL', 'busy_timeout' => 5000,
    ]]);

    try {
        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);
        $connection = DB::connection('concurrency');
        $connection->table('departments')->insert(['id' => 1, 'code' => 'REC', 'name' => 'Records', 'state' => 1]);
        $connection->table('users')->insert([
            'id' => 1, 'name' => 'Records', 'email' => 'concurrency@example.test', 'password' => 'unused',
            'role' => 'records_manager', 'department_id' => 1, 'state' => 1,
        ]);
        $connection->table('transaction_types')->insert(['id' => 1, 'name' => 'Simple', 'processing_days' => 3, 'state' => 1]);
        $connection->table('documents')->insert([
            'id' => 1, 'barcode' => 'CONCURRENT', 'access_code' => 'ABC123', 'source_name' => 'Client',
            'subject_matter' => 'Original', 'department_id' => 1, 'transaction_type_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([1, 2] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent_document_update.php'), $database, (string) $worker], base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(20);
            $process->start();
            $processes[] = $process;
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
            $results[] = trim($process->getOutput());
        }
        sort($results);
        expect($results)->toBe(['saved', 'stale']);
        $document = $connection->table('documents')->first();
        expect((int) $document->workflow_version)->toBe(1);
        expect($document->subject_matter)->toBeIn(['Worker 1', 'Worker 2']);
        expect($connection->table('document_trails')->count())->toBe(0);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::purge('concurrency');
        foreach ([$database, $database.'-wal', $database.'-shm', $database.'.ready.1', $database.'.ready.2'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});
