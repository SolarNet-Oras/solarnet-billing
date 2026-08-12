<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiTool;

/**
 * SUPER-ADMIN-ONLY: search the allowed source code roots.
 *
 * This intentionally uses PHP filesystem APIs instead of GNU grep: the
 * production PHP image is Alpine-based, where GNU-only grep options can fail.
 */
class SearchCodeTool implements AiTool
{
    public function name(): string { return 'search_code'; }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'search_code',
                'description' => 'Search a case-insensitive regular expression across project source. Returns up to 60 matches with file paths and line numbers. Read-only. Super-admin only.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => [
                            'type' => 'string',
                            'description' => 'Case-insensitive regular expression to find.',
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => 'Optional allowed source directory. Defaults to /var/www/app and /var/www/frontend/src.',
                        ],
                    ],
                    'required' => ['pattern'],
                ],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function execute(User $user, array $arguments): array
    {
        $pattern = (string) ($arguments['pattern'] ?? '');
        if ($pattern === '') return ['error' => 'pattern is required'];
        if (strlen($pattern) > 200) return ['error' => 'pattern too long (max 200 chars)'];

        $rawPath = (string) ($arguments['path'] ?? '');
        if ($rawPath !== '') {
            try {
                $roots = [CodeToolGuards::resolveSafePath($rawPath)];
            } catch (\InvalidArgumentException $e) {
                return ['error' => $e->getMessage()];
            }
        } else {
            $roots = ['/var/www/app', '/var/www/frontend/src'];
        }

        $regex = '/' . str_replace('/', '\\/', $pattern) . '/i';
        set_error_handler(static fn () => true);
        $validPattern = @preg_match($regex, '') !== false;
        restore_error_handler();
        if (!$validPattern) {
            return ['error' => 'Invalid search pattern. Use a valid regular expression.'];
        }

        $rows = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) continue;

            try {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
                );
            } catch (\UnexpectedValueException) {
                return ['error' => 'Search could not open source directory: ' . $root];
            }

            foreach ($files as $file) {
                $path = $file->getPathname();
                if (!$file->isFile() || !CodeToolGuards::hasAllowedExt($path)) continue;

                $lines = @file($path, FILE_IGNORE_NEW_LINES);
                if ($lines === false) continue;

                foreach ($lines as $lineNumber => $line) {
                    if (preg_match($regex, $line) !== 1) continue;
                    $rows[] = [
                        'file' => $path,
                        'line' => $lineNumber + 1,
                        'snippet' => mb_substr(trim($line), 0, 240),
                    ];
                    if (count($rows) >= 60) break 3;
                }
            }
        }

        return [
            'pattern' => $pattern,
            'roots' => $roots,
            'hits' => count($rows),
            'rows' => $rows,
        ];
    }
}
