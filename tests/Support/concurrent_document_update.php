<?php

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $database, $worker] = $argv;
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.journal_mode' => 'WAL', 'database.connections.sqlite.busy_timeout' => 5000]);
DB::purge('sqlite');

// Both processes must reach the write with version zero before either commits.
Document::updating(function () use ($database, $worker) {
    static $waited = false;
    if ($waited) {
        return;
    }
    $waited = true;
    file_put_contents($database.'.ready.'.$worker, 'ready');
    $deadline = microtime(true) + 10;
    while (! is_file($database.'.ready.1') || ! is_file($database.'.ready.2')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Concurrent worker did not reach the write barrier.');
        }
        usleep(10000);
    }
});

$exitCode = 0;
try {
    $document = Document::findOrFail(1);
    app(DocumentService::class)->save($document, User::findOrFail(1), [
        'workflow_version' => 0, 'subject_matter' => 'Worker '.$worker,
        'department_id' => 1, 'transaction_type_id' => 1,
    ]);
    echo 'saved';
} catch (ValidationException $exception) {
    if (! isset($exception->errors()['workflow_version'])) {
        fwrite(STDERR, $exception->getMessage());
        $exitCode = 1;
    } else {
        echo 'stale';
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    $exitCode = 1;
} finally {
    DB::disconnect('sqlite');
}
exit($exitCode);
