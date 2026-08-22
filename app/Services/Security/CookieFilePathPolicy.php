<?php

namespace App\Services\Security;

class CookieFilePathPolicy
{
    public function resolve(mixed $configuredPath, ?string $storageRoot = null): ?string
    {
        $path = trim((string) $configuredPath);

        if ($path === '') {
            return null;
        }

        $storageRoot = $this->normalizeAbsolutePath($storageRoot ?: storage_path('app'));

        if ($storageRoot === null) {
            return null;
        }

        $candidate = $this->isAbsolutePath($path)
            ? $this->normalizeAbsolutePath($path)
            : $this->normalizeAbsolutePath($storageRoot.DIRECTORY_SEPARATOR.$path);

        if ($candidate === null || $this->isPublicPath($candidate)) {
            return null;
        }

        foreach ($this->allowedRoots($storageRoot) as $allowedRoot) {
            if ($this->isWithin($candidate, $allowedRoot)) {
                return str_replace('/', DIRECTORY_SEPARATOR, $candidate);
            }
        }

        return null;
    }

    public function containsSymbolicLink(string $path): bool
    {
        $cursor = $path;

        while ($cursor !== '' && $cursor !== dirname($cursor)) {
            if (is_link($cursor)) {
                return true;
            }

            $cursor = dirname($cursor);
        }

        return $cursor !== '' && is_link($cursor);
    }

    /** @return list<string> */
    private function allowedRoots(string $storageRoot): array
    {
        $configured = config('security.cookie_files.allowed_roots', []);
        $configured = is_array($configured) ? $configured : [];
        $roots = [$storageRoot, storage_path('app'), ...$configured];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $root): ?string => $this->normalizeAbsolutePath((string) $root),
            $roots,
        ))));
    }

    private function isPublicPath(string $candidate): bool
    {
        foreach ([storage_path('app/public'), public_path()] as $publicRoot) {
            $normalized = $this->normalizeAbsolutePath($publicRoot);

            if ($normalized !== null && $this->isWithin($candidate, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function isWithin(string $candidate, string $root): bool
    {
        $candidate = rtrim($candidate, '/');
        $root = rtrim($root, '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidate = mb_strtolower($candidate);
            $root = mb_strtolower($root);
        }

        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function normalizeAbsolutePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if (! $this->isAbsolutePath($path)) {
            return null;
        }

        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            $prefix = strtoupper(substr($path, 0, 2)).'/';
            $path = substr($path, 3);
        } elseif (str_starts_with($path, '//')) {
            $prefix = '//';
            $path = ltrim($path, '/');
        } else {
            $prefix = '/';
            $path = ltrim($path, '/');
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $prefix.implode('/', $segments);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }
}
