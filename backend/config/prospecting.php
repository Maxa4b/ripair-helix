<?php

$sireneNafPrefixes = array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) env('PROSPECTING_SIRENE_NAF_PREFIXES', '261,262,263,265,266,267,268,271,272,273,274,279,282,289,3313,3314,3320,6201,6202,7112,7120,7219,9512'))
), static fn (string $value): bool => $value !== ''));

$sireneIncludeKeywords = array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) env('PROSPECTING_SIRENE_INCLUDE_KEYWORDS', 'electronique,électronique,embarque,embarqué,hardware,iot,prototype,prototypage,pcba,pcb,cms,tht,assemblage,cablage,câblage,carte,cartes,industriel,industrie,sav,maintenance,test,capteur,mesure'))
), static fn (string $value): bool => $value !== ''));

$sireneExcludeKeywords = array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) env('PROSPECTING_SIRENE_EXCLUDE_KEYWORDS', 'iphone,smartphone,telephone,téléphone,gsm,coque,vitre,ecran,écran,batterie,pc gamer,console,playstation,xbox,nintendo,depannage informatique,dépannage informatique,boutique mobile'))
), static fn (string $value): bool => $value !== ''));

return [
    'sirene' => [
        'stock_url' => env('PROSPECTING_SIRENE_STOCK_URL'),
        'http_timeout' => (int) env('PROSPECTING_SIRENE_HTTP_TIMEOUT', 120),
        'min_score' => (int) env('PROSPECTING_SIRENE_MIN_SCORE', 55),
        'naf_prefixes' => $sireneNafPrefixes,
        'include_keywords' => $sireneIncludeKeywords,
        'exclude_keywords' => $sireneExcludeKeywords,
    ],
    'geocoding' => [
        'endpoint' => env('PROSPECTING_GEOCODER_URL', 'https://data.geopf.fr/geocodage/search'),
        'timeout' => (int) env('PROSPECTING_GEOCODER_TIMEOUT', 20),
        'min_score' => (float) env('PROSPECTING_GEOCODER_MIN_SCORE', 0.6),
    ],
    'contact_enrichment' => [
        'google_places_api_key' => env('PROSPECTING_GOOGLE_PLACES_API_KEY'),
        'google_places_search_endpoint' => env('PROSPECTING_GOOGLE_PLACES_SEARCH_ENDPOINT', 'https://places.googleapis.com/v1/places:searchText'),
        'google_places_details_base' => env('PROSPECTING_GOOGLE_PLACES_DETAILS_BASE', 'https://places.googleapis.com/v1/places'),
        'http_timeout' => (int) env('PROSPECTING_CONTACT_ENRICHMENT_TIMEOUT', 20),
        'website_timeout' => (int) env('PROSPECTING_CONTACT_WEBSITE_TIMEOUT', 12),
        'max_internal_pages' => (int) env('PROSPECTING_CONTACT_MAX_INTERNAL_PAGES', 3),
    ],
];
