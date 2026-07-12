<?php

namespace App\Services\Ai\Tools;

/**
 * Guards used by super-admin code tools.
 *
 * SECURITY:
 *  - No writes. Ever. Tools that read files use this to reject paths outside
 *    an allow-list, deny traversal, deny binaries, and cap size.
 *  - The AI can only *display* code back to the user; the human copies/applies
 *    changes manually.
 */
final class CodeToolGuards
{
    /** Directory prefixes the AI is allowed to read from. */
    public const ALLOWED_ROOTS = [
        '/app/backend/app',
        '/app/backend/config',
        '/app/backend/database/migrations',
        '/app/backend/routes',
        '/app/backend/tests',
        '/app/frontend/src',
    ];

    /** Extensions the AI can read. Everything else is refused. */
    public const ALLOWED_EXTS = [
        'php', 'ts', 'tsx', 'js', 'jsx', 'json', 'md', 'yml', 'yaml',
        'css', 'scss', 'html', 'blade.php', 'env.example',
    ];

    /** Hard file-size cap (bytes) so a single tool call can't blow the token budget. */
    public const MAX_FILE_BYTES = 64_000;

    /** Hard cap on directory-listing rows. */
    public const MAX_LIST_ITEMS = 200;

    /**
     * Resolve + validate a caller-provided path.
     * Returns the real absolute path, or throws \InvalidArgumentException.
     */
    public static function resolveSafePath(string $raw): string
    {
        // No traversal, no absolute-outside-allowed
        if ($raw === '' || str_contains($raw, "\0")) {
            throw new \InvalidArgumentException('Empty or unsafe path.');
        }
        if (str_contains($raw, '..')) {
            throw new \InvalidArgumentException('Path traversal segments (..) are not allowed.');
        }

        $abs = realpath($raw);
        if ($abs === false) {
            // The path may not exist yet for list operations — fallback: normalise
            $abs = self::normalisePath($raw);
        }
        if (!self::isInsideAllowedRoot($abs)) {
            throw new \InvalidArgumentException("Path is outside the allowed roots: {$abs}");
        }
        return $abs;
    }

    public static function isInsideAllowedRoot(string $abs): bool
    {
        foreach (self::ALLOWED_ROOTS as $root) {
            $rootReal = realpath($root) ?: $root;
            if ($abs === $rootReal || str_starts_with($abs, rtrim($rootReal, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    public static function hasAllowedExt(string $abs): bool
    {
        $lower = strtolower($abs);
        foreach (self::ALLOWED_EXTS as $ext) {
            if (str_ends_with($lower, '.' . $ext)) {
                return true;
            }
        }
        return false;
    }

    protected static function normalisePath(string $path): string
    {
        // Collapse duplicate slashes, no `..` (already rejected above).
        return preg_replace('#/{2,}#', '/', $path);
    }
}
