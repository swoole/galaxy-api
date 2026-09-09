<?php

namespace App\Support;

final class GitReference
{
    /**
     * Validate a branch shorthand using the relevant git-check-ref-format rules.
     * Unicode branch names are allowed; control characters and Git's ambiguous
     * revision syntax are not.
     */
    public static function validBranch(string $branch): bool
    {
        if ($branch === '@' || str_starts_with($branch, '-')) {
            return false;
        }
        return self::validReferenceName($branch);
    }

    /**
     * Validate a tag name as refs/tags/<tag>. Unlike a branch shorthand, Git
     * permits values such as "@" and names beginning with a dash here because
     * the runner always checks out the fully qualified refs/tags/... ref.
     */
    public static function validTag(string $tag): bool
    {
        return self::validReferenceName($tag);
    }

    private static function validReferenceName(string $name): bool
    {
        if ($name === '' || strlen($name) > 255 || str_starts_with($name, '/')
            || str_ends_with($name, '/') || str_ends_with($name, '.')
            || str_ends_with($name, '.lock') || str_contains($name, '..')
            || str_contains($name, '//') || str_contains($name, '@{')) {
            return false;
        }
        foreach (explode('/', $name) as $component) {
            if ($component === '' || str_starts_with($component, '.') || str_ends_with($component, '.lock')) {
                return false;
            }
        }
        return preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $name) === 0;
    }
}
