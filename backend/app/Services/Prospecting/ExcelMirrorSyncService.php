<?php

namespace App\Services\Prospecting;

use App\Models\HelixUser;
use App\Models\Prospecting\Company;
use App\Models\Prospecting\ExcelSyncJob;
use Illuminate\Support\Facades\File;
use Throwable;

class ExcelMirrorSyncService
{
    public function __construct(
        private readonly SpreadsheetWorkbookService $spreadsheetWorkbookService,
        private readonly CompanyMutationService $companyMutationService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function sync(array $options = [], ?HelixUser $actor = null): ExcelSyncJob
    {
        $mode = (string) ($options['mode'] ?? 'resync');
        $sheetName = (string) ($options['sheet_name'] ?? env('PROSPECTING_EXCEL_SHEET', 'Prospection'));
        $filePath = (string) ($options['file_path'] ?? env('PROSPECTING_EXCEL_PATH', storage_path('app/private/prospecting/prospecting-mirror.xlsx')));

        $job = ExcelSyncJob::query()->create([
            'mode' => $mode,
            'status' => 'running',
            'file_path' => $filePath,
            'sheet_name' => $sheetName,
            'payload' => $options,
            'created_by' => $actor?->id,
            'started_at' => now(),
        ]);

        try {
            $stats = [
                'rows_total' => 0,
                'rows_processed' => 0,
                'rows_updated' => 0,
                'rows_skipped' => 0,
                'rows_failed' => 0,
                'errors' => [],
            ];

            if (in_array($mode, ['import', 'resync'], true)) {
                $stats = $this->mergeStats($stats, $this->importMirror($filePath, $sheetName, $actor));
            }

            if (in_array($mode, ['export', 'resync'], true)) {
                $stats = $this->mergeStats($stats, $this->exportMirror($filePath, $sheetName));
            }

            $job->fill([
                'status' => 'success',
                'rows_total' => $stats['rows_total'],
                'rows_processed' => $stats['rows_processed'],
                'rows_updated' => $stats['rows_updated'],
                'rows_skipped' => $stats['rows_skipped'],
                'rows_failed' => $stats['rows_failed'],
                'error_payload' => $stats['errors'] === [] ? null : $stats['errors'],
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $throwable) {
            $job->fill([
                'status' => 'failed',
                'error_payload' => ['message' => $throwable->getMessage()],
                'finished_at' => now(),
            ])->save();

            throw $throwable;
        }

        return $job->fresh() ?? $job;
    }

    /**
     * @return array{rows_total:int, rows_processed:int, rows_updated:int, rows_skipped:int, rows_failed:int, errors:list<string>}
     */
    private function exportMirror(string $filePath, string $sheetName): array
    {
        File::ensureDirectoryExists(dirname($filePath));

        Company::query()
            ->whereNull('excel_row_id')
            ->orderBy('id')
            ->chunkById(250, function ($companies): void {
                foreach ($companies as $company) {
                    $company->excel_row_id = $company->company_id;
                    $company->save();
                }
            });

        $headers = [
            'excel_row_id',
            'company_id',
            'name',
            'siren',
            'siret',
            'segment',
            'source',
            'website',
            'email',
            'phone',
            'address',
            'postal_code',
            'city',
            'country',
            'lat',
            'lng',
            'google_place_id',
            'relevance_score',
            'contact_status',
            'contact_owner',
            'first_contact_at',
            'last_contact_at',
            'notes',
            'version',
            'updated_at',
        ];

        $rows = Company::query()
            ->orderBy('name')
            ->get()
            ->map(function (Company $company) use ($headers): array {
                $row = [];

                foreach ($headers as $header) {
                    $value = $company->{$header} ?? null;
                    if ($value instanceof \DateTimeInterface) {
                        $row[$header] = $value->format(DATE_ATOM);
                    } else {
                        $row[$header] = $value;
                    }
                }

                return $row;
            })
            ->all();

        $this->spreadsheetWorkbookService->writeRows($filePath, $headers, $rows, $sheetName);

        $count = count($rows);

        return [
            'rows_total' => $count,
            'rows_processed' => $count,
            'rows_updated' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 0,
            'errors' => [],
        ];
    }

    /**
     * @return array{rows_total:int, rows_processed:int, rows_updated:int, rows_skipped:int, rows_failed:int, errors:list<string>}
     */
    private function importMirror(string $filePath, string $sheetName, ?HelixUser $actor): array
    {
        if (! is_file($filePath)) {
            return [
                'rows_total' => 0,
                'rows_processed' => 0,
                'rows_updated' => 0,
                'rows_skipped' => 0,
                'rows_failed' => 0,
                'errors' => [],
            ];
        }

        $workbook = $this->spreadsheetWorkbookService->readRows($filePath, $sheetName);
        $stats = [
            'rows_total' => count($workbook['rows']),
            'rows_processed' => 0,
            'rows_updated' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 0,
            'errors' => [],
        ];

        foreach ($workbook['rows'] as $row) {
            try {
                $company = $this->resolveCompanyFromMirrorRow($row);
                if (! $company instanceof Company) {
                    $stats['rows_skipped']++;
                    continue;
                }

                $payload = [];

                if (($status = $this->normalizeStatus($row['contact_status'] ?? $row['status'] ?? null)) !== null) {
                    $payload['contact_status'] = $status;
                }

                if (($owner = $this->nullableString($row['contact_owner'] ?? $row['owner'] ?? null)) !== null) {
                    $payload['contact_owner'] = $owner;
                }

                if (($notes = $this->nullableString($row['notes'] ?? $row['note'] ?? null)) !== null) {
                    $payload['notes'] = $notes;
                }

                if (($email = $this->nullableString($row['email'] ?? null)) !== null) {
                    $payload['email'] = $email;
                }

                if (($phone = $this->nullableString($row['phone'] ?? null)) !== null) {
                    $payload['phone'] = $phone;
                }

                if ($payload === []) {
                    $stats['rows_skipped']++;
                    continue;
                }

                $expectedVersion = is_numeric($row['version'] ?? null) ? (int) $row['version'] : null;
                $this->companyMutationService->mutate(
                    $company,
                    $payload,
                    $actor,
                    'excel_sync',
                    'Mise a jour miroir Excel',
                    $expectedVersion
                );

                $stats['rows_updated']++;
            } catch (Throwable $throwable) {
                $stats['rows_failed']++;
                if (count($stats['errors']) < 25) {
                    $stats['errors'][] = $throwable->getMessage();
                }
            } finally {
                $stats['rows_processed']++;
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function resolveCompanyFromMirrorRow(array $row): ?Company
    {
        if (($companyId = $this->nullableString($row['company_id'] ?? null)) !== null) {
            $company = Company::query()->where('company_id', $companyId)->first();
            if ($company instanceof Company) {
                return $company;
            }
        }

        if (($excelRowId = $this->nullableString($row['excel_row_id'] ?? null)) !== null) {
            $company = Company::query()->where('excel_row_id', $excelRowId)->first();
            if ($company instanceof Company) {
                return $company;
            }
        }

        if (($siret = $this->nullableString($row['siret'] ?? null)) !== null) {
            return Company::query()->where('siret', preg_replace('/\D+/', '', $siret) ?: $siret)->first();
        }

        return null;
    }

    /**
     * @param  array{rows_total:int, rows_processed:int, rows_updated:int, rows_skipped:int, rows_failed:int, errors:list<string>}  $base
     * @param  array{rows_total:int, rows_processed:int, rows_updated:int, rows_skipped:int, rows_failed:int, errors:list<string>}  $incoming
     * @return array{rows_total:int, rows_processed:int, rows_updated:int, rows_skipped:int, rows_failed:int, errors:list<string>}
     */
    private function mergeStats(array $base, array $incoming): array
    {
        return [
            'rows_total' => max($base['rows_total'], $incoming['rows_total']),
            'rows_processed' => $base['rows_processed'] + $incoming['rows_processed'],
            'rows_updated' => $base['rows_updated'] + $incoming['rows_updated'],
            'rows_skipped' => $base['rows_skipped'] + $incoming['rows_skipped'],
            'rows_failed' => $base['rows_failed'] + $incoming['rows_failed'],
            'errors' => array_slice(array_merge($base['errors'], $incoming['errors']), 0, 25),
        ];
    }

    private function normalizeStatus(mixed $value): ?string
    {
        $status = $this->nullableString($value);

        return match ($status) {
            'non_contacte', 'non_contacte_', 'non_contacte__', 'non_contacted', 'non_contacte_fr' => 'non_contacte',
            'non_contacté', 'non_contacté_fr', 'non_contacté', 'non contacte' => 'non_contacte',
            'en_cours_de_contact', 'en_cours', 'en cours', 'in_progress' => 'en_cours_de_contact',
            'contacte', 'contacte_', 'contacté', 'contacté', 'done' => 'contacte',
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
    }
}
