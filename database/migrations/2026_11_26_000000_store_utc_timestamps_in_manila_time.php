<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The app timezone moves from UTC to Asia/Manila (user, 2026-11-26). Columns Laravel filled with now() in UTC are
 * moved forward 8 hours so they read as Manila time. Workflow columns (received_at, released_at, due_date,
 * completed_at, tagged_at, acknowledged_at) were already written in Manila time and are left alone.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'audit_logs' => ['created_at', 'updated_at'],
        'delinquency_tags' => ['created_at', 'updated_at'],
        'delivery_methods' => ['created_at', 'updated_at'],
        'departments' => ['created_at', 'updated_at'],
        'document_classification_document_type' => ['created_at', 'updated_at'],
        'document_classifications' => ['created_at', 'updated_at'],
        'document_trails' => ['created_at', 'updated_at'],
        'document_types' => ['created_at', 'updated_at'],
        'documents' => ['created_at', 'updated_at'],
        'login_histories' => ['login_at', 'created_at', 'updated_at'],
        'mail_settings' => ['created_at', 'updated_at'],
        'password_reset_tokens' => ['created_at'],
        'people' => ['created_at', 'updated_at'],
        'performance_standards' => ['created_at', 'updated_at'],
        'transaction_types' => ['created_at', 'updated_at'],
        'users' => ['email_verified_at', 'two_factor_confirmed_at', 'certificate_valid_until', 'created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift(8);
    }

    public function down(): void
    {
        $this->shift(-8);
    }

    private function shift(int $hours): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        DB::transaction(function () use ($hours, $sqlite) {
            foreach (self::COLUMNS as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $updates = [];
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue;
                    }
                    // Assigning updated_at by hand keeps MySQL's ON UPDATE CURRENT_TIMESTAMP from firing.
                    $updates[$column] = DB::raw($sqlite
                        ? sprintf("datetime(%s, '%+d hours')", $column, $hours)
                        : sprintf('DATE_ADD(%s, INTERVAL %d HOUR)', $column, $hours));
                }
                if ($updates) {
                    DB::table($table)->update($updates);
                }
            }
        });
    }
};
