<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiTool;

/**
 * SUPER-ADMIN-ONLY: read a single source file so the AI can review or refactor it.
 * Read-only. No writes.
 */
class ReadSourceFileTool implements AiTool
{
    public function name(): string { return 'read_source_file'; }

    public function schema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'read_source_file',
                'description' => 'Read a single source file (PHP/TS/TSX/JSON/MD/CSS) so you can review, refactor, or suggest improvements. Restricted to /var/www/app, /var/www/config, /var/www/database/migrations, /var/www/routes, /var/www/tests, and /var/www/frontend/src. Max 64 KB. NEVER modifies anything on disk. Super-admin only.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'Absolute path starting with /var/www/... or /var/www/frontend/src/...',
                        ],
                    ],
                    'required' => ['path'],
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
        $raw = (string) ($arguments['path'] ?? '');
        try {
            $abs = CodeToolGuards::resolveSafePath($raw);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        if (!is_file($abs)) {
            return ['error' => 'File not found: ' . $abs];
        }
        if (!CodeToolGuards::hasAllowedExt($abs)) {
            return ['error' => 'Extension not allowed for reading: ' . $abs];
        }

        $size = @filesize($abs);
        if ($size !== false && $size > CodeToolGuards::MAX_FILE_BYTES) {
            return [
                'error' => sprintf(
                    'File is too large (%d bytes > cap %d). Ask for a smaller section or a specific function.',
                    $size,
                    CodeToolGuards::MAX_FILE_BYTES
                ),
            ];
        }

        $content = @file_get_contents($abs);
        if ($content === false) {
            return ['error' => 'Could not read file: ' . $abs];
        }

        return [
            'path'    => $abs,
            'bytes'   => strlen($content),
            'lines'   => substr_count($content, "\n") + 1,
            'content' => $content,
        ];
    }
}
