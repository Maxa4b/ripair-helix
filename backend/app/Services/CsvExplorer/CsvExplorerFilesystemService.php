<?php

namespace App\Services\CsvExplorer;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CsvExplorerFilesystemService
{
    public function list(string $relativePath = ''): array
    {
        $rootPath = $this->resolvedRootPath();
        $targetPath = $this->resolveDirectoryPath($relativePath);
        $relativeCurrentPath = $this->relativePath($targetPath);

        $entries = collect(File::directories($targetPath))
            ->map(fn (string $path) => $this->buildDirectoryEntry($path))
            ->concat(
                collect(File::files($targetPath))
                    ->filter(fn (\SplFileInfo $file) => $this->isAllowedFile($file->getFilename()))
                    ->map(fn (\SplFileInfo $file) => $this->buildFileEntry($file))
            )
            ->sortBy([
                ['type', 'asc'],
                ['name', 'asc'],
            ], SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'root' => [
                'label' => (string) config('csv_explorer.label', 'CSV Explorer VPS'),
                'path' => $rootPath,
            ],
            'current_path' => $relativeCurrentPath,
            'parent_path' => $relativeCurrentPath === '' ? null : Str::beforeLast($relativeCurrentPath, '/'),
            'entries' => $entries,
        ];
    }

    public function fileForStream(string $relativePath): array
    {
        $path = $this->resolveFilePath($relativePath);

        return [
            'absolute_path' => $path,
            'relative_path' => $this->relativePath($path),
            'name' => basename($path),
            'size' => File::size($path),
            'mime_type' => File::mimeType($path) ?: 'text/csv',
            'modified_at' => date(DATE_ATOM, File::lastModified($path)),
        ];
    }

    private function buildDirectoryEntry(string $path): array
    {
        return [
            'type' => 'directory',
            'name' => basename($path),
            'path' => $this->relativePath($path),
            'size' => null,
            'modified_at' => date(DATE_ATOM, File::lastModified($path)),
            'extension' => null,
        ];
    }

    private function buildFileEntry(\SplFileInfo $file): array
    {
        return [
            'type' => 'file',
            'name' => $file->getFilename(),
            'path' => $this->relativePath($file->getPathname()),
            'size' => $file->getSize(),
            'modified_at' => date(DATE_ATOM, $file->getMTime()),
            'extension' => strtolower($file->getExtension()),
        ];
    }

    private function resolveDirectoryPath(string $relativePath): string
    {
        $path = $this->resolveExistingPath($relativePath);

        if (! File::isDirectory($path)) {
            throw new RuntimeException('Le dossier demande est introuvable.');
        }

        return $path;
    }

    private function resolveFilePath(string $relativePath): string
    {
        $path = $this->resolveExistingPath($relativePath);

        if (! File::isFile($path) || ! $this->isAllowedFile($path)) {
            throw new RuntimeException('Le fichier demande est introuvable ou non autorise.');
        }

        return $path;
    }

    private function resolveExistingPath(string $relativePath): string
    {
        $rootPath = $this->resolvedRootPath();
        $normalized = $this->normalizeRelativePath($relativePath);
        $candidate = $normalized === ''
            ? $rootPath
            : $rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $resolved = realpath($candidate);

        if (! is_string($resolved) || $resolved === '') {
            throw new RuntimeException('Le chemin demande est introuvable.');
        }

        return $this->assertInsideRoot($resolved);
    }

    private function resolvedRootPath(): string
    {
        $configured = (string) config('csv_explorer.root', '');
        if ($configured === '') {
            throw new RuntimeException('CSV Explorer root non configure.');
        }

        $resolved = realpath($configured);

        if (! is_string($resolved) || $resolved === '' || ! File::isDirectory($resolved)) {
            throw new RuntimeException(sprintf('CSV Explorer root introuvable: %s', $configured));
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function relativePath(string $absolutePath): string
    {
        $root = $this->resolvedRootPath();
        $trimmed = ltrim(str_replace($root, '', $absolutePath), DIRECTORY_SEPARATOR);

        return str_replace(DIRECTORY_SEPARATOR, '/', $trimmed);
    }

    private function normalizeRelativePath(string $relativePath): string
    {
        $normalized = trim(str_replace('\\', '/', $relativePath));
        $normalized = trim($normalized, '/');

        return $normalized === '.' ? '' : $normalized;
    }

    private function assertInsideRoot(string $absolutePath): string
    {
        $root = $this->resolvedRootPath();

        if ($absolutePath !== $root && ! str_starts_with($absolutePath, $root . DIRECTORY_SEPARATOR)) {
          throw new RuntimeException('Chemin hors du perimetre autorise.');
        }

        return $absolutePath;
    }

    private function isAllowedFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = collect(config('csv_explorer.extensions', []))
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->map(fn (string $value) => strtolower($value))
            ->values();

        return $allowed->contains($extension);
    }
}
