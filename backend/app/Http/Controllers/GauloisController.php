<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GauloisController extends Controller
{
    public function meta(): JsonResponse
    {
        $seedFiles = $this->collectSeedSummaries();
        $config = [
            'python' => config('gaulois.python'),
            'script_path' => config('gaulois.script_path'),
            'working_directory' => config('gaulois.working_directory'),
            'timeout' => config('gaulois.timeout'),
        ];

        return response()->json([
            'config' => $config,
            'seeds' => $seedFiles,
        ]);
    }

    public function logs(): JsonResponse
    {
        $logsDirectory = config('gaulois.logs_directory');

        if (! $logsDirectory || ! File::isDirectory($logsDirectory)) {
            return response()->json([
                'logs' => [],
            ]);
        }

        $files = collect(File::files($logsDirectory))
            ->map(function ($file) {
                return [
                    'filename' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'modified_at' => $file->getMTime(),
                    'completed' => $this->isLogComplete($file->getPathname()),
                ];
            })
            ->sortByDesc('modified_at')
            ->values()
            ->map(function (array $item) {
                return [
                    'filename' => $item['filename'],
                    'size' => $item['size'],
                    'modified_at' => date(DATE_ATOM, $item['modified_at']),
                    'completed' => $item['completed'],
                ];
            });

        return response()->json([
            'logs' => $files,
        ]);
    }

    public function showLog(string $filename): JsonResponse
    {
        $path = $this->resolveLogPath($filename);

        if (! File::exists($path)) {
            abort(404, 'Log file not found.');
        }

        return response()->json([
            'filename' => $filename,
            'content' => File::get($path),
        ]);
    }

    public function storeSeed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['nullable', 'string', 'max:190'],
            'brand' => ['required', 'string', 'max:120'],
            'model' => ['required', 'string', 'max:160'],
            'targets' => ['nullable', 'array'],
            'targets.*' => ['required', 'string', 'max:160'],
            'suppliers' => ['required', 'array', 'min:1'],
            'suppliers.*.key' => ['required', 'string', 'max:60'],
            'suppliers.*.urls' => ['required', 'array', 'min:1'],
            'suppliers.*.urls.*' => ['required', 'url', 'max:2048'],
        ]);

        $directory = config('gaulois.seeds_directory');

        if (! $directory) {
            abort(500, 'Gaulois seeds directory is not configured.');
        }

        File::ensureDirectoryExists($directory);

        $baseName = trim((string) Arr::get($data, 'filename', ''));
        if ($baseName === '') {
            $baseName = sprintf('gaulois_seed_%s', now()->format('Ymd_His'));
        }
        $nameWithoutExtension = pathinfo($baseName, PATHINFO_FILENAME);
        $sanitizedName = Str::slug($nameWithoutExtension, '_');
        if ($sanitizedName === '') {
            $sanitizedName = sprintf('gaulois_seed_%s', now()->format('Ymd_His'));
        }
        $filename = $sanitizedName.'.json';
        $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

        $targets = collect(Arr::get($data, 'targets', []))
            ->map(fn ($target) => trim((string) $target))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $suppliers = [];
        foreach (Arr::get($data, 'suppliers', []) as $entry) {
            $key = trim((string) Arr::get($entry, 'key', ''));
            if ($key === '') {
                continue;
            }
            $urls = collect(Arr::get($entry, 'urls', []))
                ->map(fn ($url) => trim((string) $url))
                ->filter()
                ->values()
                ->all();
            if (count($urls) === 0) {
                continue;
            }
            $suppliers[$key] = $urls;
        }

        if (count($suppliers) === 0) {
            abort(422, 'Aucun fournisseur valide fourni.');
        }

        $payload = [
            'brand' => Arr::get($data, 'brand'),
            'model' => Arr::get($data, 'model'),
            'suppliers' => $suppliers,
        ];

        if (count($targets) > 0) {
            $payload['targets'] = $targets;
        }

        File::put(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL
        );

        return response()->json([
            'status' => 'created',
            'filename' => $filename,
            'path' => $path,
            'seed' => $payload,
        ], 201);
    }

    private function validateRunPayload(Request $request): array
    {
        return $request->validate([
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:160'],
            'targets' => ['nullable', 'array'],
            'targets.*' => ['string', 'max:160'],
            'seed_file' => ['nullable', 'string', 'max:190'],
            'dry_run' => ['nullable', 'boolean'],
            'log' => ['nullable', 'boolean'],
            'seed' => ['nullable', 'string', 'max:190'],
        ]);
    }

    /**
     * @param  array  $data
     * @return array{command: array<int, string>, working_directory: ?string, timeout: int}
     */
    private function buildProcessCommand(array $data): array
    {
        $python = config('gaulois.python');
        $scriptPath = config('gaulois.script_path');
        $workingDirectory = config('gaulois.working_directory');
        $timeout = (int) config('gaulois.timeout', 900);
        $processTimeout = $timeout > 0 ? $timeout : null; // 0 ou négatif => pas de limite

        if (! $python || ! $scriptPath) {
            abort(500, 'Gaulois configuration is incomplete.');
        }

        if (! File::exists($scriptPath)) {
            abort(500, 'gaulois.py introuvable. Vérifiez GAULOIS_SCRIPT_PATH.');
        }

        $command = [$python, $scriptPath];

        if ($brand = Arr::get($data, 'brand')) {
            $command[] = '--brand';
            $command[] = $brand;
        }

        if ($model = Arr::get($data, 'model')) {
            $command[] = '--model';
            $command[] = $model;
        }

        if ($targets = Arr::get($data, 'targets')) {
            $normalizedTargets = collect($targets)
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->implode(',');

            if ($normalizedTargets !== '') {
                $command[] = '--targets';
                $command[] = $normalizedTargets;
            }
        }

        $seedFile = Arr::get($data, 'seed_file') ?? Arr::get($data, 'seed');

        if ($seedFile) {
            $seedPath = $this->resolveSeedPath($seedFile);

            if (! \File::exists($seedPath)) {
                abort(422, 'Seed file introuvable.');
            }

            $command[] = '--seeds';
            $command[] = $seedPath;
        }

        // Ajout du job_id si fourni (multi-instance, logs dédiés)
        if ($jobId = Arr::get($data, 'job_id')) {
            $command[] = '--job-id';
            $command[] = $jobId;
        }

        if (Arr::get($data, 'dry_run', true)) {
            $command[] = '--dry-run';
        }

        if (Arr::get($data, 'log', true)) {
            $command[] = '--log';
        }

        return [
            'command' => $command,
            'working_directory' => $workingDirectory,
            'timeout' => $processTimeout,
        ];
    }

    public function run(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $this->validateRunPayload($request);

        // Compat : mapper "seed" → "seed_file" si besoin
        if (! isset($data['seed_file']) && isset($data['seed'])) {
            $data['seed_file'] = $data['seed'];
        }

        // On part du nom du seed sans extension
        $seedFile = $data['seed_file'] ?? $data['seed'] ?? null;
        $seedBase = $seedFile
            ? pathinfo($seedFile, PATHINFO_FILENAME) // ex: iphone14_seed
            : 'gaulois';

        $seedSlug = Str::slug($seedBase, '_');       // iphone14_seed
        $timestamp = now()->format('Ymd_His');       // 20251111_153012

        // job_id lisible, qui servira de base au nom du log
        $jobId = "{$seedSlug}_{$timestamp}";
        $data['job_id'] = $jobId;


        $processConfig = $this->buildProcessCommand($data);
        $timeout = $processConfig['timeout'];

        // Empêcher l'arrêt PHP prématuré pendant l'exécution longue
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            $limit = $timeout === null ? 0 : max($timeout + 60, 0); // 0 => illimité
            @set_time_limit($limit);
        }

        $start = microtime(true);

        $process = new Process(
            $processConfig['command'],
            $processConfig['working_directory'] ?: null,
            null,
            null,
            $timeout
        );

        $output = '';
        $errorOutput = '';

        try {
            $process->run(function (string $type, string $buffer) use (&$output, &$errorOutput): void {
                if ($type === Process::OUT) {
                    $output .= $buffer;
                } else {
                    $errorOutput .= $buffer;
                }
            });
        } catch (ProcessFailedException $exception) {
            $errorOutput .= PHP_EOL.$exception->getMessage();
        }

        $duration = microtime(true) - $start;
        $exitCode = $process->getExitCode();
        $success = $process->isSuccessful();

        $logHint = $this->buildLogFilename($jobId);

        return response()->json([
            'status' => $success ? 'done' : 'failed',
            'job_id' => $jobId,
            'seed' => Arr::get($data, 'seed_file'),
            'exit_code' => $exitCode,
            'duration_seconds' => round($duration, 2),
            'command' => $process->getCommandLine(),
            'output' => $this->splitOutput($output),
            'error_output' => $this->splitOutput($errorOutput),
            'log_hint' => $logHint,
        ], $success ? 200 : 422);
    }


    public function stream(Request $request): StreamedResponse
    {
        $data = $this->validateRunPayload($request);
        $processConfig = $this->buildProcessCommand($data);
        $logHint = $this->currentLogFilename();
        $shouldLog = (bool) Arr::get($data, 'log', true);
        $timeout = $processConfig['timeout'];

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            $limit = $timeout === null ? 0 : max($timeout + 60, 0);
            @set_time_limit($limit);
        }

        $response = new StreamedResponse(function () use ($processConfig, $logHint, $shouldLog): void {
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ob_implicit_flush(true);

            $process = new Process(
                $processConfig['command'],
                $processConfig['working_directory'] ?: null,
                null,
                null,
                $processConfig['timeout']
            );

            $emit = static function (string $type, mixed $payload): void {
                echo json_encode(['type' => $type, 'data' => $payload], JSON_UNESCAPED_UNICODE)."\n";
                @ob_flush();
                @flush();
            };

            $commandLine = $process->getCommandLine();
            $emit('meta', [
                'command' => $commandLine,
                'log_hint' => $shouldLog ? $logHint : null,
            ]);

            $start = microtime(true);

            try {
                $process->start();
            } catch (\Throwable $exception) {
                $emit('error', ['message' => $exception->getMessage()]);
                $emit('exit', [
                    'code' => 255,
                    'duration_seconds' => round(microtime(true) - $start, 2),
                ]);
                return;
            }

            while ($process->isRunning()) {
                $stdout = $process->getIncrementalOutput();
                if ($stdout !== '') {
                    $emit('stdout', $stdout);
                }
                $stderr = $process->getIncrementalErrorOutput();
                if ($stderr !== '') {
                    $emit('stderr', $stderr);
                }
                usleep(150000);
            }

            $remainingStdout = $process->getIncrementalOutput();
            if ($remainingStdout !== '') {
                $emit('stdout', $remainingStdout);
            }
            $remainingStderr = $process->getIncrementalErrorOutput();
            if ($remainingStderr !== '') {
                $emit('stderr', $remainingStderr);
            }

            $emit('exit', [
                'code' => $process->getExitCode(),
                'duration_seconds' => round(microtime(true) - $start, 2),
            ]);
        });

        $response->headers->set('Content-Type', 'application/x-ndjson; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function collectSeedSummaries(): Collection
    {
        $seedsDirectory = config('gaulois.seeds_directory');

        if (! $seedsDirectory || ! File::isDirectory($seedsDirectory)) {
            return collect();
        }

        return collect(File::files($seedsDirectory))
            ->filter(fn ($file) => Str::endsWith($file->getFilename(), '.json'))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values()
            ->map(function ($file) {
                $summary = $this->parseSeedSummary($file->getPathname());

                return array_merge($summary, [
                    'filename' => $file->getFilename(),
                ]);
            });
    }

    private function parseSeedSummary(string $path): array
    {
        try {
            $content = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return [
                'filename' => basename($path),
                'brands' => [],
                'models' => [],
                'targets' => [],
                'suppliers' => [],
                'error' => $exception->getMessage(),
            ];
        }

        $entries = is_array($content) ? (array) $content : [];
        if (Arr::isAssoc($entries)) {
            $entries = [$entries];
        }

        $brands = collect($entries)->pluck('brand')->filter()->unique()->values();
        $models = collect($entries)->pluck('model')->filter()->unique()->values();
        $targets = collect($entries)
            ->map(fn ($entry) => Arr::get($entry, 'targets', []))
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        $suppliers = [];
        foreach ($entries as $entry) {
            $current = Arr::get($entry, 'suppliers', []);
            if (! is_array($current)) {
                continue;
            }
            foreach ($current as $key => $urls) {
                if (! is_array($urls)) {
                    continue;
                }
                $suppliers[$key] = array_values(array_unique(array_merge(
                    $suppliers[$key] ?? [],
                    array_map(static fn ($url) => (string) $url, array_filter($urls))
                )));
            }
        }

        $suppliersList = collect($suppliers)
            ->map(fn ($urls, $key) => [
                'key' => (string) $key,
                'urls' => collect($urls)
                    ->map(static fn ($url) => trim((string) $url))
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->filter(fn ($item) => count($item['urls']) > 0)
            ->values();

        return [
            'filename' => basename($path),
            'brands' => $brands,
            'models' => $models,
            'targets' => $targets,
            'suppliers' => $suppliersList,
        ];
    }

    private function resolveSeedPath(string $filename): string
    {
        $sanitized = basename($filename);
        $directory = config('gaulois.seeds_directory');

        if (! $directory) {
            return $sanitized;
        }

        return rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sanitized;
    }

    private function resolveLogPath(string $filename): string
    {
        $sanitized = basename($filename);
        $directory = config('gaulois.logs_directory');

        if (! $directory) {
            return $sanitized;
        }

        return rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sanitized;
    }

    private function splitOutput(string $value): array
    {
        return collect(preg_split('/\r\n|\n|\r/', trim($value)))
            ->filter()
            ->values()
            ->toArray();
    }

    private function isLogComplete(string $path): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        try {
            $content = File::get($path);
        } catch (\Throwable $exception) {
            return false;
        }

        $tail = $content;
        $maxBytes = 8192;
        if (strlen($content) > $maxBytes) {
            $tail = substr($content, -$maxBytes);
        }

        return stripos($tail, 'capitulatif global') !== false;
    }

    private function currentLogFilename(): string
    {
        return sprintf('gaulois_%s.log', now()->format('Ymd'));
    }
    private function buildLogFilename(?string $jobId): string
    {
        if ($jobId) {
            // Le fichier sera créé par gaulois.py sous ce nom
            return sprintf('log_%s.log', $jobId);
        }

        // Fallback legacy
        return $this->currentLogFilename();
    }
}
