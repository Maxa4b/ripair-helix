<?php

namespace App\Services\Prospecting;

use Illuminate\Support\Facades\Http;

class WebsiteContactDiscoveryService
{
    /**
     * @return array{email:?string, phone:?string}
     */
    public function discover(?string $website): array
    {
        $baseUrl = $this->normalizeWebsite($website);
        if ($baseUrl === null) {
            return ['email' => null, 'phone' => null];
        }

        $pages = [$baseUrl];
        $discovered = [
            'email' => null,
            'phone' => null,
        ];

        $homeHtml = $this->fetchHtml($baseUrl);
        if ($homeHtml !== null) {
            $discovered = $this->mergeContacts($discovered, $this->extractContacts($homeHtml));
            $pages = array_merge($pages, $this->extractContactLinks($baseUrl, $homeHtml));
        }

        $pages = array_values(array_unique($pages));
        $pages = array_slice($pages, 0, max(1, (int) config('prospecting.contact_enrichment.max_internal_pages', 3)) + 1);

        foreach ($pages as $pageUrl) {
            if ($discovered['email'] !== null && $discovered['phone'] !== null) {
                break;
            }

            if ($pageUrl === $baseUrl && $homeHtml !== null) {
                continue;
            }

            $html = $this->fetchHtml($pageUrl);
            if ($html === null) {
                continue;
            }

            $discovered = $this->mergeContacts($discovered, $this->extractContacts($html));
        }

        return $discovered;
    }

    private function normalizeWebsite(?string $website): ?string
    {
        $value = trim((string) ($website ?? ''));
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^https?:\/\//i', $value)) {
            $value = 'https://' . ltrim($value, '/');
        }

        return rtrim($value, '/');
    }

    private function fetchHtml(string $url): ?string
    {
        $response = Http::timeout((int) config('prospecting.contact_enrichment.website_timeout', 12))
            ->withHeaders([
                'User-Agent' => 'RIPAIR Helix Prospecting Bot/1.0 (+https://helix.ripair.shop)',
            ])
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        $contentType = strtolower((string) $response->header('Content-Type', ''));
        if ($contentType !== '' && ! str_contains($contentType, 'html')) {
            return null;
        }

        return $response->body();
    }

    /**
     * @return array{email:?string, phone:?string}
     */
    private function extractContacts(string $html): array
    {
        $mailToMatches = [];
        preg_match_all('/mailto:([^"\'?#\s>]+)/i', $html, $mailToMatches);
        $emailMatches = [];
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $html, $emailMatches);

        $phoneMatches = [];
        preg_match_all('/(?:\+33|0)[1-9](?:[\s.\-]?\d{2}){4}/', $html, $phoneMatches);

        $emails = array_values(array_unique(array_filter(array_map(
            static fn ($email): string => strtolower(trim((string) $email)),
            array_merge($mailToMatches[1] ?? [], $emailMatches[0] ?? []),
        ), static fn (string $email): bool => ! preg_match('/\.(png|jpg|jpeg|gif|svg|webp)$/i', $email))));

        $phones = array_values(array_unique(array_map(
            static fn ($phone): string => trim(preg_replace('/\s+/', ' ', (string) $phone) ?? (string) $phone),
            $phoneMatches[0] ?? [],
        )));

        return [
            'email' => $emails[0] ?? null,
            'phone' => $phones[0] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractContactLinks(string $baseUrl, string $html): array
    {
        $matches = [];
        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);

        $host = parse_url($baseUrl, PHP_URL_HOST);
        $links = [];

        foreach ($matches[1] ?? [] as $href) {
            $candidate = trim((string) $href);
            if ($candidate === '' || str_starts_with($candidate, 'mailto:') || str_starts_with($candidate, 'tel:')) {
                continue;
            }

            $normalized = $this->resolveUrl($baseUrl, $candidate);
            if ($normalized === null) {
                continue;
            }

            if (parse_url($normalized, PHP_URL_HOST) !== $host) {
                continue;
            }

            $path = strtolower((string) parse_url($normalized, PHP_URL_PATH));
            if (
                str_contains($path, 'contact')
                || str_contains($path, 'mentions-legales')
                || str_contains($path, 'mentions_legales')
                || str_contains($path, 'legal')
                || str_contains($path, 'about')
            ) {
                $links[] = $normalized;
            }
        }

        return array_values(array_unique($links));
    }

    private function resolveUrl(string $baseUrl, string $href): ?string
    {
        if (preg_match('/^https?:\/\//i', $href)) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        if (! str_starts_with($href, '/')) {
            $href = '/' . ltrim($href, '/');
        }

        return rtrim($baseUrl, '/') . $href;
    }

    /**
     * @param  array{email:?string, phone:?string}  $current
     * @param  array{email:?string, phone:?string}  $next
     * @return array{email:?string, phone:?string}
     */
    private function mergeContacts(array $current, array $next): array
    {
        return [
            'email' => $current['email'] ?? $next['email'],
            'phone' => $current['phone'] ?? $next['phone'],
        ];
    }
}
