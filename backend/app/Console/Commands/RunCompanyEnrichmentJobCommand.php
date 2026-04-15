<?php

namespace App\Console\Commands;

use App\Services\CompanyEnrichment\CompanyEnrichmentFilesystemService;
use App\Services\CompanyEnrichment\CompanyEnrichmentJobStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class RunCompanyEnrichmentJobCommand extends Command
{
    protected $signature = 'company-enrichment:run {jobId : Identifiant du job company enrichment}';

    protected $description = 'Execute un job Python company_enrichment en arriere-plan et persiste son etat.';

    public function handle(
        CompanyEnrichmentJobStore $jobStore,
        CompanyEnrichmentFilesystemService $filesystem,
    ): int {
        $jobId = (string) $this->argument('jobId');

        try {
            $job = $jobStore->get($jobId);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $job = $jobStore->update($jobId, function (array $current): array {
            $current['status'] = 'running';
            $current['runner_pid'] = getmypid() ?: null;
            $current['snapshot']['status'] = 'running';
            $current['snapshot']['startedAt'] = $current['snapshot']['startedAt'] ?? $this->timestamp();

            return $current;
        });

        $workingDirectory = $filesystem->pipelineRoot();
        $command = $this->buildPythonCommand($job);
        $logPath = (string) ($job['log_path'] ?? '');
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, '');

        $process = new Process($command, $workingDirectory, [
            'PYTHONUNBUFFERED' => '1',
        ], null, null);
        $process->start();

        $jobStore->update($jobId, function (array $current) use ($process): array {
            $current['process_pid'] = $process->getPid();

            return $current;
        });

        try {
            $buffer = '';

            while ($process->isRunning()) {
                $buffer .= $process->getIncrementalOutput();
                $buffer .= $process->getIncrementalErrorOutput();
                $buffer = $this->flushBufferToLogAndSnapshot($buffer, $logPath, $jobStore, $jobId);

                if ($jobStore->shouldCancel($jobId)) {
                    $process->stop(2);
                    $jobStore->update($jobId, function (array $current): array {
                        $current['status'] = 'cancelled';
                        $current['snapshot']['status'] = 'cancelled';
                        $current['snapshot']['completedAt'] = $this->timestamp();

                        return $current;
                    });

                    return self::SUCCESS;
                }

                usleep(250000);
            }

            $buffer .= $process->getIncrementalOutput();
            $buffer .= $process->getIncrementalErrorOutput();
            $this->flushBufferToLogAndSnapshot($buffer, $logPath, $jobStore, $jobId);

            $exitCode = $process->getExitCode() ?? 1;
            if ($exitCode !== 0) {
                $jobStore->update($jobId, function (array $current) use ($exitCode): array {
                    $current['status'] = 'error';
                    $current['snapshot']['status'] = 'error';
                    $current['snapshot']['error'] = 'Le pipeline Python a echoue avec le code ' . $exitCode . '.';
                    $current['snapshot']['completedAt'] = $this->timestamp();

                    return $current;
                });

                return self::FAILURE;
            }

            $jobStore->update($jobId, function (array $current) use ($filesystem): array {
                $current['status'] = 'completed';
                $current['snapshot']['status'] = 'completed';
                $current['snapshot']['progress'] = 1;
                $current['snapshot']['currentPhase'] = null;
                $current['snapshot']['completedAt'] = $this->timestamp();
                $current['snapshot']['phases'] = collect($current['snapshot']['phases'] ?? [])
                    ->map(function (array $phase): array {
                        if (($phase['status'] ?? null) !== 'completed') {
                            $phase['status'] = 'completed';
                            $phase['completedAt'] = $this->timestamp();
                        }

                        return $phase;
                    })
                    ->all();
                $current['snapshot']['artifacts'] = $filesystem->listArtifacts((string) ($current['output_directory'] ?? ''));

                return $current;
            });

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $jobStore->update($jobId, function (array $current) use ($throwable): array {
                $current['status'] = 'error';
                $current['snapshot']['status'] = 'error';
                $current['snapshot']['error'] = $throwable->getMessage();
                $current['snapshot']['completedAt'] = $this->timestamp();

                return $current;
            });

            $this->error($throwable->getMessage());
            return self::FAILURE;
        }
    }

    private function buildPythonCommand(array $job): array
    {
        $pythonBinary = (string) config('company_enrichment.python_binary', 'python');
        $mode = (string) ($job['mode'] ?? 'run-all');
        $input = (string) ($job['input_absolute_path'] ?? '');
        $configPath = (string) ($job['config_path'] ?? '');
        $outputDirectory = (string) ($job['output_directory'] ?? '');

        return match ($mode) {
            'ingest' => [$pythonBinary, '-m', 'app', 'ingest', '--input', $input, '--output', $outputDirectory . DIRECTORY_SEPARATOR . 'targets.parquet', '--config', $configPath],
            'resolve-domains' => [$pythonBinary, '-m', 'app', 'resolve-domains', '--input', $outputDirectory . DIRECTORY_SEPARATOR . 'targets.parquet', '--output', $outputDirectory . DIRECTORY_SEPARATOR . 'domain_candidates.parquet', '--config', $configPath],
            'crawl' => [$pythonBinary, '-m', 'app', 'crawl', '--input', $outputDirectory . DIRECTORY_SEPARATOR . 'domain_candidates.parquet', '--output', $outputDirectory . DIRECTORY_SEPARATOR . 'crawl_results.parquet', '--config', $configPath],
            'score-emails' => [$pythonBinary, '-m', 'app', 'score-emails', '--input', $outputDirectory . DIRECTORY_SEPARATOR . 'crawl_results.parquet', '--output', $outputDirectory . DIRECTORY_SEPARATOR . 'email_candidates.parquet', '--config', $configPath],
            'export-final' => [$pythonBinary, '-m', 'app', 'export-final', '--targets', $outputDirectory . DIRECTORY_SEPARATOR . 'targets.parquet', '--domains', $outputDirectory . DIRECTORY_SEPARATOR . 'domain_candidates.parquet', '--emails', $outputDirectory . DIRECTORY_SEPARATOR . 'email_candidates.parquet', '--output', $outputDirectory . DIRECTORY_SEPARATOR . 'final_contacts.csv', '--config', $configPath],
            default => [$pythonBinary, '-m', 'app', 'run-all', '--input', $input, '--output-dir', $outputDirectory, '--config', $configPath],
        };
    }

    private function flushBufferToLogAndSnapshot(
        string $buffer,
        string $logPath,
        CompanyEnrichmentJobStore $jobStore,
        string $jobId,
    ): string {
        if ($buffer === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $buffer) ?: [];
        $remainder = '';
        if ($buffer !== '' && ! preg_match('/\r\n|\r|\n$/', $buffer)) {
            $remainder = (string) array_pop($lines);
        }

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            File::append($logPath, $line . PHP_EOL);
            $this->applyLogLineToSnapshot($jobStore, $jobId, $line);
        }

        return $remainder;
    }

    private function applyLogLineToSnapshot(CompanyEnrichmentJobStore $jobStore, string $jobId, string $line): void
    {
        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            return;
        }

        $message = (string) ($decoded['message'] ?? '');
        $phase = (string) ($decoded['phase'] ?? '');
        if ($phase === '') {
            return;
        }

        $jobStore->update($jobId, function (array $current) use ($message, $phase): array {
            $phases = collect($current['snapshot']['phases'] ?? [])
                ->map(function (array $phaseRow) use ($message, $phase): array {
                    if (($phaseRow['key'] ?? null) !== $phase) {
                        return $phaseRow;
                    }

                    if ($message === 'phase_started') {
                        $phaseRow['status'] = 'running';
                        $phaseRow['startedAt'] = $phaseRow['startedAt'] ?? $this->timestamp();
                    }

                    if ($message === 'phase_finished') {
                        $phaseRow['status'] = 'completed';
                        $phaseRow['completedAt'] = $this->timestamp();
                    }

                    return $phaseRow;
                });

            $completed = $phases->where('status', 'completed')->count();
            $total = max(1, $phases->count());
            $current['snapshot']['phases'] = $phases->all();
            $current['snapshot']['progress'] = min(0.99, $completed / $total);
            $current['snapshot']['currentPhase'] = $message === 'phase_started' ? $phase : ($message === 'phase_finished' ? null : ($current['snapshot']['currentPhase'] ?? null));

            return $current;
        });
    }

    private function timestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
