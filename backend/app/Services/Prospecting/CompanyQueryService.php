<?php

namespace App\Services\Prospecting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CompanyQueryService
{
    public function applyFilters(Builder $query, array $filters): Builder
    {
        if (($filters['include_disabled'] ?? 'false') !== 'true') {
            $query->where('is_disabled', false);
        }

        $statuses = $this->parseList($filters['status'] ?? null);
        if ($statuses !== [] && ! in_array('all', $statuses, true)) {
            $query->whereIn('contact_status', $statuses);
        }

        $segments = $this->parseList($filters['segment'] ?? null);
        if ($segments !== []) {
            $query->whereIn('segment', $segments);
        }

        $owner = trim((string) ($filters['contact_owner'] ?? ''));
        if ($owner !== '') {
            $query->where('contact_owner', 'like', '%' . $owner . '%');
        }

        $zone = trim((string) ($filters['zone'] ?? ''));
        if ($zone !== '') {
            $query->where(function (Builder $inner) use ($zone): void {
                $inner->where('address', 'like', '%' . $zone . '%')
                    ->orWhere('postal_code', 'like', '%' . $zone . '%')
                    ->orWhere('city', 'like', '%' . $zone . '%')
                    ->orWhere('country', 'like', '%' . $zone . '%');
            });
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('website', 'like', '%' . $search . '%')
                    ->orWhere('siren', 'like', '%' . $search . '%')
                    ->orWhere('siret', 'like', '%' . $search . '%')
                    ->orWhere('address', 'like', '%' . $search . '%')
                    ->orWhere('city', 'like', '%' . $search . '%')
                    ->orWhere('postal_code', 'like', '%' . $search . '%');
            });
        }

        if (($filters['missing_contact'] ?? null) === 'true') {
            $query->where(function (Builder $inner): void {
                $inner->whereNull('email')
                    ->orWhere('email', '')
                    ->orWhereNull('phone')
                    ->orWhere('phone', '');
            });
        }

        $bounds = $this->parseBounds($filters['bounds'] ?? null);
        if ($bounds !== null) {
            $query->whereNotNull('lat')
                ->whereNotNull('lng')
                ->whereBetween('lat', [$bounds['south'], $bounds['north']])
                ->whereBetween('lng', [$bounds['west'], $bounds['east']]);
        } elseif (($filters['only_geocoded'] ?? 'false') === 'true') {
            $query->whereNotNull('lat')->whereNotNull('lng');
        }

        return $query;
    }

    public function parseBounds(null|string|array $bounds): ?array
    {
        if (is_array($bounds)) {
            $values = array_values($bounds);
        } else {
            $raw = trim((string) $bounds);
            if ($raw === '') {
                return null;
            }
            $values = array_map('trim', explode(',', $raw));
        }

        if (count($values) !== 4) {
            return null;
        }

        [$south, $west, $north, $east] = array_map(static fn ($value) => is_numeric($value) ? (float) $value : null, $values);

        if ($south === null || $west === null || $north === null || $east === null) {
            return null;
        }

        return [
            'south' => min($south, $north),
            'west' => min($west, $east),
            'north' => max($south, $north),
            'east' => max($west, $east),
        ];
    }

    public function normalizeHeader(string $header): string
    {
        $ascii = Str::of($header)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');

        return (string) $ascii;
    }

    /**
     * @return list<string>
     */
    private function parseList(null|string|array $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $raw = trim((string) $value);
            if ($raw === '') {
                return [];
            }
            $items = explode(',', $raw);
        }

        return array_values(array_filter(array_map(static fn ($item) => trim((string) $item), $items), static fn ($item) => $item !== ''));
    }
}
