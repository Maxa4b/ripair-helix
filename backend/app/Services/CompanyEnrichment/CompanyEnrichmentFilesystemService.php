<?php

namespace App\Services\CompanyEnrichment;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CompanyEnrichmentFilesystemService
{
    public function listInputs(string $relativePath = ''): array
    {
        $rootPath = $this->resolvedInputRootPath();
        $targetPath = $this->resolveDirectoryPath($relativePath);
        $relativeCurrentPath = $this->relativePath($targetPath, $rootPath);

        $entries = collect(File::directories($targetPath))
            ->map(fn (string $path) => $this->buildDirectoryEntry($path, $rootPath))
            ->concat(
                collect(File::files($targetPath))
                    ->filter(fn (\SplFileInfo $file) => $this->isAllowedInputFile($file->getFilename()))
                    ->map(fn (\SplFileInfo $file) => $this->buildFileEntry($file, $rootPath))
            )
            ->sortBy([
                ['type', 'asc'],
                ['name', 'asc'],
            ], SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'root' => [
                'label' => (string) config('company_enrichment.input_label', 'Sources VPS'),
                'path' => $rootPath,
            ],
            'current_path' => $relativeCurrentPath,
            'parent_path' => $relativeCurrentPath === '' ? null : Str::beforeLast($relativeCurrentPath, '/'),
            'entries' => $entries,
        ];
    }

    public function inputFile(string $relativePath): array
    {
        $rootPath = $this->resolvedInputRootPath();
        $path = $this->resolveFilePath($relativePath);

        return [
            'absolute_path' => $path,
            'relative_path' => $this->relativePath($path, $rootPath),
            'name' => basename($path),
            'size' => File::size($path),
            'mime_type' => File::mimeType($path) ?: 'application/octet-stream',
            'modified_at' => date(DATE_ATOM, File::lastModified($path)),
        ];
    }

    public function defaultConfigPath(): string
    {
        $configured = (string) config('company_enrichment.default_config', '');
        $resolved = realpath($configured);

        if (! is_string($resolved) || $resolved === '' || ! File::isFile($resolved)) {
            throw new RuntimeException('Configuration YAML par defaut introuvable.');
        }

        return $resolved;
    }

