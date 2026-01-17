<?php

namespace App\Services\Logistics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class BoxtalService
{
    public function __construct(
        private readonly ?string $key = null,
        private readonly ?string $secret = null,
        private readonly ?string $login = null,
        private readonly ?string $password = null,
        private readonly string $baseUrl = 'https://test.envoimoinscher.com/api/v1',
    ) {
    }

    public static function make(): self
    {
        $cfg = config('services.boxtal', []);
        return new self(
            $cfg['key'] ?? null,
            $cfg['secret'] ?? null,
            $cfg['login'] ?? null,
            $cfg['password'] ?? null,
            rtrim((string) ($cfg['base_url'] ?? 'https://test.envoimoinscher.com/api/v1'), '/')
        );
    }

    public function enabled(): bool
    {
        return (filled($this->key) && filled($this->secret)) || (filled($this->login) && filled($this->password));
    }

    private function authPairsPreferKey(): array
    {
        $pairs = [];
        if (filled($this->key) && filled($this->secret)) {
            $pairs[] = [$this->key, $this->secret];
        }
        if (filled($this->login) && filled($this->password)) {
            $pairs[] = [$this->login, $this->password];
        }
        return $pairs;
    }

    private function authPairsPreferLogin(): array
    {
        $pairs = [];
        if (filled($this->login) && filled($this->password)) {
            $pairs[] = [$this->login, $this->password];
        }
        if (filled($this->key) && filled($this->secret)) {
            $pairs[] = [$this->key, $this->secret];
        }
        return $pairs;
    }

    public function downloadDocument(string $url): array
    {
        $clean = trim((string) $url);
        if ($clean === '') {
            return ['ok' => false, 'errors' => ['Boxtal: URL de document vide']];
        }
        if (! $this->enabled()) {
            return ['ok' => false, 'errors' => ['Boxtal: credentials manquants']];
        }

        $pairs = $this->authPairsPreferLogin();
        if (empty($pairs)) {
            return ['ok' => false, 'errors' => ['Boxtal: credentials manquants']];
        }

        $response = null;
        foreach ($pairs as [$authUser, $authPass]) {
            $response = Http::withBasicAuth($authUser, $authPass)
                ->withHeaders([
                    'Accept' => 'application/pdf,application/octet-stream,application/xml,text/html;q=0.9,*/*;q=0.8',
                ])
                ->timeout(25)
                ->withOptions(['allow_redirects' => true])
                ->get($clean);

            if ($response->ok()) {
                break;
            }
            if (! in_array($response->status(), [401, 403], true)) {
                break;
            }
        }

        if (! $response || ! $response->ok()) {
            $status = $response?->status() ?? 0;
            $body = $response ? trim((string) $response->body()) : '';
            $errors = ['Boxtal: erreur HTTP '.$status];
            if ($body !== '') {
                $plain = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
                if ($plain !== '') {
                    $errors[] = mb_substr($plain, 0, 300);
                }
            }
            return ['ok' => false, 'errors' => $errors, 'status' => $status];
        }

        $contentType = (string) ($response->header('Content-Type') ?? '');
        if ($contentType === '') {
            $contentType = 'application/pdf';
        }

        return [
            'ok' => true,
            'body' => $response->body(),
            'content_type' => $contentType,
        ];
    }

    /**
     * Cotations Boxtal v1 (XML) via /cotation.
     *
     * @param array $from ['country','zip','city','type']
     * @param array $to ['country','zip','city','type']
     * @param array $packages liste de colis [['weight','length','width','height','value']]
     */
    public function quotes(array $from, array $to, array $packages, string $contentCode = '10150'): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        if (! function_exists('simplexml_load_string')) {
            return collect();
        }

        $query = [
            'code_contenu' => $contentCode,
            'expediteur.pays' => $from['country'] ?? '',
            'expediteur.code_postal' => $from['zip'] ?? '',
            'expediteur.ville' => $from['city'] ?? '',
            'expediteur.type' => $from['type'] ?? 'entreprise',
            'destinataire.pays' => $to['country'] ?? '',
            'destinataire.code_postal' => $to['zip'] ?? '',
            'destinataire.ville' => $to['city'] ?? '',
            'destinataire.type' => $to['type'] ?? 'particulier',
        ];

