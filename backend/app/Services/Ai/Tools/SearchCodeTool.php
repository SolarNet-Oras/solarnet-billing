<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiTool;
use Symfony\Component\Process\Process;

/**
 * SUPER-ADMIN-ONLY: grep the codebase for a literal or regex pattern.
 * Uses `grep -rnE` under the hood so we get real speed + line numbers.
 * ONLY searches allowed roots. Read-only.
 */
class SearchCodeTool implements AiTool
{
    public function name(): string { return 'search_code'; }

    public function schema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'search_code',
                'description' => 'Search for a text pattern (POSIX extended regex) across the project source. Returns up to 60 hits with file paths and line numbers. Use to locate implementations before proposing changes. Super-admin only.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'pattern' => [
                            'type'        => 'string',
                            'description' => 'Search pattern. Passed to grep -E (POSIX ERE). Case-insensitive by default.',
                        ],
                        'path' => [
                            'type'        => 'string',
                            'description' => 'Optional root to limit the search. Must be inside an allowed root. Default: /app/backend/app + /app/frontend/src.',
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
        $roots = [];
        if ($rawPath !== '') {
            try {
                $roots[] = CodeToolGuards::resolveSafePath($rawPath);
            } catch (\InvalidArgumentException $e) {
                return ['error' => $e->getMessage()];
            }
        } else {
            $roots = ['/app/backend/app', '/app/frontend/src'];
        }

        // grep -rnE -i --include patterns, refuse to read binaries via -I
        $cmd = ['grep', '-rnEI', '-i',
            '--exclude-dir=node_modules',
            '--exclude-dir=vendor',
            '--exclude-dir=.git',
            '--include=*.php', '--include=*.ts', '--include=*.tsx',
            '--include=*.js', '--include=*.jsx', '--include=*.json',
            '--include=*.md', '--include=*.blade.php',
            $pattern,
            ...$roots,
        ];

        $proc = new Process($cmd, null, null, null, 15);
        $proc->run();
        $out = $proc->getOutput();
        // grep exit code 1 means "no matches" — not an error for us
        if (!$proc->isSuccessful() && $proc->getExitCode() !== 1) {
            return ['error' => 'search failed: ' . trim($proc->getErrorOutput())];
        }

        $lines = array_slice(array_filter(explode("\n", $out)), 0, 60);
        $rows = [];
        foreach ($lines as $line) {
            // format: /path:LINE:content
            $parts = explode(':', $line, 3);
            if (count($parts) === 3) {
                $rows[] = [
                    'file'    => $parts[0],
                    'line'    => (int) $parts[1],
                    'snippet' => mb_substr(trim($parts[2]), 0, 240),
                ];
            }
        }
        return [
            'pattern' => $pattern,
            'roots'   => $roots,
            'hits'    => count($rows),
            'rows'    => $rows,
        ];
    }
}
