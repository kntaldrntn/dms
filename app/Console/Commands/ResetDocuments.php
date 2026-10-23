<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentTrail;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * FGD fresh start (user-approved 2026-10-21): delete every document, its custody trail and its files, keeping
 * offices, users, registries, the People Directory, e-signatures and certificates. This is the only code that
 * deletes trail history, and it never runs without an explicit confirmation.
 */
class ResetDocuments extends Command
{
    use ConfirmableTrait;

    protected $signature = 'dms:reset-documents
        {--dry-run : Show what would be deleted and change nothing}
        {--force : Skip the typed confirmation (and the production prompt)}';

    protected $description = 'Delete all documents, their trails and uploaded/signed files for a fresh start (keeps offices, users and registries)';

    /** Directories that hold only document files: intake scans (public) and approval scans/stamped PDFs (local). */
    private const FILE_DIRECTORIES = ['public' => 'documents', 'local' => 'signed-documents'];

    public function handle(): int
    {
        $files = collect(self::FILE_DIRECTORIES)->map(fn (string $directory, string $disk) => collect(Storage::disk($disk)->allFiles($directory)));
        $counts = [
            'documents' => Document::query()->count(),
            'document trail entries' => DocumentTrail::query()->count(),
            'uploaded scans (storage/app/public/documents)' => $files['public']->count(),
            'signed files (storage/app/private/signed-documents)' => $files['local']->count(),
        ];

        $this->warn('This permanently deletes:');
        $this->table(['What', 'Count'], collect($counts)->map(fn ($count, $what) => [$what, $count])->values()->all());
        $this->line('Kept: offices, users, document types and other registries, People Directory, e-signatures, certificates, login history, audit logs.');

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && $this->ask('Type RESET to delete all document data') !== 'RESET') {
            $this->error('Cancelled: nothing was changed.');

            return self::FAILURE;
        }

        // Rows first, in one transaction; files only once the rows are gone, so a failed delete leaves both in place.
        DB::transaction(function () {
            DocumentTrail::query()->delete();
            Document::query()->delete();
        });
        if (DB::getDriverName() === 'mysql') {
            // New registrations start again from id 1 (DDL, so it runs after the transaction commits).
            DB::statement('ALTER TABLE document_trails AUTO_INCREMENT = 1');
            DB::statement('ALTER TABLE documents AUTO_INCREMENT = 1');
        }
        $failed = $files->flatMap(fn ($paths, string $disk) => collect($paths)->reject(fn (string $path) => Storage::disk($disk)->delete($path))
            ->map(fn (string $path) => "{$disk}:{$path}"));

        Log::warning('All document data was reset.', [...$counts, 'files_not_deleted' => $failed->values()->all()]);
        if ($failed->isNotEmpty()) {
            $this->warn('Documents were deleted, but these files could not be removed: '.$failed->implode(', '));

            return self::FAILURE;
        }
        $this->info('Done: all document data was deleted.');

        return self::SUCCESS;
    }
}