        foreach ($packages as $idx => $pkg) {
            $n = $idx + 1;
            $query["colis_{$n}.poids"] = $pkg['weight'];
            $query["colis_{$n}.longueur"] = $pkg['length'];
            $query["colis_{$n}.largeur"] = $pkg['width'];
            $query["colis_{$n}.hauteur"] = $pkg['height'];
            if (isset($pkg['value'])) {
                $query["colis_{$n}.valeur"] = $pkg['value'];
            }
        }

        $pairs = $this->authPairsPreferKey();
        if (empty($pairs)) {
            return collect();
        }

        $response = null;
        foreach ($pairs as [$authUser, $authPass]) {
            $response = Http::withBasicAuth($authUser, $authPass)
                ->withHeaders(['Accept' => 'application/xml'])
                ->timeout(12)
                ->get($this->baseUrl.'/cotation', $query);
            if ($response->ok()) {
                break;
            }
            // Si l'auth n'est pas acceptée, on tente le couple suivant.
            if (! in_array($response->status(), [401, 403], true)) {
                break;
            }
        }

        if (! $response->ok() || trim($response->body()) === '') {
            return collect();
        }

        $xml = @simplexml_load_string($response->body());
        if (! $xml) {
            return collect();
        }

        $offerNodes = [];
        try {
            $offerNodes = $xml->xpath('//offer') ?: [];
        } catch (\Throwable) {
            $offerNodes = [];
        }

        $offers = [];
        foreach ($offerNodes as $offer) {
            $operatorCode = strtoupper((string) ($offer->operator->code ?? ''));
            $operatorLabel = (string) ($offer->operator->label ?? '');
            $serviceCodeRaw = trim((string) ($offer->service->code ?? ''));
            $serviceCode = strtoupper($serviceCodeRaw);
            $serviceLabel = (string) ($offer->service->label ?? '');
            $deliveryLabel = (string) ($offer->delivery->label ?? '');
            $collecteDate = trim((string) ($offer->collection->date ?? $offer->collection_date ?? ''));
            if ($collecteDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $collecteDate)) {
                $collecteDate = '';
            }

            $priceTtc = (float) str_replace(',', '.', (string) ($offer->price->taxIncluded ?? $offer->price->tax_included ?? $offer->price->ttc ?? 0));