    public function pipelineRoot(): string
    {
        $configured = (string) config('company_enrichment.pipeline_root', '');
        $resolved = realpath($configured);

        if (! is_string($resolved) || $resolved === '' || ! File::isDirectory($resolved)) {
            throw new RuntimeException('Racine du pipeline company_enrichment introuvable.');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function makeOutputDirectory(string $jobId): string
    {
        $root = $this->resolvedOutputRootPath();
        $directory = $root . DIRECTORY_SEPARATOR . $jobId;
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    public function generatedSeedOutput(): array
    {
        $root = $this->resolvedInputRootPath();
        $directory = trim((string) config('company_enrichment.generated_seed_directory', '_generated'), '/\\');
        $filename = trim((string) config('company_enrichment.generated_seed_filename', 'domain_seed.csv'));
        $relativePath = trim(($directory !== '' ? $directory . '/' : '') . $filename, '/');
        $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return [
            'absolute_path' => $absolutePath,
            'relative_path' => $relativePath,
            'name' => basename($absolutePath),
        ];
    }

    /**
     * @return list<array{key:string,name:string,relative_path:string,size:int,modified_at:string}>
     */
    public function listArtifacts(string $outputDirectory): array
    {
        if (! File::isDirectory($outputDirectory)) {
            return [];
        }

        return collect(File::files($outputDirectory))
            ->sortBy(fn (\SplFileInfo $file) => $file->getFilename(), SORT_NATURAL | SORT_FLAG_CASE)
            ->map(function (\SplFileInfo $file): array {
                return [
                    'key' => sha1($file->getFilename()),
                    'name' => $file->getFilename(),
                    'relative_path' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'modified_at' => date(DATE_ATOM, $file->getMTime()),
                ];
            })
            ->values()
            ->all();
    }

    public function artifactForDownload(string $outputDirectory, string $relativePath): array
    {
        $this->assertInsideOutputRoot($outputDirectory);
        $candidate = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($relativePath, '/'));
        $resolved = realpath($candidate);

        if (! is_string($resolved) || $resolved === '' || ! File::isFile($resolved)) {
            throw new RuntimeException('Artefact introuvable.');
        }

        $this->assertInsideOutputRoot(dirname($resolved));

        return [
            'absolute_path' => $resolved,
            'name' => basename($resolved),
            'size' => File::size($resolved),
            'mime_type' => File::mimeType($resolved) ?: 'application/octet-stream',
            'modified_at' => date(DATE_ATOM, File::lastModified($resolved)),
        ];
    }

    private function buildDirectoryEntry(string $path, string $rootPath): array
    {
        return [
            'type' => 'directory',
            'name' => basename($path),
            'path' => $this->relativePath($path, $rootPath),
            'size' => null,
            'modified_at' => date(DATE_ATOM, File::lastModified($path)),
            'extension' => null,
        ];
    }

    private function buildFileEntry(\SplFileInfo $file, string $rootPath): array
    {
        return [
            'type' => 'file',
            'name' => $file->getFilename(),
            'path' => $this->relativePath($file->getPathname(), $rootPath),
            'size' => $file->getSize(),
            'modified_at' => date(DATE_ATOM, $file->getMTime()),
            'extension' => strtolower($file->getExtension()),
        ];
    }

    private function resolveDirectoryPath(string $relativePath): string
    {
        $path = $this->resolveExistingInputPath($relativePath);

        if (! File::isDirectory($path)) {
            throw new RuntimeException('Le dossier demande est introuvable.');
        }

        return $path;
    }

    private function resolveFilePath(string $relativePath): string
    {
        $path = $this->resolveExistingInputPath($relativePath);

        if (! File::isFile($path) || ! $this->isAllowedInputFile($path)) {
            throw new RuntimeException('Le fichier demande est introuvable ou non autorise.');
        }

        return $path;
    }

    private function resolveExistingInputPath(string $relativePath): string
    {
        $rootPath = $this->resolvedInputRootPath();
        $normalized = $this->normalizeRelativePath($relativePath);
        $candidate = $normalized === ''
            ? $rootPath
            : $rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $resolved = realpath($candidate);

        if (! is_string($resolved) || $resolved === '') {
            throw new RuntimeException('Le chemin demande est introuvable.');
        }

        return $this->assertInsideRoot($resolved, $rootPath);
    }

    private function resolvedInputRootPath(): string
    {
        $configured = (string) config('company_enrichment.input_root', '');
        $resolved = realpath($configured);

        if (! is_string($resolved) || $resolved === '' || ! File::isDirectory($resolved)) {
            throw new RuntimeException(sprintf('Company enrichment input root introuvable: %s', $configured));
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function resolvedOutputRootPath(): string
    {
        $configured = (string) config('company_enrichment.output_root', '');
        File::ensureDirectoryExists($configured);
        $resolved = realpath($configured);

        if (! is_string($resolved) || $resolved === '' || ! File::isDirectory($resolved)) {
            throw new RuntimeException(sprintf('Company enrichment output root introuvable: %s', $configured));
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function relativePath(string $absolutePath, string $root): string
    {
        $trimmed = ltrim(str_replace($root, '', $absolutePath), DIRECTORY_SEPARATOR);

        return str_replace(DIRECTORY_SEPARATOR, '/', $trimmed);
    }

    private function normalizeRelativePath(string $relativePath): string
    {
        $normalized = trim(str_replace('\\', '/', $relativePath));
        $normalized = trim($normalized, '/');

        return $normalized === '.' ? '' : $normalized;
    }

    private function assertInsideRoot(string $absolutePath, string $root): string
    {
        if ($absolutePath !== $root && ! str_starts_with($absolutePath, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Chemin hors du perimetre autorise.');
        }

        return $absolutePath;
    }

    private function assertInsideOutputRoot(string $absolutePath): string
    {
        return $this->assertInsideRoot($absolutePath, $this->resolvedOutputRootPath());
    }

    private function isAllowedInputFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = collect(config('company_enrichment.input_extensions', []))
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->map(fn (string $value) => strtolower($value))
            ->values();

        return $allowed->contains($extension);
    }
}
