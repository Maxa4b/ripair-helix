<?php

namespace App\Services\CompanyEnrichment;

use App\Models\Prospecting\Company;
use Illuminate\Support\Facades\File;

class CompanyEnrichmentSeedService
{
    private const REJECTED_HOSTS = [
        'facebook.com',
        'instagram.com',
        'linkedin.com',
        'x.com',
        'twitter.com',
        'youtube.com',
        'tiktok.com',
        'pagesjaunes.fr',
        'societe.com',
        'pappers.fr',
        'manageo.fr',
        'infogreffe.fr',
        'google.com',
        'google.fr',
        'goo.gl',
        'g.page',
    ];

    public function __construct(
        private readonly CompanyEnrichmentFilesystemService $filesystem,
    ) {
    }

    public function generateFromProspectingCompanies(): array
    {
        $output = $this->filesystem->generatedSeedOutput();
        File::ensureDirectoryExists(dirname($output['absolute_path']));

        $handle = fopen($output['absolute_path'], 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de creer le fichier domain seed.');
        }

        $generatedAt = now()->toIso8601String();
        $header = [
            'siren',
            'source_url',
            'domain',
            'source_type',
            'matched_name',
            'matched_phone',
            'matched_address',
            'company_id',
            'city',
            'relevance_score',
            'generated_at',
        ];

        fputcsv($handle, $header);

        $rowsWritten = 0;
        $considered = 0;
        $uniqueCompanies = [];
        $seenPairs = [];

        foreach ($this->seedableCompanies() as $company) {
            $considered++;
            $normalizedUrl = $this->normalizeWebsite((string) $company->website);
            if ($normalizedUrl === null) {
                continue;
            }

            $domain = $this->extractDomain($normalizedUrl);
            if ($domain === null || $this->isRejectedDomain($domain)) {
                continue;
            }

            $siren = $this->normalizeSiren($company->siren);
            if ($siren === null) {
                continue;
            }

            $pairKey = $siren . '|' . $domain;
            if (isset($seenPairs[$pairKey])) {
                continue;
            }

            $seenPairs[$pairKey] = true;
            $uniqueCompanies[$siren] = true;

            fputcsv($handle, [
                $siren,
                $normalizedUrl,
                $domain,
                $company->google_place_id ? 'prospecting_google_places' : 'prospecting_company',
                $company->name,
                $company->phone,
                $company->address,
                (string) $company->company_id,
                $company->city,
                (string) ($company->relevance_score ?? 0),
                $generatedAt,
            ]);

            $rowsWritten++;
        }

        fclose($handle);

        $file = $this->filesystem->inputFile($output['relative_path']);

        return [
            'file' => [
                'name' => $file['name'],
                'path' => $file['relative_path'],
                'size' => $file['size'],
                'modified_at' => $file['modified_at'],
            ],
            'rows_written' => $rowsWritten,
            'companies_considered' => $considered,
            'unique_sirens' => count($uniqueCompanies),
            'rejected_domains' => array_values(self::REJECTED_HOSTS),
        ];
    }

    private function seedableCompanies(): \Generator
    {
        $query = Company::query()
            ->select([
                'company_id',
                'name',
                'siren',
                'website',
                'phone',
                'address',
                'city',
                'google_place_id',
                'relevance_score',
            ])
            ->whereNotNull('siren')
            ->where('siren', '!=', '')
            ->whereNotNull('website')
            ->where('website', '!=', '')
            ->where('is_disabled', false)
            ->orderByDesc('relevance_score')
            ->orderByDesc('id');

        foreach ($query->cursor() as $company) {
            yield $company;
        }
    }

    private function normalizeWebsite(string $value): ?string
    {
        $website = trim($value);
        if ($website === '') {
            return null;
        }

        if (! preg_match('/^https?:\/\//i', $website)) {
            $website = 'https://' . ltrim($website, '/');
        }

        $parts = parse_url($website);
        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        $host = preg_replace('/^www\./i', '', $host) ?? $host;
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $path = isset($parts['path']) && is_string($parts['path']) ? trim($parts['path']) : '';
        $path = $path === '' ? '' : '/' . ltrim($path, '/');
        $query = isset($parts['query']) && is_string($parts['query']) ? '?' . $parts['query'] : '';

        return rtrim(sprintf('%s://%s%s%s', $scheme, $host, $path, $query), '/');
    }

    private function extractDomain(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', $host) ?: null;
    }

    private function isRejectedDomain(string $domain): bool
    {
        foreach (self::REJECTED_HOSTS as $rejectedHost) {
            if ($domain === $rejectedHost || str_ends_with($domain, '.' . $rejectedHost)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSiren(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';

        return strlen($digits) === 9 ? $digits : null;
    }
}
