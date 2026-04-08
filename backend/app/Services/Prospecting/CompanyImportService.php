<?php

namespace App\Services\Prospecting;

use App\Models\HelixUser;
use App\Models\Prospecting\Company;
use App\Models\Prospecting\ImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CompanyImportService
{
    public function __construct(
        private readonly SpreadsheetWorkbookService $spreadsheetWorkbookService,
        private readonly CompanyQueryService $companyQueryService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function importFromPath(string $filePath, array $options = [], ?HelixUser $actor = null): ImportJob
    {
        $job = ImportJob::query()->create([
            'source' => $options['source'] ?? null,
            'status' => 'running',
            'file_path' => $filePath,
            'segment' => $options['segment'] ?? null,
            'payload' => $options,
            'created_by' => $actor?->id,
            'started_at' => now(),
        ]);

        try {
            $workbook = $this->spreadsheetWorkbookService->readRows($filePath, $options['sheet_name'] ?? null);
            $headers = $workbook['headers'];
            $rows = $workbook['rows'];

            $job->rows_total = count($rows);
            $job->save();

            $normalizedHeaders = [];
            foreach ($headers as $header) {
                $normalizedHeaders[$header] = $this->companyQueryService->normalizeHeader($header);
            }

            $stats = [
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'deduplicated' => 0,
                'rejected' => 0,
                'errors' => [],
            ];

            foreach ($rows as $row) {
                try {
                    $payload = $this->normalizeImportRow($row, $normalizedHeaders, $options);

                    if (($payload['name'] ?? null) === null) {
                        throw new \InvalidArgumentException('Nom entreprise manquant.');
                    }

                    DB::transaction(function () use ($payload, &$stats): void {
                        $existing = $this->findExistingCompany($payload);

                        if ($existing instanceof Company) {
                            $stats['deduplicated']++;
                            $dirty = false;

                            foreach ($payload as $key => $value) {
                                if ($value === null || $value === '') {
                                    continue;
                                }

                                $current = $existing->{$key};
                                if ($current === null || $current === '' || in_array($key, ['lat', 'lng', 'google_place_id', 'relevance_score'], true)) {
                                    $existing->{$key} = $value;
                                    $dirty = true;
                                }
                            }

                            if ($dirty) {
                                $existing->version = (int) $existing->version + 1;
                                $existing->save();
                                $stats['updated']++;
                            }

                            return;
                        }

                        Company::query()->create(array_merge($payload, [
                            'company_id' => (string) Str::ulid(),
                            'excel_row_id' => null,
                            'contact_status' => 'non_contacte',
                            'version' => 1,
                        ]));

                        $stats['created']++;
                    });
                } catch (Throwable $throwable) {
                    $stats['rejected']++;
                    if (count($stats['errors']) < 25) {
                        $stats['errors'][] = $throwable->getMessage();
                    }
                } finally {
                    $stats['processed']++;
                }
            }

            $job->fill([
                'status' => 'success',
                'rows_processed' => $stats['processed'],
                'rows_created' => $stats['created'],
                'rows_updated' => $stats['updated'],
                'rows_deduplicated' => $stats['deduplicated'],
                'rows_rejected' => $stats['rejected'],
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
     * @param  array<string, string|null>  $row
     * @param  array<string, string>  $normalizedHeaders
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeImportRow(array $row, array $normalizedHeaders, array $options): array
    {
        $canonical = [];
        foreach ($row as $header => $value) {
            $normalizedHeader = $normalizedHeaders[$header] ?? $this->companyQueryService->normalizeHeader($header);
            $canonical[$this->mapHeaderAlias($normalizedHeader)] = $value;
        }

        $name = $this->nullableString($canonical['name'] ?? null);
        $email = $this->nullableString($canonical['email'] ?? null);
        $phone = $this->nullableString($canonical['phone'] ?? null);
        $website = $this->nullableString($canonical['website'] ?? null);
        $lat = $this->nullableFloat($canonical['lat'] ?? null);
        $lng = $this->nullableFloat($canonical['lng'] ?? null);

        return [
            'name' => $name,
            'siren' => $this->nullableDigits($canonical['siren'] ?? null),
            'siret' => $this->nullableDigits($canonical['siret'] ?? null),
            'segment' => $this->nullableString($options['segment'] ?? $canonical['segment'] ?? null),
            'source' => $this->nullableString($options['source'] ?? $canonical['source'] ?? null),
            'website' => $website,
            'email' => $email,
            'phone' => $phone,
            'address' => $this->nullableString($canonical['address'] ?? null),
            'postal_code' => $this->nullableString($canonical['postal_code'] ?? null),
            'city' => $this->nullableString($canonical['city'] ?? null),
            'country' => $this->nullableString($canonical['country'] ?? null) ?? 'France',
            'lat' => $lat,
            'lng' => $lng,
            'google_place_id' => $this->nullableString($canonical['google_place_id'] ?? null),
            'relevance_score' => $this->resolveRelevanceScore($canonical, $email, $phone, $website, $lat, $lng),
            'notes' => $this->nullableString($canonical['notes'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findExistingCompany(array $payload): ?Company
    {
        if (($payload['siret'] ?? null) !== null) {
            $company = Company::query()->where('siret', $payload['siret'])->first();
            if ($company instanceof Company) {
                return $company;
            }
        }

        if (($payload['siren'] ?? null) !== null && ($payload['name'] ?? null) !== null && ($payload['postal_code'] ?? null) !== null) {
            $company = Company::query()
                ->where('siren', $payload['siren'])
                ->where('postal_code', $payload['postal_code'])
                ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $payload['name'])])
                ->first();

            if ($company instanceof Company) {
                return $company;
            }
        }

        if (($payload['name'] ?? null) !== null && ($payload['address'] ?? null) !== null) {
            return Company::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $payload['name'])])
                ->whereRaw('LOWER(address) = ?', [mb_strtolower((string) $payload['address'])])
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $canonical
     */
    private function resolveRelevanceScore(array $canonical, ?string $email, ?string $phone, ?string $website, ?float $lat, ?float $lng): int
    {
        if (($canonical['relevance_score'] ?? null) !== null && is_numeric($canonical['relevance_score'])) {
            return max(0, min(100, (int) $canonical['relevance_score']));
        }

        $score = 20;
        $score += $email !== null ? 25 : 0;
        $score += $phone !== null ? 25 : 0;
        $score += $website !== null ? 10 : 0;
        $score += ($lat !== null && $lng !== null) ? 10 : 0;
        $score += ($canonical['segment'] ?? null) !== null ? 5 : 0;
        $score += ($canonical['source'] ?? null) !== null ? 5 : 0;

        return max(0, min(100, $score));
    }

    private function mapHeaderAlias(string $normalizedHeader): string
    {
        return match ($normalizedHeader) {
            'company', 'company_name', 'entreprise', 'nom', 'nom_entreprise' => 'name',
            'postal', 'zip', 'zip_code', 'code_postal' => 'postal_code',
            'mail', 'courriel' => 'email',
            'telephone', 'mobile', 'tel' => 'phone',
            'site', 'url', 'site_web' => 'website',
            'adresse' => 'address',
            'ville' => 'city',
            'pays' => 'country',
            default => $normalizedHeader,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
    }

    private function nullableDigits(mixed $value): ?string
    {
        $string = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';

        return $string === '' ? null : $string;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