            $delayRaw = trim($deliveryLabel);
            $delayDate = '';
            if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $delayLabel = strtoupper($deliveryLabel), $m)) {
                $delayDate = $m[1];
            }

            $type = 'shipping';
            if ($operatorCode === 'CHRP' && str_contains($serviceCode, 'SHOP')) {
                $type = 'relay';
            } elseif ($operatorCode === 'MONR') {
                $type = 'relay';
            }

            $niceName = trim($operatorLabel.' '.$serviceLabel);
            if ($operatorCode === 'CHRP' && (str_contains($serviceCode, 'SHOP') || str_contains(strtoupper($niceName), 'SHOP2SHOP'))) {
                $niceName = 'Chronopost Shop2Shop - Point relais';
            } elseif ($operatorCode === 'MONR') {
                $niceName = 'Mondial Relay - Point relais';
            } elseif ($operatorCode === 'COLI' || $operatorCode === 'POFR' || str_contains(strtoupper($niceName), 'COLISSIMO')) {
                $service = $serviceLabel ?: $deliveryLabel;
                if (! str_contains(mb_strtoupper($service), 'DOMICILE')) {
                    $service = 'Domicile - '.$service;
                }
                $service = preg_replace('/Domicile\\s+(Sans|Avec)/iu', 'Domicile - $1', $service) ?? $service;
                $niceName = trim('La Poste Colissimo '.$service);
            }

            $offers[] = [
                'method_id' => "boxtal:{$operatorCode}:{$serviceCode}",
                'name' => $niceName ?: trim($operatorLabel.' '.$serviceLabel.' '.$deliveryLabel),
                'price' => round($priceTtc, 2),
                'delay' => $delayDate ?: $delayRaw,
                'collecte' => $collecteDate !== '' ? $collecteDate : null,
                'type' => $type,
                'origin' => 'boxtal',
                'operator' => $operatorCode,
                'service' => $serviceCode,
                // Conserve la casse d'origine pour /order (certains services sont case-sensitive).
                'service_raw' => $serviceCodeRaw !== '' ? $serviceCodeRaw : $serviceCode,
            ];
        }

        return collect($offers)->values();
    }

    /**
     * Crée une expédition Boxtal (paiement du bordereau) via /order.
     *
     * @param array $shipper ['country','zip','city','type','address','first_name','last_name','company','email','phone']
     * @param array $recipient ['country','zip','city','type','address','first_name','last_name','company','email','phone']
     * @param array $packages liste de colis [['weight','length','width','height','value']]
     * @param array $params ex: ['operator'=>'COLI','service'=>'...','collecte'=>'YYYY-MM-DD','depot.pointrelais'=>'...']
     */
    public function createOrder(array $shipper, array $recipient, array $packages, array $params, string $contentCode = '10150'): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'errors' => ['Boxtal: credentials manquants']];
        }
        if (! function_exists('simplexml_load_string')) {
            return ['ok' => false, 'errors' => ['Boxtal: extension SimpleXML manquante']];
        }

        $payload = [];

        // Compat: Boxtal accepte généralement shipper/recipient, certains environnements acceptent expediteur/destinataire.
        $mapPerson = function (string $prefix, array $data) use (&$payload): void {
            $country = (string) ($data['country'] ?? '');
            $zip = (string) ($data['zip'] ?? '');
            $city = (string) ($data['city'] ?? '');
            $type = (string) ($data['type'] ?? '');
            $address = (string) ($data['address'] ?? '');
            $first = (string) ($data['first_name'] ?? '');
            $last = (string) ($data['last_name'] ?? '');
            $company = (string) ($data['company'] ?? '');
            $email = (string) ($data['email'] ?? '');
            $phone = (string) ($data['phone'] ?? '');

            if ($country !== '') $payload["{$prefix}.pays"] = $country;
            if ($zip !== '') $payload["{$prefix}.code_postal"] = $zip;
            if ($city !== '') $payload["{$prefix}.ville"] = $city;
            if ($type !== '') $payload["{$prefix}.type"] = $type;
            if ($address !== '') $payload["{$prefix}.adresse"] = $address;
            if ($first !== '') $payload["{$prefix}.prenom"] = $first;
            if ($last !== '') $payload["{$prefix}.nom"] = $last;
            if ($company !== '') $payload["{$prefix}.societe"] = $company;
            if ($email !== '') $payload["{$prefix}.email"] = $email;
            if ($phone !== '') $payload["{$prefix}.tel"] = $phone;
        };

        $mapPerson('shipper', $shipper);
        $mapPerson('recipient', $recipient);
        $mapPerson('expediteur', $shipper);
        $mapPerson('destinataire', $recipient);

        // Colis
        foreach (array_values($packages) as $idx => $pkg) {
            $n = $idx + 1;
            $payload["colis_{$n}.poids"] = $pkg['weight'];
            $payload["colis_{$n}.longueur"] = $pkg['length'];
            $payload["colis_{$n}.largeur"] = $pkg['width'];
            $payload["colis_{$n}.hauteur"] = $pkg['height'];
            if (isset($pkg['value'])) {
                $payload["colis_{$n}.valeur"] = $pkg['value'];
            }
        }

        // Content code (compat keys)
        $payload['content_code'] = $contentCode;
        $payload['code_contenu'] = $contentCode;

        foreach ($params as $k => $v) {
            if ($v === null) continue;
            if (is_bool($v)) {
                $payload[$k] = $v ? '1' : '0';
                continue;
            }
            $payload[$k] = (string) $v;
        }

        $pairs = $this->authPairsPreferLogin();
        if (empty($pairs)) {
            return ['ok' => false, 'errors' => ['Boxtal: credentials manquants']];
        }

        $response = null;
        foreach ($pairs as [$authUser, $authPass]) {
            $response = Http::withBasicAuth($authUser, $authPass)
                ->asForm()
                ->withHeaders(['Accept' => 'application/xml'])
                ->timeout(25)
                ->post($this->baseUrl.'/order', $payload);

            if ($response->ok()) {
                break;
            }
            // Réessaie avec l'autre couple si c'est un refus d'auth.
            if (! in_array($response->status(), [401, 403], true)) {
                break;
            }
        }

        $body = (string) $response->body();
        if (! $response->ok() || trim($body) === '') {
            $status = $response->status();
            $errors = ['Boxtal: erreur HTTP '.$status];
            $boxtalCode = null;

            $raw = trim($body);
            if ($raw !== '') {
                $msg = '';

                // 1) XML (parfois avec namespaces)
                $xml = @simplexml_load_string($raw);
                if ($xml) {
                    try {
                        $codes = $xml->xpath('//*[local-name()="code"]');
                        if (is_array($codes) && isset($codes[0])) {
                            $boxtalCode = trim((string) $codes[0]) ?: $boxtalCode;
                        }
                        $nodes = $xml->xpath('//*[local-name()="message"]');
                        if (is_array($nodes) && isset($nodes[0])) {
                            $msg = trim((string) $nodes[0]);
                        }
                    } catch (\Throwable) {
                        // ignore
                    }
                    if ($boxtalCode === null && isset($xml->code)) {
                        $boxtalCode = trim((string) $xml->code) ?: null;
                    }
                    if ($msg === '' && isset($xml->message)) {
                        $msg = trim((string) $xml->message);
                    }
                }

                // 2) JSON (fallback)
                if ($msg === '' && (str_starts_with($raw, '{') || str_starts_with($raw, '['))) {
                    $j = json_decode($raw, true);
                    if (is_array($j)) {
                        $msg = (string) ($j['message'] ?? $j['error']['message'] ?? '');
                        $msg = trim($msg);
                    }
                }

                // 3) Plain text / HTML fallback
                if ($msg === '') {
                    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
                    if ($plain !== '') {
                        $msg = $plain;
                    }
                }

                if ($msg !== '') {
                    $errors[] = mb_substr($msg, 0, 500);
                } else {
                    $errors[] = 'unparsed error';
                }

                // Preview "safe" du body pour investiguer (sans dumping complet).
                $errors[] = 'body: '.mb_substr($raw, 0, 200);
            }

            return ['ok' => false, 'errors' => $errors, 'status' => $status, 'boxtal_code' => $boxtalCode];
        }

        // Parse XML + erreurs
        $xml = @simplexml_load_string($body);
        if (! $xml) {
            return ['ok' => false, 'errors' => ['Boxtal: réponse XML invalide']];
        }
        if (isset($xml->code) && isset($xml->message)) {
            return ['ok' => false, 'errors' => [(string) $xml->message]];
        }

        $reference = (string) ($xml->shipment->reference ?? '');
        if ($reference === '') {
            // Some responses nest differently: /order/shipment/reference
            try {
                $node = $xml->xpath('//shipment/reference');
                if (is_array($node) && isset($node[0])) {
                    $reference = (string) $node[0];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $labels = [];
        try {
            $labelNodes = $xml->xpath('//shipment/labels/label') ?: [];
            foreach ($labelNodes as $n) {
                $labels[] = trim((string) $n);
            }
        } catch (\Throwable) {
            $labels = [];
        }

        $offer = [
            'operator' => (string) ($xml->shipment->offer->operator->code ?? ''),
            'service' => (string) ($xml->shipment->offer->service->code ?? ''),
            'url' => (string) ($xml->shipment->offer->url ?? ''),
            'mode' => (string) ($xml->shipment->offer->mode ?? ''),
            'price_tax_inclusive' => (string) ($xml->shipment->offer->price->{'tax-inclusive'} ?? ''),
        ];

        return [
            'ok' => $reference !== '',
            'reference' => $reference,
            'labels' => $labels,
            'raw' => $body,
            'offer' => $offer,
        ];
    }

    /**
     * Récupère les infos d'une expédition Boxtal via /order_status/{ref}/informations.
     */
    public function getOrderInformations(string $reference): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'errors' => ['Boxtal: credentials manquants']];
        }
        if (! function_exists('simplexml_load_string')) {
            return ['ok' => false, 'errors' => ['Boxtal: extension SimpleXML manquante']];
        }

        $pairs = $this->authPairsPreferLogin();
        if (empty($pairs)) {
            return ['ok' => false, 'errors' => ['Boxtal: credentials manquants']];
        }

        $response = null;
        foreach ($pairs as [$authUser, $authPass]) {
            $response = Http::withBasicAuth($authUser, $authPass)
                ->withHeaders(['Accept' => 'application/xml'])
                ->timeout(20)
                ->get($this->baseUrl.'/order_status/'.$reference.'/informations');
            if ($response->ok()) {
                break;
            }
            if (! in_array($response->status(), [401, 403], true)) {
                break;
            }
        }

        $body = (string) $response->body();
        if (! $response->ok() || trim($body) === '') {
            return ['ok' => false, 'errors' => ['Boxtal: erreur HTTP '.$response->status()]];
        }

        $xml = @simplexml_load_string($body);
        if (! $xml) {
            return ['ok' => false, 'errors' => ['Boxtal: réponse XML invalide']];
        }
        if (isset($xml->code) && isset($xml->message)) {
            return ['ok' => false, 'errors' => [(string) $xml->message]];
        }

        $labels = [];
        try {
            $labelNodes = $xml->xpath('//order/labels/*') ?: [];
            foreach ($labelNodes as $n) {
                $labels[] = trim((string) $n);
            }
        } catch (\Throwable) {
            $labels = [];
        }

        $labelUrl = '';
        try {
            $labelUrl = (string) ($xml->label_url ?? '');
            if ($labelUrl === '') {
                $node = $xml->xpath('//order/label_url');
                if (is_array($node) && isset($node[0])) {
                    $labelUrl = (string) $node[0];
                }
            }
        } catch (\Throwable) {
            $labelUrl = '';
        }

        $carrierRef = '';
        $carrierPaths = [
            '//order/carrier_reference',
            '//order/tracking_number',
            '//order/tracking',
            '//order/tracking_reference',
            '//order/parcel_tracking_number',
            '//order/parcel/tracking_number',
            '//order/parcel/tracking',
            '//order/parcel/reference',
            '//shipment/tracking_number',
            '//shipment/tracking',
            '//order/reference_transporteur',
            '//order/transporteur_reference',
        ];
        foreach ($carrierPaths as $path) {
            try {
                $node = $xml->xpath($path);
                if (is_array($node) && isset($node[0])) {
                    $candidate = trim((string) $node[0]);
                    if ($candidate !== '') {
                        $carrierRef = $candidate;
                        break;
                    }
                }
            } catch (\Throwable) {
                // ignore xpath errors
            }
        }

        if ($carrierRef === '') {
            try {
                if (preg_match('/<(carrier_reference|tracking_number|tracking)[^>]*>([^<]+)<\\/\\1>/i', $body, $matches)) {
                    $carrierRef = trim((string) ($matches[2] ?? ''));
                }
            } catch (\Throwable) {
                $carrierRef = '';
            }
        }

        $state = '';
        try {
            $node = $xml->xpath('//order/state');
            if (is_array($node) && isset($node[0])) {
                $state = (string) $node[0];
            }
        } catch (\Throwable) {
            $state = '';
        }

        return [
            'ok' => true,
            'emc_ref' => $reference,
            'state' => $state,
            'carrier_reference' => $carrierRef,
            'label_url' => $labelUrl,
            'labels' => $labels,
            'raw' => $body,
        ];
    }
}
