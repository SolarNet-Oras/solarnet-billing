<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Models\Setting;
use App\Services\Automation\AutomationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Nightly PostgreSQL logical backup.
 * Uses pg_dump via a shell process, writes gzipped .sql.gz files to
 * storage/app/backups/. Retains N days as configured in settings.
 *
 * Notes:
 *  - We use env variables (PGHOST, PGPORT, PGUSER, PGPASSWORD, PGDATABASE)
 *    derived from config('database.connections.pgsql.*') so no secrets in argv.
 *  - Requires the postgres client (pg_dump) to be present in PATH.
 *    In the Docker prod image it's bundled with php-fpm image or the
 *    backend service should install `postgresql-client`.
 */
class BackupDatabase extends Command
{
    protected $signature = 'automation:db-backup
                            {--triggered-by=schedule}
                            {--user-id=}';

    protected $description = 'Create a gzipped pg_dump backup and prune old ones';

    public function handle(): int
    {
        $log = AutomationRunner::run(
            AutomationLog::JOB_DB_BACKUP,
            (string) $this->option('triggered-by'),
            $this->option('user-id') ?: null,
            fn () => $this->doWork()
        );

        $this->line("Job: {$log->job}  status: {$log->status}  duration: {$log->duration_ms}ms");
        $this->line(json_encode($log->summary, JSON_PRETTY_PRINT));

        return $log->status === AutomationLog::STATUS_ERROR ? 1 : 0;
    }

    protected function doWork(): array
    {
        $automationEnabled = (bool) Setting::get('automation.enabled', true);
        if (!$automationEnabled) {
            return ['skipped' => true, 'reason' => 'automation.enabled=false'];
        }

        $retentionDays = (int) Setting::get('automation.backup_retention_days', 7);

        $cfg = config('database.connections.' . config('database.default'));
        if (($cfg['driver'] ?? '') !== 'pgsql') {
            throw new \RuntimeException("Only pgsql is supported for backups (got: {$cfg['driver']})");
        }

        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        $filename = 'db_' . now()->format('Ymd_His') . '_' . $cfg['database'] . '.sql.gz';
        $fullPath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        // Assemble pg_dump | gzip pipeline. We shell out through /bin/sh -c so gzip
        // can consume pg_dump's stdout without buffering the whole dump in PHP.
        $envSafe = [
            'PGHOST'     => $cfg['host'] ?? '127.0.0.1',
            'PGPORT'     => (string) ($cfg['port'] ?? '5432'),
            'PGUSER'     => $cfg['username'] ?? 'postgres',
            'PGPASSWORD' => $cfg['password'] ?? '',
            'PGDATABASE' => $cfg['database'] ?? '',
        ];

        $shellCmd = 'pg_dump --no-owner --no-privileges | gzip -9 > ' . escapeshellarg($fullPath);
        $process = Process::fromShellCommandline($shellCmd, null, $envSafe, null, 300); // 5 min timeout
        $process->run();

        if (!$process->isSuccessful() || !is_file($fullPath) || filesize($fullPath) < 100) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            @unlink($fullPath);
            throw new \RuntimeException('pg_dump failed: ' . ($err ?: 'unknown error'));
        }

        $sizeBytes = filesize($fullPath);

        // Prune old backups
        $pruned = $this->prune($backupDir, $retentionDays);

        return [
            'file'            => $filename,
            'path'            => $fullPath,
            'size_bytes'      => $sizeBytes,
            'size_human'      => $this->humanBytes($sizeBytes),
            'retention_days'  => $retentionDays,
            'pruned'          => $pruned,
        ];
    }

    protected function prune(string $dir, int $retentionDays): array
    {
        $pruned = [];
        $cutoff = now()->subDays($retentionDays)->timestamp;
        foreach ((array) glob($dir . '/db_*.sql.gz') as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $pruned[] = basename($file);
            }
        }
        return $pruned;
    }

    protected function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $b = (float) $bytes;
        while ($b >= 1024 && $i < count($units) - 1) { $b /= 1024; $i++; }
        return round($b, 2) . ' ' . $units[$i];
    }
}
