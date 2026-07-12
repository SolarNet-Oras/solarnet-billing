<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiTool;

/**
 * SUPER-ADMIN-ONLY: list files in a directory (non-recursive) so the AI can
 * discover code layout before asking to read a specific file.
 */
class ListSourceFilesTool implements AiTool
{
    public function name(): string { return 'list_source_files'; }

    public function schema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'list_source_files',
                'description' => 'List files and folders under a directory (one level, non-recursive). Restricted to project source roots. Use this to explore the codebase before asking to read a file. Super-admin only.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'Absolute directory path (e.g. /app/backend/app/Services/Ai/Tools). Defaults to /app/backend/app if omitted.',
                        ],
                    ],
                    'required' => [],
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
        $raw = (string) ($arguments['path'] ?? '/app/backend/app');
        try {
            $abs = CodeToolGuards::resolveSafePath($raw);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
        if (!is_dir($abs)) {
            return ['error' => 'Not a directory: ' . $abs];
        }

        $entries = [];
        $iter    = new \DirectoryIterator($abs);
        foreach ($iter as $item) {
            if ($item->isDot()) continue;
            $entries[] = [
                'name'      => $item->getFilename(),
                'is_dir'    => $item->isDir(),
                'bytes'     => $item->isFile() ? $item->getSize() : null,
                'full_path' => $item->getPathname(),
            ];
            if (count($entries) >= CodeToolGuards::MAX_LIST_ITEMS) break;
        }
        usort($entries, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] <=> $a['is_dir'];
            return strcmp($a['name'], $b['name']);
        });

        return [
            'path'    => $abs,
            'count'   => count($entries),
            'entries' => $entries,
        ];
    }
}
