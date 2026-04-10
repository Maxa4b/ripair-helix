<?php

namespace App\Services\Prospecting;

use App\Models\HelixUser;
use App\Models\Prospecting\Company;
use App\Models\Prospecting\ImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SireneProspectingImportService
{
    public function __construct(
        private readonly CompanyQueryService $companyQueryService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function importFromSource(string $source, array $options = [], ?HelixUser $actor = null): ImportJob
    {
        $job = ImportJob::query()->create([
            'source' => 'sirene_auto',
            'status' => 'running',
            'file_path' => $source,
            'segment' => null,
            'payload' => $options,
            'created_by' => $actor?->id,
            'started_at' => now(),
        ]);

        [$filePath, $temporaryFile] = $this->prepareSource($source);

        try {
            $stats = [
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'deduplicated' => 0,
                'rejected' => 0,
                'errors' => [],
            ];

            $rowLimit = max(1, (int) ($options['limit'] ?? 10000));
            $minScore = (int) ($options['min_score'] ?? config('prospecting.sirene.min_score', 55));
            $departments = $this->parseList($options['departments'] ?? null);

            foreach ($this->iterateCsvRows($filePath) as $row) {
                if ($stats['processed'] >= $rowLimit) {
                    break;
                }

                try {
                    $payload = $this->mapSireneRow($row, $departments);

                    if ($payload === null || (int) $payload['relevance_score'] < $minScore) {
                        $stats['rejected']++;

                        continue;
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
                                if ($current === null || $current === '' || in_array($key, ['lat', 'lng', 'google_place_id', 'relevance_score', 'notes'], true)) {
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
                } catch (\Throwable $throwable) {
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
                'rows_total' => $stats['processed'],
                'rows_processed' => $stats['processed'],
                'rows_created' => $stats['created'],
                'rows_updated' => $stats['updated'],
                'rows_deduplicated' => $stats['deduplicated'],
                'rows_rejected' => $stats['rejected'],
                'error_payload' => $stats['errors'] === [] ? null : $stats['errors'],
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $throwable) {
            $job->fill([
                'status' => 'failed',
                'error_payload' => ['message' => $throwable->getMessage()],
                'finished_at' => now(),
            ])->save();

            throw $throwable;
        } finally {
            if ($temporaryFile !== null && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }

        return $job->fresh() ?? $job;
    }

    /**
     * @return array{0:string,1:string|null}
     */
    private function prepareSource(string $source): array
    {
        if (! preg_match('/^https?:\/\//i', $source)) {
            return [$source, null];
        }

        $folder = storage_path('app/private/prospecting/imports');
        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $tempFile = $folder . DIRECTORY_SEPARATOR . 'sirene-auto-' . Str::uuid() . '.csv';

        Http::timeout((int) config('prospecting.sirene.http_timeout', 120))
            ->withOptions(['sink' => $tempFile])
            ->get($source)
            ->throw();

        return [$tempFile, $tempFile];
    }

    /**
     * @return \Generator<int, array<string, string|null>>
     */
    private function iterateCsvRows(string $filePath): \Generator
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier source SIRENE.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                return;
            }

            $delimiter = $this->detectDelimiter($firstLine);
            rewind($handle);

            $headers = null;
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($row === [null]) {
                    continue;
                }

                $row = array_map(static function ($value): ?string {
                    if ($value === null) {
                        return null;
                    }

                    $string = trim((string) $value);
                    $string = preg_replace('/^\xEF\xBB\xBF/u', '', $string) ?? $string;

                    return $string === '' ? null : $string;
                }, $row);

                if ($headers === null) {
                    $headers = array_map(
                        fn (?string $header): string => $this->companyQueryService->normalizeHeader((string) ($header ?? '')),
                        $row
                    );

                    continue;
                }

                $assoc = [];
                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }

                    $assoc[$header] = $row[$index] ?? null;
                }

                if ($assoc !== []) {
                    yield $assoc;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  list<string>  $departmentFilter
     * @return array<string, mixed>|null
     */
    private function mapSireneRow(array $row, array $departmentFilter): ?array
    {
        if (! $this->isActiveRow($row)) {
            return null;
        }

        $name = $this->firstNonEmpty($row, [
            'denominationunitelegale',
            'denominationusuelle1unitelegale',
            'denominationusuelle2unitelegale',
            'denominationusuelle3unitelegale',
            'nomusageunitelegale',
            'enseigne1etablissement',
            'enseigne2etablissement',
            'enseigne3etablissement',
        ]);

        if ($name === null) {
            return null;
        }

        $naf = $this->firstNonEmpty($row, [
            'activiteprincipaleetablissement',
            'activiteprincipaleunitelegale',
            'activiteprincipale',
        ]);

        $searchBlob = Str::lower(Str::ascii(implode(' ', array_filter([
            $name,
            $this->firstNonEmpty($row, ['enseigne1etablissement', 'enseigne2etablissement', 'enseigne3etablissement']),
            $this->firstNonEmpty($row, ['denominationusuelle1unitelegale', 'nomusageunitelegale']),
        ], static fn (?string $value): bool => $value !== null && $value !== ''))));

        if ($this->containsAny($searchBlob, config('prospecting.sirene.exclude_keywords', []))) {
            return null;
        }

        $postalCode = $this->firstNonEmpty($row, ['codepostaletablissement']);
        $city = $this->firstNonEmpty($row, ['libellecommuneetablissement', 'communeetablissement', 'libellecommuneetrangereetablissement']);

        if ($postalCode === null || $city === null) {
            return null;
        }

        if (
            $departmentFilter !== []
            && ! in_array(substr($postalCode, 0, 2), $departmentFilter, true)
            && ! in_array(substr($postalCode, 0, 3), $departmentFilter, true)
        ) {
            return null;
        }

        $address = trim(implode(' ', array_filter([
            $this->firstNonEmpty($row, ['complementadresseetablissement']),
            $this->firstNonEmpty($row, ['numerovoieetablissement']),
            $this->firstNonEmpty($row, ['indicerepetitionetablissement']),
            $this->firstNonEmpty($row, ['typevoieetablissement']),
            $this->firstNonEmpty($row, ['libellevoieetablissement']),
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));

        if ($address === '') {
            return null;
        }

        $score = $this->resolveRelevanceScore($naf, $searchBlob, $address);
        if ($score <= 0) {
            return null;
        }

        $lat = $this->nullableFloat($this->firstNonEmpty($row, ['lat', 'latitude']));
        $lng = $this->nullableFloat($this->firstNonEmpty($row, ['lng', 'longitude', 'lon']));
        $segment = $this->resolveSegment($naf, $searchBlob);

        return [
            'name' => $name,
            'siren' => $this->digitsOnly($this->firstNonEmpty($row, ['siren'])),
            'siret' => $this->digitsOnly($this->firstNonEmpty($row, ['siret'])),
            'segment' => $segment,
            'source' => 'sirene_auto',
            'website' => null,
            'email' => null,
            'phone' => null,
            'address' => $address,
            'postal_code' => $postalCode,
            'city' => $city,
            'country' => 'France',
            'lat' => $lat,
            'lng' => $lng,
            'google_place_id' => null,
            'relevance_score' => $score,
            'notes' => trim(implode(' | ', array_filter([
                $naf !== null ? 'APE ' . $naf : null,
                $segment !== null ? 'segment ' . $segment : null,
                $lat !== null && $lng !== null ? 'coordonnees source' : null,
            ], static fn (?string $value): bool => $value !== null))),
        ];
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function isActiveRow(array $row): bool
    {
        $etablissement = $this->firstNonEmpty($row, ['etatadministratifetablissement']);
        $uniteLegale = $this->firstNonEmpty($row, ['etatadministratifunitelegale']);

        if ($etablissement !== null && Str::upper($etablissement) !== 'A') {
            return false;
        }

        if ($uniteLegale !== null && Str::upper($uniteLegale) !== 'A') {
            return false;
        }

        return true;
    }

    private function resolveSegment(?string $naf, string $searchBlob): string
    {
        if ($this->containsAny($searchBlob, ['iot', 'embarque', 'hardware', 'capteur'])) {
            return 'iot_hardware';
        }

        if ($this->containsAny($searchBlob, ['prototype', 'prototypage'])) {
            return 'prototypage_electronique';
        }

        if ($this->containsAny($searchBlob, ['maintenance', 'sav', 'retrofit'])) {
            return 'maintenance_electronique';
        }

        if ($this->containsAny($searchBlob, ['assemblage', 'cablage', 'cms', 'tht', 'pcba'])) {
            return 'assemblage_electronique';
        }

        if ($naf !== null && (str_starts_with($naf, '7112') || str_starts_with($naf, '6201') || str_starts_with($naf, '6202'))) {
            return 'bureau_etude_electronique';
        }

        return 'electronique_industrielle';
    }

    private function resolveRelevanceScore(?string $naf, string $searchBlob, string $address): int
    {
        $score = 0;

        if ($naf !== null && $this->matchesNafPrefix($naf, config('prospecting.sirene.naf_prefixes', []))) {
            $score += 45;
        }

        if ($this->containsAny($searchBlob, config('prospecting.sirene.include_keywords', []))) {
            $score += 30;
        }

        if ($this->containsAny($searchBlob, ['bureau', 'etude', 'etudes', 'engineering', 'ingenierie'])) {
            $score += 10;
        }

        if ($this->containsAny($searchBlob, ['electronique', 'embarque', 'hardware', 'pcb', 'carte'])) {
            $score += 15;
        }

        if ($address !== '') {
            $score += 5;
        }

        if ($this->containsAny($searchBlob, config('prospecting.sirene.exclude_keywords', []))) {
            $score -= 80;
        }

        return max(0, min(100, $score));
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matchesNafPrefix(string $naf, array $prefixes): bool
    {
        $normalized = strtoupper(str_replace(['.', ' '], '', $naf));
        foreach ($prefixes as $prefix) {
            $candidate = strtoupper(str_replace(['.', ' '], '', (string) $prefix));
            if ($candidate !== '' && str_starts_with($normalized, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $normalizedNeedle = Str::lower(Str::ascii((string) $needle));
            if ($normalizedNeedle !== '' && str_contains($haystack, $normalizedNeedle)) {
                return true;
            }
        }

        return false;
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
     * @param  array<string, string|null>  $row
     * @param  list<string>  $keys
     */
    private function firstNonEmpty(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function digitsOnly(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    private function nullableFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(',', '.', trim($value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @return list<string>
     */
    private function parseList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $raw = trim((string) ($value ?? ''));
            if ($raw === '') {
                return [];
            }

            $items = explode(',', $raw);
        }

        return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $items), static fn (string $item): bool => $item !== ''));
    }
}
