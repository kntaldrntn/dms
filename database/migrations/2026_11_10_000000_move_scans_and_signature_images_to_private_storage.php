<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Intake scans and signature images were on the public disk, so anyone with the URL could open them. They now live
 * on the private `local` disk and are served by authorized routes. Paths stay the same (documents/…, signatures/…),
 * so no database row changes: only the files move.
 */
return new class extends Migration
{
    private const DIRECTORIES = ['documents', 'signatures'];

    public function up(): void
    {
        $this->move('public', 'local');
    }

    public function down(): void
    {
        $this->move('local', 'public');
    }

    private function move(string $from, string $to): void
    {
        foreach (self::DIRECTORIES as $directory) {
            foreach (Storage::disk($from)->allFiles($directory) as $path) {
                $stream = Storage::disk($from)->readStream($path);
                $copied = $stream && Storage::disk($to)->writeStream($path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                // Delete the source only after the copy succeeded, so a failure never loses a file.
                if (! $copied) {
                    Log::error('A stored file could not be moved between disks.', compact('from', 'to', 'path'));

                    throw new RuntimeException("Could not move {$from}:{$path} to the {$to} disk.");
                }
                Storage::disk($from)->delete($path);
            }
        }
    }
};
