<?php

namespace App\Http\Controllers\Livreo;

use App\Models\Livreo\SupplierOrder;
use App\Services\Logistics\BoxtalService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends LivreoBaseController
{
    private function appendBoxtalLog(array $metadata, string $event, array $data = []): array
    {
        $logs = Arr::get($metadata, 'boxtal_logs', []);
        if (! is_array($logs)) {
            $logs = [];
        }

        $entry = array_merge([
            'at' => now()->toISOString(),
            'event' => $event,
        ], $data);

        $logs[] = $entry;
        // Garde les 30 derniers.
        if (count($logs) > 30) {
            $logs = array_slice($logs, -30);
        }

        $metadata['boxtal_logs'] = $logs;
        return $metadata;
    }

    private function boxtalCollecteDate(?string $preferredDate = null): string
    {
        // Boxtal: la collecte n'est pas garantie J+1 (jours fériés / contraintes transporteurs).
        $d = now()->addDays(2)->startOfDay();
        $fixedHolidays = [
            '01-01', // Jour de l'an
            '05-01', // Fête du travail
            '05-08', // Victoire 1945
            '07-14', // Fête nationale
            '08-15', // Assomption
            '11-01', // Toussaint
            '11-11', // Armistice
            '12-25', // Noël
        ];

        while ($d->isWeekend() || in_array($d->format('m-d'), $fixedHolidays, true)) {
            $d->addDay();
        }

        $min = $d->copy();
        if (is_string($preferredDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferredDate)) {
            try {
                $p = \Carbon\Carbon::createFromFormat('Y-m-d', $preferredDate)->startOfDay();
                // N'accepte une date "préférée" que si elle est >= min (évite collecte le jour même / fériés).
                if ($p->greaterThanOrEqualTo($min) && ! $p->isWeekend() && ! in_array($p->format('m-d'), $fixedHolidays, true)) {
                    return $p->toDateString();
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $min->toDateString();
    }

    private function carrierNameFromOperator(?string $operator): string
    {
        return match (strtoupper((string) $operator)) {
            'COLI' => 'Colissimo',
            'POFR' => 'Colissimo',
            'CHRP' => 'Chronopost',
            'MONR' => 'Mondial Relay',
            default => 'Boxtal',
        };
    }

    private function boxtalFriendlyName(?string $operator, ?string $service): ?string
    {
        $operator = strtoupper((string) $operator);
        $service = strtoupper((string) $service);

        if ($operator === 'CHRP') {
            return 'Chronopost Shop2Shop - Point relais';
        }

        if ($operator === 'MONR') {
            return 'Mondial Relay - Point relais';
        }

        if ($operator === 'COLI' || $operator === 'POFR') {
            $suffix = 'Domicile';
            if (str_contains($service, 'SANS')) {
                $suffix .= ' - Sans Signature';
            } elseif (str_contains($service, 'AVEC') || str_contains($service, 'SIGNATURE')) {
                $suffix .= ' - Avec Signature';
            }

            return 'La Poste Colissimo '.$suffix;
        }

        return null;
    }

    private function inferBoxtalPreference(array $shippingChoice): array
    {
        $methodId = (string) ($shippingChoice['method_id'] ?? '');
        $operator = strtoupper((string) ($shippingChoice['operator'] ?? ''));
        $type = (string) ($shippingChoice['type'] ?? '');
        $name = strtoupper((string) ($shippingChoice['name'] ?? ''));
        // Compat e-commerce: certaines anciennes valeurs utilisent "home" au lieu de "shipping".
        if ($type === 'home') {
            $type = 'shipping';
        }

        $preferSignature = null;

        if (str_starts_with($methodId, 'boxtal:')) {
            $parts = explode(':', $methodId);
            $serviceFromId = strtoupper((string) ($parts[2] ?? ''));
            if ($preferSignature === null) {
                if ($serviceFromId !== '' && str_contains($serviceFromId, 'SANS')) {
                    $preferSignature = false;
                } elseif ($serviceFromId !== '' && (str_contains($serviceFromId, 'AVEC') || str_contains($serviceFromId, 'SIGNATURE'))) {
                    $preferSignature = true;
                }
            }
            return [
                'requested_method_id' => $methodId,
                'operator' => strtoupper((string) ($parts[1] ?? $operator)),
                'service' => $serviceFromId,
                'type' => $type,
                'prefer_signature' => $preferSignature,
            ];
        }

        if (str_starts_with($methodId, 'manual:colissimo_home_sig')) {
            $operator = $operator ?: 'POFR';
            if ($operator === 'COLI') {
                $operator = 'POFR';
            }
            $type = $type ?: 'shipping';
            $preferSignature = true;
        } elseif (str_starts_with($methodId, 'manual:colissimo_home_no_sig')) {
            $operator = $operator ?: 'POFR';
            if ($operator === 'COLI') {
                $operator = 'POFR';
            }
            $type = $type ?: 'shipping';
            $preferSignature = false;
        } elseif (str_starts_with($methodId, 'manual:chronopost_shop2shop_relay')) {
            $operator = $operator ?: 'CHRP';
            $type = $type ?: 'relay';
        } elseif (str_starts_with($methodId, 'manual:mondial_relay')) {
            $operator = $operator ?: 'MONR';
            $type = $type ?: 'relay';
        }

        // Fallback: infère à partir du libellé si besoin.
        if ($operator === '' && $name !== '') {
            if (str_contains($name, 'MONDIAL')) {
                $operator = 'MONR';
                $type = $type ?: 'relay';
            } elseif (str_contains($name, 'SHOP2SHOP') || str_contains($name, 'CHRONOPOST')) {
                $operator = 'CHRP';
                $type = $type ?: 'relay';
            } elseif (str_contains($name, 'COLISSIMO') || str_contains($name, 'LA POSTE')) {
                $operator = 'COLI';
                $type = $type ?: 'shipping';
            }
        }
        if ($preferSignature === null && $name !== '') {
            if (str_contains($name, 'SANS SIGNATURE')) {
                $preferSignature = false;
            } elseif (str_contains($name, 'AVEC SIGNATURE') || str_contains($name, 'SIGNATURE')) {
                $preferSignature = true;
            }
        }

        return [
            'requested_method_id' => $methodId !== '' ? $methodId : null,
            'operator' => $operator !== '' ? $operator : null,
            'service' => null,
            'type' => $type !== '' ? $type : null,
            'prefer_signature' => $preferSignature,
        ];
    }

    private function selectMatchingBoxtalOffer(array $pref, array $offers): ?array
    {
        $requestedMethodId = (string) ($pref['requested_method_id'] ?? '');
        $operator = strtoupper((string) ($pref['operator'] ?? ''));
        $service = strtoupper((string) ($pref['service'] ?? ''));
        $type = (string) ($pref['type'] ?? '');
        $preferSignature = $pref['prefer_signature'] ?? null;

        if ($requestedMethodId !== '' && str_starts_with($requestedMethodId, 'boxtal:')) {
            foreach ($offers as $offer) {
                if (($offer['method_id'] ?? null) === $requestedMethodId) {
                    return $offer;
                }
            }
        }

        $operatorMatches = function ($offerOperator) use ($operator): bool {
            if ($operator === '') {
                return true;
            }
            $offerOperator = strtoupper((string) $offerOperator);
            if ($operator === 'COLI' || $operator === 'POFR') {
                return in_array($offerOperator, ['COLI', 'POFR'], true);
            }
            return $offerOperator === $operator;
        };

        $filter = function ($offer) use ($operatorMatches, $service, $type) {
            if (! $operatorMatches($offer['operator'] ?? '')) {
                return false;
            }
            if ($service !== '' && strtoupper((string) ($offer['service'] ?? '')) !== $service) {
                return false;
            }
            if ($type !== '' && (string) ($offer['type'] ?? '') !== $type) {
                return false;
            }
            return true;
        };

        $candidates = array_values(array_filter($offers, $filter));

        // Si le code service diffère légèrement (ex: suffix/prefix), on tente un match "contient / est contenu".
        if (empty($candidates) && $operator !== '' && $service !== '') {
            $opCandidates = array_values(array_filter($offers, function ($offer) use ($operatorMatches, $type) {
                if (! $operatorMatches($offer['operator'] ?? '')) {
                    return false;
                }
                if ($type !== '' && (string) ($offer['type'] ?? '') !== $type) {
                    return false;
                }
                return true;
            }));
            foreach ($opCandidates as $offer) {
                $offerService = strtoupper((string) ($offer['service'] ?? ''));
                if ($offerService !== '' && (str_contains($offerService, $service) || str_contains($service, $offerService))) {
                    return $offer;
                }
            }
        }

        // Si la cotation retourne un type différent (ex: relay vs shipping), on retente sans filtrer par type.
        if (empty($candidates) && $type !== '') {
            $type = '';
            $candidates = array_values(array_filter($offers, function ($offer) use ($operatorMatches, $service) {
                if (! $operatorMatches($offer['operator'] ?? '')) {
                    return false;
                }
                if ($service !== '' && strtoupper((string) ($offer['service'] ?? '')) !== $service) {
                    return false;
                }
                return true;
            }));
        }

        if (in_array($operator, ['COLI', 'POFR'], true) && is_bool($preferSignature)) {
            $sigNeedle = $preferSignature ? 'AVEC SIGNATURE' : 'SANS SIGNATURE';
            foreach ($candidates as $offer) {
                $name = strtoupper((string) ($offer['name'] ?? ''));
                if (str_contains($name, $sigNeedle)) {
                    return $offer;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    private function ecommerceHasTable(string $table): bool
    {
        try {
            return $this->ecommerce()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function computeShippingChoice(mixed $metadataValue, mixed $shippingMethodId, string $deliveryType, float $shippingTotal): array
    {
        $metadata = $this->decodeJson($metadataValue);
        $shipping = Arr::get($metadata, 'shipping', []);
        if (! is_array($shipping)) {
            $shipping = [];
        }

        // Si Helix a déjà "résolu" une sélection Boxtal (via refresh), complète le choix (sans écraser le prix payé).
        $boxtalSelected = Arr::get($metadata, 'shipping.boxtal_selected');
        if (is_array($boxtalSelected)) {
            foreach (['method_id', 'operator', 'service', 'service_raw', 'delay', 'collecte', 'type', 'name'] as $k) {
                if (empty($shipping[$k]) && array_key_exists($k, $boxtalSelected)) {
                    $shipping[$k] = $boxtalSelected[$k];
                }
            }
            foreach (['price_original', 'is_free'] as $k) {
                if (! array_key_exists($k, $shipping) && array_key_exists($k, $boxtalSelected)) {
                    $shipping[$k] = $boxtalSelected[$k];
                }
            }
        }

        $shipping = array_merge($shipping, [
            'type' => $shipping['type'] ?? ($deliveryType !== '' ? $deliveryType : null),
            'price' => array_key_exists('price', $shipping) ? (float) $shipping['price'] : $shippingTotal,
        ]);

        if (! empty($shipping['name'])) {
            return $shipping;
        }

        $methodId = is_scalar($shippingMethodId) ? (string) $shippingMethodId : '';
        if ($methodId !== '' && ! array_key_exists('method_id', $shipping)) {
            $shipping['method_id'] = $methodId;
        }

        $methodCode = $methodId;
        if ($methodCode === '' && is_string($shipping['method_id'] ?? null)) {
            $methodCode = (string) $shipping['method_id'];
        }

        // Anciennes commandes : shipping_method_id numérique => table shipping_methods
        if ($methodId !== '' && ctype_digit($methodId) && $this->ecommerceHasTable('shipping_methods')) {
            try {
                $sm = $this->ecommerce()
                    ->table('shipping_methods')
                    ->where('id', (int) $methodId)
                    ->select(['id', 'name', 'code', 'type', 'min_delay_days', 'max_delay_days'])
                    ->first();
            } catch (QueryException) {
                $sm = null;
            }

            if ($sm) {
                $shipping['name'] = $shipping['name'] ?? (string) $sm->name;
                $shipping['provider'] = $shipping['provider'] ?? (str_starts_with((string) $sm->code, 'boxtal:') ? 'boxtal' : 'manual');
                $shipping['method_id'] = $shipping['method_id'] ?? (string) $sm->code;
                $shipping['type'] = $shipping['type'] ?? (string) $sm->type;
                $shipping['delay'] = $shipping['delay'] ?? [
                    'min_days' => $sm->min_delay_days ?? null,
                    'max_days' => $sm->max_delay_days ?? null,
                ];
            }
        }

        // Cas string: boxtal:OP:SERVICE (dans orders.shipping_method_id ou metadata.shipping.method_id)
        if ($methodCode !== '' && str_starts_with($methodCode, 'boxtal:')) {
            $parts = explode(':', $methodCode);
            $shipping['provider'] = $shipping['provider'] ?? 'boxtal';
            $shipping['operator'] = $shipping['operator'] ?? ($parts[1] ?? null);
            $shipping['service'] = $shipping['service'] ?? ($parts[2] ?? null);
            if (empty($shipping['name'])) {
                $shipping['name'] = $this->boxtalFriendlyName($shipping['operator'] ?? null, $shipping['service'] ?? null)
                    ?? trim('Boxtal '.(($parts[1] ?? '') !== '' ? $parts[1].' ' : '').($parts[2] ?? ''));
            }
        }

        if (($shipping['provider'] ?? null) === 'boxtal') {
            if (empty($shipping['name'])) {
                $shipping['name'] = $this->boxtalFriendlyName($shipping['operator'] ?? null, $shipping['service'] ?? null);
            }
            if (empty($shipping['type'])) {
                $op = strtoupper((string) ($shipping['operator'] ?? ''));
                $shipping['type'] = in_array($op, ['CHRP', 'MONR'], true) ? 'relay' : 'shipping';
            }
        }

        if (empty($shipping['name'])) {
            $shipping['name'] = match ($deliveryType) {
                'relay' => 'Point relais',
                'workshop_pickup' => 'Retrait atelier',
                default => 'Livraison',
            };
        }

        return $shipping;
    }

    public function boxtalRefresh(int $id, Request $request): JsonResponse
    {
        $order = $this->ecommerce()->table('orders')->where('id', $id)->first();
        if (! $order) {
            abort(404);
        }

        $metadata = $this->decodeJson($order->metadata);
        $shippingMeta = Arr::get($metadata, 'shipping', []);
        if (! is_array($shippingMeta)) {
            $shippingMeta = [];
        }

        $shippingAddress = $this->decodeJson($order->shipping_address);
        $country = (string) Arr::get($shippingAddress, 'country_code', 'FR');
        $zip = (string) Arr::get($shippingAddress, 'postal_code', '');
        $city = (string) Arr::get($shippingAddress, 'city', '');
        $toType = (string) Arr::get($shippingAddress, 'type', 'particulier');

        if ($zip === '' || $city === '' || $country === '') {
            return response()->json(['message' => 'Adresse de livraison incomplète (pays/CP/ville).'], 422);
        }

        $boxtal = BoxtalService::make();
        $boxtalEnabled = $boxtal->enabled();
        if (! $boxtalEnabled) {
            return response()->json(['message' => 'Boxtal non configuré côté Helix (credentials manquants).'], 503);
        }

        $fromCfg = (array) config('services.boxtal.from', []);
        $defaults = (array) config('services.boxtal.defaults', []);
        $contentCode = (string) ($defaults['content_code'] ?? '10150');
        $length = (float) ($defaults['length'] ?? 20);
        $width = (float) ($defaults['width'] ?? 20);
        $height = (float) ($defaults['height'] ?? 20);

        $from = [
            'country' => (string) ($fromCfg['country'] ?? 'FR'),
            'zip' => (string) ($fromCfg['zip'] ?? ''),
            'city' => (string) ($fromCfg['city'] ?? ''),
            'type' => (string) ($fromCfg['type'] ?? 'entreprise'),
        ];

        $to = [
            'country' => $country,
            'zip' => $zip,
            'city' => $city,
            'type' => $toType ?: 'particulier',
        ];

        $items = $this->ecommerce()
            ->table('order_items')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->where('order_items.order_id', $id)
            ->select([
                'order_items.quantity',
                'products.shipping_weight',
            ])
            ->get();

        // 1 colis par commande (pas de multi-colis).
        $packages = [];
        $declaredValue = (float) ($order->subtotal_ttc ?? $order->total_ttc ?? 0);
        $totalWeight = 0.0;
        foreach ($items as $row) {
            $qty = max(1, (int) ($row->quantity ?? 1));
            $w = (float) ($row->shipping_weight ?? 0.2);
            $w = max(0.2, $w);
            $totalWeight += ($w * $qty);
        }

        $totalWeight = max(0.2, $totalWeight);
        $packages[] = [
            'weight' => $totalWeight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'value' => $declaredValue,
        ];

        $offers = [];
        if ($boxtalEnabled && $zip !== '' && $city !== '' && $country !== '' && (string) ($from['zip'] ?? '') !== '' && (string) ($from['city'] ?? '') !== '') {
            $offers = $boxtal->quotes($from, $to, $packages, $contentCode)->values()->all();
        }

        $pref = $this->inferBoxtalPreference(array_merge($shippingMeta, [
            'type' => $shippingMeta['type'] ?? (string) ($order->delivery_type ?? ''),
        ]));

        $selected = $this->selectMatchingBoxtalOffer($pref, $offers);

        // Persiste une sélection Boxtal "résolue" pour permettre l'achat du bordereau même si la commande stocke un method_id manuel.
        if ($selected) {
            $meta = $metadata;
            $ship = Arr::get($meta, 'shipping', []);
            if (! is_array($ship)) {
                $ship = [];
            }
            $ship['boxtal_selected'] = Arr::only($selected, [
                'method_id',
                'name',
                'operator',
                'service',
                'service_raw',
                'collecte',
                'type',
                'delay',
                'price',
                'price_original',
                'is_free',
            ]);
            $meta['shipping'] = $ship;

            $this->ecommerce()->table('orders')->where('id', $id)->update([
                'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        return response()->json([
            'requested' => [
                'from' => [
                    'country' => $from['country'],
                    'zip' => $from['zip'],
                    'city' => $from['city'],
                    'type' => $from['type'],
                ],
                'to' => [
                    'country' => $to['country'],
                    'zip' => $to['zip'],
                    'city' => $to['city'],
                    'type' => $to['type'],
                ],
                'packages_count' => count($packages),
                'content_code' => $contentCode,
            ],
            'stored_choice' => [
                'method_id' => Arr::get($shippingMeta, 'method_id'),
                'name' => Arr::get($shippingMeta, 'name'),
                'operator' => Arr::get($shippingMeta, 'operator'),
                'service' => Arr::get($shippingMeta, 'service'),
                'type' => Arr::get($shippingMeta, 'type') ?? (string) ($order->delivery_type ?? ''),
                'price' => Arr::get($shippingMeta, 'price') ?? (float) ($order->shipping_total ?? 0),
                'delay' => Arr::get($shippingMeta, 'delay'),
                'provider' => Arr::get($shippingMeta, 'provider'),
            ],
            'preference' => $pref,
            'selected' => $selected,
            'offers' => $offers,
        ]);
    }

    public function boxtalValidate(int $id): JsonResponse
    {
        $order = $this->ecommerce()->table('orders')->where('id', $id)->first();
        if (! $order) {
            abort(404);
        }

        $metadata = $this->decodeJson($order->metadata);
        $billing = $this->decodeJson($order->billing_address);
        $shippingAddress = $this->decodeJson($order->shipping_address);

        $missingRecipient = [];
        $country = (string) Arr::get($shippingAddress, 'country_code', 'FR');
        $zip = (string) Arr::get($shippingAddress, 'postal_code', '');
        $city = (string) Arr::get($shippingAddress, 'city', '');
        $recipientAddress = trim((string) Arr::get($shippingAddress, 'line1', '').' '.(string) Arr::get($shippingAddress, 'line2', ''));
        if ($recipientAddress === '') $missingRecipient[] = 'address';
        if ($zip === '') $missingRecipient[] = 'postal_code';
        if ($city === '') $missingRecipient[] = 'city';
        if ($country === '') $missingRecipient[] = 'country_code';

        $recipientEmail = trim((string) Arr::get($shippingAddress, 'email', Arr::get($billing, 'email', Arr::get($metadata, 'guest_email', ''))));
        $recipientPhone = trim((string) Arr::get($shippingAddress, 'phone', Arr::get($billing, 'phone', '')));
        $recipientFirst = trim((string) Arr::get($shippingAddress, 'first_name', ''));
        $recipientLast = trim((string) Arr::get($shippingAddress, 'last_name', ''));
        if ($recipientFirst === '') $missingRecipient[] = 'first_name';
        if ($recipientLast === '') $missingRecipient[] = 'last_name';
        if ($recipientEmail === '') $missingRecipient[] = 'email';
        if ($recipientPhone === '') $missingRecipient[] = 'phone';

        $fromCfg = (array) config('services.boxtal.from', []);
        $missingShipperEnv = [];
        foreach ([
            'BOXTAL_FROM_ADDRESS' => trim((string) ($fromCfg['address'] ?? '')),
            'BOXTAL_FROM_FIRST_NAME' => trim((string) ($fromCfg['first_name'] ?? '')),
            'BOXTAL_FROM_LAST_NAME' => trim((string) ($fromCfg['last_name'] ?? '')),
            'BOXTAL_FROM_EMAIL' => trim((string) ($fromCfg['email'] ?? '')),
            'BOXTAL_FROM_PHONE' => trim((string) ($fromCfg['phone'] ?? '')),
            'BOXTAL_FROM_ZIP' => trim((string) ($fromCfg['zip'] ?? '')),
            'BOXTAL_FROM_CITY' => trim((string) ($fromCfg['city'] ?? '')),
        ] as $envKey => $val) {
            if ($val === '') $missingShipperEnv[] = $envKey;
        }

        $ok = empty($missingRecipient) && empty($missingShipperEnv);
        return response()->json([
            'ok' => $ok,
            'missing_recipient_fields' => array_values(array_unique($missingRecipient)),
            'missing_shipper_env' => $missingShipperEnv,
        ], $ok ? 200 : 422);
    }

    public function boxtalBuyLabel(int $id, Request $request): JsonResponse
    {
        // Anti double-clic / double paiement : verrouille la commande pendant l'achat du bordereau.
        $lock = null;
        try {
            $lock = Cache::lock('livreo:boxtal-buy-label:'.$id, 60);
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock) {
            if (! $lock->get()) {
                return response()->json([
                    'message' => 'Achat du bordereau déjà en cours pour cette commande. Réessaie dans quelques secondes.',
                ], 409);
            }
        }

        try {
        $data = $request->validate([
            'method_id' => ['nullable', 'string', 'max:100'],
            'force' => ['sometimes', 'boolean'],
            'dev' => ['sometimes', 'boolean'],
            'set_status' => ['nullable', 'string'], // ex: shipped
        ]);

        $devMode = (bool) config('services.boxtal.dev_mode', false);
        $requestedDev = (bool) ($data['dev'] ?? false);
        $useDevMode = $devMode && $requestedDev;

        $order = $this->ecommerce()->table('orders')->where('id', $id)->first();
        if (! $order) {
            abort(404);
        }

        if ($requestedDev && ! $devMode) {
            return response()->json([
                'message' => "Mode dev demand├®, mais BOXTAL_DEV_MODE n'est pas activ├® c├┤t├® backend.",
            ], 422);
        }

        if ((string) $order->payment_status !== 'paid' && ! $useDevMode) {
            return response()->json(['message' => 'Le bordereau Boxtal ne peut être acheté que pour une commande payée.'], 422);
        }

        $metadata = $this->decodeJson($order->metadata);

        $existingLabel = Arr::get($metadata, 'shipping_label', []);
        if (is_array($existingLabel) && ! empty($existingLabel['emc_ref']) && empty($data['force'])) {
            return response()->json([
                'message' => 'Un bordereau Boxtal existe déjà pour cette commande.',
                'shipping_label' => $existingLabel,
            ], 409);
        }

        $inProgress = Arr::get($metadata, 'boxtal_buy.in_progress');
        $startedAt = (string) Arr::get($metadata, 'boxtal_buy.started_at', '');
        if ($inProgress && $startedAt !== '') {
            try {
                $started = \Carbon\Carbon::parse($startedAt);
                if ($started->greaterThan(now()->subMinutes(2))) {
                    return response()->json([
                        'message' => 'Achat du bordereau déjà en cours pour cette commande.',
                    ], 409);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $metadata['boxtal_buy'] = [
            'in_progress' => true,
            'started_at' => now()->toISOString(),
            'by_user_id' => $request->user()?->id,
        ];
        $metadata = $this->appendBoxtalLog($metadata, 'buy_started', [
            'order_id' => (int) $id,
        ]);
        $this->ecommerce()->table('orders')->where('id', $id)->update([
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $billing = $this->decodeJson($order->billing_address);
        $shippingAddress = $this->decodeJson($order->shipping_address);

        $country = (string) Arr::get($shippingAddress, 'country_code', 'FR');
        $zip = (string) Arr::get($shippingAddress, 'postal_code', '');
        $city = (string) Arr::get($shippingAddress, 'city', '');
        $toType = (string) Arr::get($shippingAddress, 'type', 'particulier');
        $recipientAddress = trim((string) Arr::get($shippingAddress, 'line1', '').' '.(string) Arr::get($shippingAddress, 'line2', ''));

        if (($zip === '' || $city === '' || $country === '' || $recipientAddress === '') && ! $useDevMode) {
            $missing = [];
            if ($recipientAddress === '') $missing[] = 'address';
            if ($zip === '') $missing[] = 'postal_code';
            if ($city === '') $missing[] = 'city';
            if ($country === '') $missing[] = 'country_code';
            if (is_array($metadata['boxtal_buy'] ?? null)) {
                $metadata['boxtal_buy']['in_progress'] = false;
            }
            $this->ecommerce()->table('orders')->where('id', $id)->update([
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return response()->json(['message' => 'Adresse de livraison incomplète.', 'missing' => $missing], 422);
        }

        $boxtal = BoxtalService::make();
        $boxtalEnabled = $boxtal->enabled();
        if (! $boxtalEnabled && ! $useDevMode) {
            return response()->json(['message' => 'Boxtal non configuré côté Helix (credentials manquants).'], 503);
        }

        $fromCfg = (array) config('services.boxtal.from', []);
        $defaults = (array) config('services.boxtal.defaults', []);
        $contentCode = (string) ($defaults['content_code'] ?? '10150');
        $length = (float) ($defaults['length'] ?? 20);
        $width = (float) ($defaults['width'] ?? 20);
        $height = (float) ($defaults['height'] ?? 20);

        $fromZip = (string) ($fromCfg['zip'] ?? '');
        $fromCity = (string) ($fromCfg['city'] ?? '');
        if (($fromZip === '' || $fromCity === '') && ! $useDevMode) {
            return response()->json(['message' => 'Boxtal: expéditeur incomplet (CP/ville).'], 422);
        }

        $from = [
            'country' => (string) ($fromCfg['country'] ?? 'FR'),
            'zip' => $fromZip !== '' ? $fromZip : '00000',
            'city' => $fromCity !== '' ? $fromCity : 'DEV',
            'type' => (string) ($fromCfg['type'] ?? 'entreprise'),
        ];

        $to = [
            'country' => $country !== '' ? $country : 'FR',
            'zip' => $zip !== '' ? $zip : '00000',
            'city' => $city !== '' ? $city : 'DEV',
            'type' => $toType !== '' ? $toType : 'particulier',
        ];

        $items = $this->ecommerce()
            ->table('order_items')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->where('order_items.order_id', $id)
            ->select([
                'order_items.quantity',
                'products.shipping_weight',
            ])
            ->get();

        // 1 colis par commande (pas de multi-colis).
        $packages = [];
        $declaredValue = (float) ($order->subtotal_ttc ?? $order->total_ttc ?? 0);
        $totalWeight = 0.0;
        foreach ($items as $row) {
            $qty = max(1, (int) ($row->quantity ?? 1));
            $w = (float) ($row->shipping_weight ?? 0.2);
            $w = max(0.2, $w);
            $totalWeight += ($w * $qty);
        }
        $totalWeight = max(0.2, $totalWeight);
        $packages[] = [
            'weight' => $totalWeight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'value' => $declaredValue,
        ];

        $shippingChoice = $this->computeShippingChoice(
            $order->metadata,
            $order->shipping_method_id ?? null,
            (string) $order->delivery_type,
            (float) ($order->shipping_total ?? 0),
        );

        $requestedMethodId = trim((string) ($data['method_id'] ?? ''));
        if ($requestedMethodId !== '') {
            $shippingChoice['method_id'] = $requestedMethodId;
        } else {
            $storedSelected = Arr::get($metadata, 'shipping.boxtal_selected');
            if (is_array($storedSelected) && is_string($storedSelected['method_id'] ?? null) && $storedSelected['method_id'] !== '') {
                $shippingChoice = array_merge($shippingChoice, Arr::only($storedSelected, [
                    'method_id',
                    'name',
                    'operator',
                    'service',
                    'service_raw',
                    'collecte',
                    'type',
                    'delay',
                    'price',
                    'price_original',
                    'is_free',
                ]));
            }
        }

        $selected = null;
        $offers = [];
        $pref = $this->inferBoxtalPreference($shippingChoice);

        // Toujours recoter : /order doit recevoir operator/service (et collecte) compatibles avec les cotations du moment.
        if (! $useDevMode) {
            $offers = $boxtal->quotes($from, $to, $packages, $contentCode)->values()->all();
            $selected = $this->selectMatchingBoxtalOffer($pref, $offers);
        }

        if (! $selected) {
            if (! $useDevMode) {
                if (empty($offers)) {
                    return response()->json([
                        'message' => 'Boxtal: aucune offre disponible (cotation vide).',
                        'debug' => [
                            'from' => $from,
                            'to' => $to,
                            'packages_count' => count($packages),
                            'method_id' => (string) ($shippingChoice['method_id'] ?? ''),
                            'type' => (string) ($pref['type'] ?? ''),
                            'operator' => (string) ($pref['operator'] ?? ''),
                        ],
                    ], 502);
                }

                return response()->json([
                    'message' => 'Aucune offre Boxtal correspondante pour cette commande.',
                    'debug' => [
                        'preference' => $pref,
                        'offers_count' => count($offers),
                        'sample_offers' => array_slice(array_map(function ($o) {
                            return [
                                'method_id' => $o['method_id'] ?? null,
                                'name' => $o['name'] ?? null,
                                'type' => $o['type'] ?? null,
                                'operator' => $o['operator'] ?? null,
                                'service' => $o['service'] ?? null,
                                'price' => $o['price'] ?? null,
                            ];
                        }, $offers), 0, 6),
                    ],
                ], 422);
            }
            $selected = [
                'method_id' => (string) ($shippingChoice['method_id'] ?? 'boxtal:UNKNOWN:UNKNOWN'),
                'name' => (string) ($shippingChoice['name'] ?? 'Boxtal (dev)'),
                'price' => (float) ($shippingChoice['price'] ?? 0),
                'delay' => $shippingChoice['delay'] ?? null,
                'collecte' => $shippingChoice['collecte'] ?? null,
                'type' => (string) ($shippingChoice['type'] ?? 'shipping'),
                'origin' => 'dev',
                'operator' => (string) ($shippingChoice['operator'] ?? ''),
                'service' => (string) ($shippingChoice['service'] ?? ''),
            ];
        }

        $shipperAddress = trim((string) ($fromCfg['address'] ?? ''));
        $shipperFirst = trim((string) ($fromCfg['first_name'] ?? ''));
        $shipperLast = trim((string) ($fromCfg['last_name'] ?? ''));
        $shipperEmail = trim((string) ($fromCfg['email'] ?? ''));
        $shipperPhone = trim((string) ($fromCfg['phone'] ?? ''));

        if (($shipperAddress === '' || $shipperFirst === '' || $shipperLast === '' || $shipperEmail === '' || $shipperPhone === '') && ! $useDevMode) {
            $missing = [];
            $missingEnv = [];
            if ($shipperAddress === '') {
                $missing[] = 'address';
                $missingEnv[] = 'BOXTAL_FROM_ADDRESS';
            }
            if ($shipperFirst === '') {
                $missing[] = 'first_name';
                $missingEnv[] = 'BOXTAL_FROM_FIRST_NAME';
            }
            if ($shipperLast === '') {
                $missing[] = 'last_name';
                $missingEnv[] = 'BOXTAL_FROM_LAST_NAME';
            }
            if ($shipperEmail === '') {
                $missing[] = 'email';
                $missingEnv[] = 'BOXTAL_FROM_EMAIL';
            }
            if ($shipperPhone === '') {
                $missing[] = 'phone';
                $missingEnv[] = 'BOXTAL_FROM_PHONE';
            }

            return response()->json([
                'message' => 'Boxtal: expéditeur incomplet (adresse/nom/email/téléphone). Renseigne les variables manquantes dans helix/backend/.env.',
                'missing' => $missing,
                'missing_env' => $missingEnv,
            ], 422);
        }

        $recipientEmail = (string) Arr::get($shippingAddress, 'email', Arr::get($billing, 'email', Arr::get($metadata, 'guest_email', '')));
        $recipientPhone = (string) Arr::get($shippingAddress, 'phone', Arr::get($billing, 'phone', ''));
        $recipientFirst = trim((string) Arr::get($shippingAddress, 'first_name', ''));
        $recipientLast = trim((string) Arr::get($shippingAddress, 'last_name', ''));

        if (($recipientFirst === '' || $recipientLast === '' || trim($recipientEmail) === '' || trim($recipientPhone) === '') && ! $useDevMode) {
            $missing = [];
            if ($recipientFirst === '') $missing[] = 'first_name';
            if ($recipientLast === '') $missing[] = 'last_name';
            if (trim($recipientEmail) === '') $missing[] = 'email';
            if (trim($recipientPhone) === '') $missing[] = 'phone';
            if (is_array($metadata['boxtal_buy'] ?? null)) {
                $metadata['boxtal_buy']['in_progress'] = false;
            }
            $this->ecommerce()->table('orders')->where('id', $id)->update([
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return response()->json([
                'message' => 'Adresse de livraison incomplète.',
                'missing' => $missing,
            ], 422);
        }

        $shipper = [
            'country' => (string) ($fromCfg['country'] ?? 'FR'),
            'zip' => $fromZip !== '' ? $fromZip : '00000',
            'city' => $fromCity !== '' ? $fromCity : 'DEV',
            'type' => (string) ($fromCfg['type'] ?? 'entreprise'),
            'address' => $shipperAddress !== '' ? $shipperAddress : 'DEV',
            'company' => (string) ($fromCfg['company'] ?? ''),
            'first_name' => $shipperFirst !== '' ? $shipperFirst : 'DEV',
            'last_name' => $shipperLast !== '' ? $shipperLast : 'DEV',
            'email' => $shipperEmail !== '' ? $shipperEmail : 'dev@invalid.local',
            'phone' => $shipperPhone !== '' ? $shipperPhone : '0000000000',
        ];

        $recipient = [
            'country' => $country !== '' ? $country : 'FR',
            'zip' => $zip !== '' ? $zip : '00000',
            'city' => $city !== '' ? $city : 'DEV',
            'type' => $toType !== '' ? $toType : 'particulier',
            'address' => $recipientAddress !== '' ? $recipientAddress : 'DEV',
            'company' => (string) Arr::get($shippingAddress, 'company', ''),
            'first_name' => $recipientFirst !== '' ? $recipientFirst : 'Client',
            'last_name' => $recipientLast !== '' ? $recipientLast : 'DEV',
            'email' => trim($recipientEmail) !== '' ? trim($recipientEmail) : 'client@invalid.local',
            'phone' => trim($recipientPhone) !== '' ? trim($recipientPhone) : '0000000000',
        ];

        $params = [
            'operator' => (string) ($selected['operator'] ?? ''),
            // Certains codes service sont sensibles à la casse : préférer la version raw si disponible.
            'service' => (string) ($selected['service_raw'] ?? ($selected['service'] ?? '')),
            // Boxtal API (v1): /order attend `collecte`.
            'collecte' => $this->boxtalCollecteDate($selected['collecte'] ?? null),
            'assurance.selection' => 'false',
            'colis.description' => 'Pièces détachées',
            'reference_externe' => (string) ($order->number ?? $id),
        ];

        if ($useDevMode) {
            $emcRef = 'DEV-'.strtoupper(Str::random(10));
            $carrierRef = 'DEVTRACK-'.strtoupper(Str::random(8));

            $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Bordereau (DEV)</title>'
                .'<meta name="viewport" content="width=device-width,initial-scale=1">'
                .'<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;padding:24px;color:#0f172a}'
                .'.card{border:1px solid #e2e8f0;border-radius:16px;padding:18px;background:#fff;max-width:720px}'
                .'.muted{color:#64748b;font-size:13px}.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}'
                .'.pill{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:800;font-size:12px}'
                .'.title{font-size:20px;font-weight:900;margin:10px 0}</style></head><body>'
                .'<div class="card">'
                .'<div class="row"><div><span class="pill">DEV - Ne pas expedier</span></div><div class="muted">'.now()->format('d/m/Y H:i').'</div></div>'
                .'<div class="title">Bon d\'envoi (simulation)</div>'
                .'<div class="muted">Commande : <strong>'.e((string) $order->number).'</strong></div>'
                .'<div style="margin-top:12px" class="row">'
                .'<div><div class="muted">Transporteur</div><div style="font-weight:900">'.e((string) ($selected['name'] ?? 'Boxtal')).'</div></div>'
                .'<div><div class="muted">Réf. Boxtal</div><div style="font-weight:900">'.e($emcRef).'</div></div>'
                .'<div><div class="muted">Tracking</div><div style="font-weight:900">'.e($carrierRef).'</div></div>'
                .'</div>'
                .'<div style="margin-top:16px" class="muted">Apercu Helix pour valider le flux (achat/impression) sans paiement Boxtal.</div>'
                .'<div style="margin-top:16px"><button onclick="window.print()" style="border:none;background:#0ea5e9;color:#fff;padding:10px 14px;border-radius:12px;font-weight:900;cursor:pointer">Imprimer</button></div>'
                .'</div></body></html>';

            $labelUrl = 'data:text/html;charset=utf-8,'.rawurlencode($html);
        } else {
            $orderResult = $boxtal->createOrder($shipper, $recipient, $packages, $params, $contentCode);
            if (! ($orderResult['ok'] ?? false)) {
                try {
                    $metadata['boxtal_buy']['in_progress'] = false;
                    $metadata = $this->appendBoxtalLog($metadata, 'buy_failed', [
                        'order_id' => (int) $id,
                        'operator' => $params['operator'] ?? null,
                        'service' => $params['service'] ?? null,
                        'collecte' => $params['collecte'] ?? null,
                        'status' => (int) ($orderResult['status'] ?? 0),
                        'errors' => $orderResult['errors'] ?? [],
                    ]);
                    $this->ecommerce()->table('orders')->where('id', $id)->update([
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                } catch (\Throwable) {
                    // ignore log failures
                }

                $boxtalCode = (string) ($orderResult['boxtal_code'] ?? '');
                $status = (int) ($orderResult['status'] ?? 502);
                if ($status === 403 && $boxtalCode === 'access_denied') {
                    $metadata['boxtal_buy']['in_progress'] = false;
                    $this->ecommerce()->table('orders')->where('id', $id)->update([
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                    return response()->json([
                        'message' => "Boxtal: accès refusé (access_denied). Le endpoint /order nécessite un compte Boxtal en paiement différé par prélèvement (et un plafond non dépassé).",
                        'errors' => $orderResult['errors'] ?? [],
                        'debug' => [
                            'operator' => $params['operator'] ?? null,
                            'service' => $params['service'] ?? null,
                            'collecte' => $params['collecte'] ?? null,
                            'boxtal_base_url' => (string) config('services.boxtal.base_url', ''),
                        ],
                    ], 403);
                }
                return response()->json([
                    'message' => 'Boxtal: impossible de créer le bordereau.',
                    'errors' => $orderResult['errors'] ?? [],
                    'debug' => [
                        'operator' => $params['operator'] ?? null,
                        'service' => $params['service'] ?? null,
                        'collecte' => $params['collecte'] ?? null,
                        'method_id' => (string) ($shippingChoice['method_id'] ?? ''),
                        'packages_count' => count($packages),
                        'from_zip' => (string) ($from['zip'] ?? ''),
                        'to_zip' => (string) ($to['zip'] ?? ''),
                        'boxtal_base_url' => (string) config('services.boxtal.base_url', ''),
                        'boxtal_auth' => [
                            'has_key' => filled(config('services.boxtal.key')),
                            'has_login' => filled(config('services.boxtal.login')),
                        ],
                    ],
                ], 502);
            }

            $emcRef = (string) ($orderResult['reference'] ?? '');
            $info = $emcRef !== '' ? $boxtal->getOrderInformations($emcRef) : ['ok' => false];

            $labelUrl = (string) ($info['label_url'] ?? '');
            if ($labelUrl === '') {
                $labels = array_values(array_filter((array) ($info['labels'] ?? ($orderResult['labels'] ?? []))));
                $labelUrl = (string) ($labels[0] ?? '');
            }
            $carrierRef = (string) ($info['carrier_reference'] ?? '');
        }

        $shippingLabel = [
            'provider' => 'boxtal',
            'method_id' => (string) ($selected['method_id'] ?? ($shippingChoice['method_id'] ?? null)),
            'operator' => (string) ($selected['operator'] ?? null),
            'service' => (string) ($selected['service'] ?? null),
            'name' => (string) ($selected['name'] ?? null),
            'price' => (float) ($selected['price'] ?? 0),
            'delay' => $selected['delay'] ?? null,
            'emc_ref' => $emcRef,
            'state' => $useDevMode ? 'dev' : (string) ($info['state'] ?? ''),
            'carrier_reference' => $carrierRef !== '' ? $carrierRef : null,
            'label_url' => $labelUrl !== '' ? $labelUrl : null,
            'labels' => $useDevMode ? [] : array_values(array_filter((array) ($info['labels'] ?? ($orderResult['labels'] ?? [])))),
            'is_dev' => $useDevMode,
            'created_at' => now()->toISOString(),
        ];

        $shipments = Arr::get($metadata, 'shipments', []);
        if (! is_array($shipments)) {
            $shipments = [];
        }

        $trackingNumber = $carrierRef !== '' ? $carrierRef : ($emcRef !== '' ? $emcRef : null);
        $carrierName = $this->carrierNameFromOperator((string) ($selected['operator'] ?? ''));
        if ($trackingNumber) {
            $shipments[] = [
                'carrier_name' => $carrierName,
                'tracking_number' => $trackingNumber,
                'created_at' => now()->toISOString(),
                'source' => 'boxtal',
                'emc_ref' => $emcRef,
            ];
        }

        $metadata['shipping_label'] = $shippingLabel;
        $metadata['shipments'] = $shipments;
        $metadata['boxtal_buy']['in_progress'] = false;
        $metadata = $this->appendBoxtalLog($metadata, 'buy_ok', [
            'order_id' => (int) $id,
            'operator' => (string) ($shippingLabel['operator'] ?? ''),
            'service' => (string) ($shippingLabel['service'] ?? ''),
            'emc_ref' => (string) ($shippingLabel['emc_ref'] ?? ''),
            'carrier_reference' => (string) ($shippingLabel['carrier_reference'] ?? ''),
            'price' => (float) ($shippingLabel['price'] ?? 0),
        ]);

        $updates = [
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        if ($trackingNumber) {
            $updates['carrier_name'] = $carrierName;
            $updates['tracking_number'] = $trackingNumber;
        }

        if (! empty($data['set_status'])) {
            $fromStatus = (string) $order->status;
            $toStatus = (string) $data['set_status'];
            if ($fromStatus !== $toStatus) {
                $updates['status'] = $toStatus;
                $this->ecommerce()->table('order_status_histories')->insert([
                    'order_id' => $id,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'user_id' => null,
                    'comment' => 'Bordereau Boxtal acheté via Livreo (Helix)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->ecommerce()->table('orders')->where('id', $id)->update($updates);

        $this->safeAudit([
            'actor_user_id' => $request->user()?->id,
            'entity_type' => 'order',
            'entity_id' => (string) $id,
            'action' => 'boxtal_label_bought',
            'payload' => [
                'shipping_label' => Arr::only($shippingLabel, [
                    'method_id',
                    'operator',
                    'service',
                    'emc_ref',
                    'carrier_reference',
                    'label_url',
                    'price',
                ]),
                'set_status' => $data['set_status'] ?? null,
            ],
        ]);

        return response()->json([
            'status' => 'ok',
            'selected' => $selected,
            'shipping_label' => $shippingLabel,
            'carrier_name' => $carrierName,
            'tracking_number' => $trackingNumber,
        ]);
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    public function boxtalLabel(int $id, Request $request): Response
    {
        $order = $this->ecommerce()->table('orders')->where('id', $id)->first();
        if (! $order) {
            abort(404);
        }

        $metadata = $this->decodeJson($order->metadata);
        $shippingLabel = Arr::get($metadata, 'shipping_label', []);
        if (! is_array($shippingLabel)) {
            $shippingLabel = [];
        }

        $labelUrl = trim((string) ($shippingLabel['label_url'] ?? ''));
        if ($labelUrl === '') {
            $labels = array_values(array_filter((array) ($shippingLabel['labels'] ?? [])));
            $labelUrl = trim((string) ($labels[0] ?? ''));
        }

        // Si l'URL n'a pas été stockée, on tente de la récupérer via la référence Boxtal.
        if ($labelUrl === '') {
            $emcRef = trim((string) ($shippingLabel['emc_ref'] ?? ''));
            if ($emcRef !== '') {
                try {
                    $boxtal = BoxtalService::make();
                    $info = $boxtal->getOrderInformations($emcRef);
                    if (($info['ok'] ?? false) === true) {
                        $labelUrl = trim((string) ($info['label_url'] ?? ''));
                        $labels = array_values(array_filter((array) ($info['labels'] ?? [])));
                        if ($labelUrl === '' && ! empty($labels)) {
                            $labelUrl = trim((string) ($labels[0] ?? ''));
                        }
                        if ($labelUrl !== '' || ! empty($labels)) {
                            $shippingLabel['label_url'] = $labelUrl !== '' ? $labelUrl : null;
                            $shippingLabel['labels'] = $labels;
                            $metadata['shipping_label'] = $shippingLabel;
                            $this->ecommerce()->table('orders')->where('id', $id)->update([
                                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]);
                        }
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        if ($labelUrl === '') {
            return response()->json(['message' => "Aucun bordereau disponible pour cette commande."], 404);
        }

        // Mode DEV : bordereau HTML stocké en data URL.
        if (str_starts_with($labelUrl, 'data:text/html')) {
            $idx = strpos($labelUrl, ',');
            $encoded = $idx !== false ? substr($labelUrl, $idx + 1) : '';
            $html = '';
            try {
                $html = $encoded !== '' ? rawurldecode($encoded) : '';
            } catch (\Throwable) {
                $html = '';
            }
            return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
        }

        $boxtal = BoxtalService::make();
        $doc = $boxtal->downloadDocument($labelUrl);
        if (! ($doc['ok'] ?? false)) {
            return response()->json([
                'message' => 'Impossible de récupérer le bordereau Boxtal.',
                'errors' => $doc['errors'] ?? [],
            ], 502);
        }

        $contentType = (string) ($doc['content_type'] ?? 'application/pdf');
        $filename = 'bordereau-'.$order->number.'.pdf';

        try {
            $metadata = $this->appendBoxtalLog($metadata, 'label_opened', [
                'order_id' => (int) $id,
                'by_user_id' => $request->user()?->id,
                'emc_ref' => (string) ($shippingLabel['emc_ref'] ?? ''),
            ]);
            $this->ecommerce()->table('orders')->where('id', $id)->update([
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {
            // ignore
        }

        return response($doc['body'], 200)
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(5, min(50, (int) $request->integer('per_page', 20)));
        $search = trim((string) $request->string('q', ''));
        $status = $request->string('status')->toString();
        $paymentStatus = $request->string('payment_status')->toString();
        $deliveryType = $request->string('delivery_type')->toString();
        $needsLabel = (bool) $request->boolean('needs_label');
        $mobileSentrixUnassigned = false;
        $mobileSentrixInTransit = false;
        $awaitingCustomerShipment = false;
        if ($status === 'mobilesentrix_unassigned') {
            $status = '';
            $mobileSentrixUnassigned = true;
        }
        if ($status === 'awaiting_assignment') {
            $status = '';
            $paymentStatus = 'paid';
            $mobileSentrixUnassigned = true;
        }
        if ($status === 'ms_in_transit') {
            $status = '';
            $paymentStatus = 'paid';
            $mobileSentrixInTransit = true;
        }
        if ($status === 'awaiting_customer_shipment') {
            $status = '';
            $paymentStatus = 'paid';
            $awaitingCustomerShipment = true;
            $needsLabel = true;
        }
        if ($status === 'pending_payment') {
            $status = '';
            $paymentStatus = 'pending';
        }
        if ($status === 'paid') {
            $status = '';
            $paymentStatus = 'paid';
        }

        $query = $this->ecommerce()
            ->table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->select([
                'orders.id',
                'orders.number',
                'orders.status',
                'orders.payment_status',
                'orders.delivery_type',
                'orders.shipping_method_id',
                'orders.total_ttc',
                'orders.shipping_total',
                'orders.placed_at',
                'orders.tracking_number',
                'orders.carrier_name',
                'orders.billing_address',
                'orders.shipping_address',
                'orders.metadata',
                'users.email as user_email',
            ])
            ->orderByDesc('orders.placed_at');

        if ($status !== '') {
            $query->where('orders.status', $status);
        }
        if ($paymentStatus !== '') {
            $query->where('orders.payment_status', $paymentStatus);
        }
        if ($deliveryType !== '') {
            $query->where('orders.delivery_type', $deliveryType);
        }

        if ($mobileSentrixUnassigned && $this->hasLivreoTables()) {
            $latestIds = SupplierOrder::query()
                ->whereIn('supplier', ['mobilesentrix', 'mobile_sentrix'])
                ->selectRaw('MAX(id) as id')
                ->groupBy('order_id')
                ->pluck('id')
                ->filter()
                ->values()
                ->all();

            if (empty($latestIds)) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                ]);
            }

            $unassignedOrderIds = SupplierOrder::query()
                ->whereIn('id', $latestIds)
                ->where(function ($q): void {
                    $q->whereNull('supplier_order_number')
                        ->orWhere('supplier_order_number', '');
                })
                ->pluck('order_id')
                ->filter()
                ->values()
                ->all();

            if (empty($unassignedOrderIds)) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                ]);
            }

            $query->whereIn('orders.id', $unassignedOrderIds);
        }

        if ($mobileSentrixInTransit && $this->hasLivreoTables()) {
            $latestIds = SupplierOrder::query()
                ->whereIn('supplier', ['mobilesentrix', 'mobile_sentrix'])
                ->selectRaw('MAX(id) as id')
                ->groupBy('order_id')
                ->pluck('id')
                ->filter()
                ->values()
                ->all();

            if (empty($latestIds)) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                ]);
            }

            $inTransitOrderIds = SupplierOrder::query()
                ->whereIn('id', $latestIds)
                ->where(function ($q): void {
                    $q->whereNotNull('supplier_order_number')
                        ->where('supplier_order_number', '!=', '');
                })
                ->where('status', '!=', 'received')
                ->pluck('order_id')
                ->filter()
                ->values()
                ->all();

            if (empty($inTransitOrderIds)) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                ]);
            }

            $query->whereIn('orders.id', $inTransitOrderIds);
        }

        if ($awaitingCustomerShipment && $this->hasLivreoTables()) {
            $latestIds = SupplierOrder::query()
                ->whereIn('supplier', ['mobilesentrix', 'mobile_sentrix'])
                ->selectRaw('MAX(id) as id')
                ->groupBy('order_id')
                ->pluck('id')
                ->filter()
                ->values()
                ->all();

            if (! empty($latestIds)) {
                $receivedOrderIds = SupplierOrder::query()
                    ->whereIn('id', $latestIds)
                    ->where('status', 'received')
                    ->pluck('order_id')
                    ->filter()
                    ->values()
                    ->all();

                if (! empty($receivedOrderIds)) {
                    $query->whereIn('orders.id', $receivedOrderIds);
                } else {
                    return response()->json([
                        'data' => [],
                        'meta' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => $perPage,
                            'total' => 0,
                        ],
                    ]);
                }
            }
        }

        if ($needsLabel) {
            $query->whereRaw("(JSON_EXTRACT(orders.metadata, '$.shipping_label.emc_ref') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(orders.metadata, '$.shipping_label.emc_ref')) = '')");
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('orders.number', 'like', $like)
                    ->orWhere('orders.tracking_number', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.billing_address,'$.email')) LIKE ?", [$like]);
            });
        }

        $page = $query->paginate($perPage);

        $orderIds = collect($page->items())->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
        $mobileSentrixUnassignedByOrderId = [];
        $mobileSentrixLatestByOrderId = [];
        if ($this->hasLivreoTables() && ! empty($orderIds)) {
            $latestIds = SupplierOrder::query()
                ->whereIn('supplier', ['mobilesentrix', 'mobile_sentrix'])
                ->whereIn('order_id', $orderIds)
                ->selectRaw('MAX(id) as id')
                ->groupBy('order_id')
                ->pluck('id')
                ->filter()
                ->values()
                ->all();

            if (! empty($latestIds)) {
                $latest = SupplierOrder::query()
                    ->whereIn('id', $latestIds)
                    ->get(['order_id', 'supplier_order_number', 'status', 'supplier_tracking_number', 'supplier_shipped_at', 'received_at']);

                foreach ($latest as $row) {
                    $num = trim((string) $row->supplier_order_number);
                    $mobileSentrixUnassignedByOrderId[(int) $row->order_id] = ($num === '');
                    $mobileSentrixLatestByOrderId[(int) $row->order_id] = [
                        'supplier_order_number' => $num,
                        'status' => (string) $row->status,
                        'supplier_tracking_number' => $row->supplier_tracking_number ? trim((string) $row->supplier_tracking_number) : '',
                        'supplier_shipped_at' => $row->supplier_shipped_at ? (string) $row->supplier_shipped_at : '',
                        'received_at' => $row->received_at ? (string) $row->received_at : '',
                    ];
                }
            }
        }

        $orders = collect($page->items())->map(function ($row) use ($mobileSentrixLatestByOrderId) {
            $billing = $this->decodeJson($row->billing_address);
            $shipping = $this->decodeJson($row->shipping_address);
            $shippingChoice = $this->computeShippingChoice(
                $row->metadata,
                $row->shipping_method_id,
                (string) $row->delivery_type,
                (float) $row->shipping_total,
            );

            $email = $row->user_email ?: (string) Arr::get($billing, 'email', '');
            $meta = $this->decodeJson($row->metadata);
            $hasLabel = (string) Arr::get($meta, 'shipping_label.emc_ref', '') !== '';
            $inProgress = (bool) Arr::get($meta, 'boxtal_buy.in_progress', false);
            $logs = Arr::get($meta, 'boxtal_logs', []);
            $lastEvent = null;
            if (is_array($logs) && ! empty($logs)) {
                $lastEvent = end($logs);
                if (! is_array($lastEvent)) {
                    $lastEvent = null;
                }
            }

            $supplier = $mobileSentrixLatestByOrderId[(int) $row->id] ?? null;
            $supplierNum = $supplier ? (string) ($supplier['supplier_order_number'] ?? '') : '';
            $supplierStatus = $supplier ? (string) ($supplier['status'] ?? '') : '';

            $workflowStatus = 'paid';
            if ((string) $row->status === 'cancelled') {
                $workflowStatus = 'cancelled';
            } elseif ((string) $row->status === 'delivered') {
                $workflowStatus = 'delivered';
            } elseif ((string) $row->status === 'shipped') {
                $workflowStatus = 'shipped';
            } elseif ((string) $row->payment_status !== 'paid') {
                $workflowStatus = 'pending_payment';
            } elseif ($supplier !== null && $supplierNum === '') {
                $workflowStatus = 'awaiting_assignment';
            } elseif ($supplier !== null && $supplierNum !== '') {
                $workflowStatus = $supplierStatus === 'received' ? ($hasLabel ? 'paid' : 'awaiting_customer_shipment') : 'ms_in_transit';
            } else {
                $workflowStatus = $hasLabel ? 'paid' : 'awaiting_customer_shipment';
            }

            return [
                'id' => (int) $row->id,
                'number' => (string) $row->number,
                'status' => (string) $row->status,
                'payment_status' => (string) $row->payment_status,
                'workflow_status' => $workflowStatus,
                'delivery_type' => (string) $row->delivery_type,
                'placed_at' => (string) ($row->placed_at ?? ''),
                'total_ttc' => (float) $row->total_ttc,
                'shipping_total' => (float) $row->shipping_total,
                'customer_email' => $email,
                'customer_name' => trim((string) Arr::get($billing, 'first_name', '').' '.(string) Arr::get($billing, 'last_name', '')),
                'shipping_city' => (string) Arr::get($shipping, 'city', ''),
                'tracking_number' => $row->tracking_number ? (string) $row->tracking_number : null,
                'carrier_name' => $row->carrier_name ? (string) $row->carrier_name : null,
                'has_shipping_label' => $hasLabel,
                'boxtal_buy_in_progress' => $inProgress,
                'boxtal_last_event' => $lastEvent,
                'mobilesentrix_unassigned' => false,
                'shipping_choice' => [
                    'name' => Arr::get($shippingChoice, 'name'),
                    'operator' => Arr::get($shippingChoice, 'operator'),
                    'service' => Arr::get($shippingChoice, 'service'),
                    'type' => Arr::get($shippingChoice, 'type'),
                    'provider' => Arr::get($shippingChoice, 'provider'),
                    'method_id' => Arr::get($shippingChoice, 'method_id'),
                    'delay' => Arr::get($shippingChoice, 'delay'),
                    'price' => Arr::get($shippingChoice, 'price'),
                    'price_original' => Arr::get($shippingChoice, 'price_original'),
                    'is_free' => Arr::get($shippingChoice, 'is_free'),
                ],
            ];
        })->map(function (array $order) use ($mobileSentrixUnassignedByOrderId) {
            $order['mobilesentrix_unassigned'] = (bool) ($mobileSentrixUnassignedByOrderId[(int) $order['id']] ?? false);
            return $order;
        })->values();

        return response()->json([
            'data' => $orders,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $order = $this->ecommerce()->table('orders')->where('id', $id)->first();
        if (! $order) {
            abort(404);
        }

        $billing = $this->decodeJson($order->billing_address);
        $shipping = $this->decodeJson($order->shipping_address);
        $metadata = $this->decodeJson($order->metadata);
        $shippingChoice = $this->computeShippingChoice(
            $order->metadata,
            $order->shipping_method_id ?? null,
            (string) $order->delivery_type,
            (float) ($order->shipping_total ?? 0),
        );

        $userEmail = null;
        if ($order->user_id) {
            $userEmail = $this->ecommerce()->table('users')->where('id', $order->user_id)->value('email');
        }

        $schema = $this->ecommerce()->getSchemaBuilder();
        $supplierParts = ['p.supplier_reference', 'vp.supplier_reference'];
        if ($schema->hasColumn('order_items', 'supplier_reference')) {
            array_unshift($supplierParts, 'oi.supplier_reference');
        }

        $itemsQuery = $this->ecommerce()
            ->table('order_items as oi')
            ->leftJoin('products as p', 'oi.product_id', '=', 'p.id')
            ->leftJoin('product_variants as pv', 'oi.product_variant_id', '=', 'pv.id')
            ->leftJoin('products as vp', 'pv.product_id', '=', 'vp.id')
            ->where('oi.order_id', $id)
            ->select('oi.id', 'oi.name', 'oi.reference', 'oi.quantity', 'oi.total_ttc')
            ->selectRaw('COALESCE('.implode(', ', $supplierParts).') as supplier_reference');

        $itemsRaw = $itemsQuery->get();
        $legacyMap = [];

        foreach ($itemsRaw as $item) {
            if (! empty($item->supplier_reference)) {
                continue;
            }
            $reference = is_scalar($item->reference) ? trim((string) $item->reference) : '';
            if ($reference !== '' && preg_match('/^LEGACY-(\d+)$/', $reference, $matches)) {
                $legacyMap[(int) $item->id] = (int) $matches[1];
            }
        }

        $legacyRefs = collect();
        if ($legacyMap && $schema->hasTable('repairs')) {
            $legacyColumn = null;
            if ($schema->hasColumn('repairs', 'supplier_ref')) {
                $legacyColumn = 'supplier_ref';
            } elseif ($schema->hasColumn('repairs', 'supplier_reference')) {
                $legacyColumn = 'supplier_reference';
            }

            if ($legacyColumn) {
                $legacyRefs = $this->ecommerce()
                    ->table('repairs')
                    ->whereIn('id', array_values($legacyMap))
                    ->pluck($legacyColumn, 'id');
            }
        }

        $items = $itemsRaw
            ->map(function ($item) use ($legacyMap, $legacyRefs) {
                $supplierRef = is_scalar($item->supplier_reference) ? trim((string) $item->supplier_reference) : '';
                if ($supplierRef === '' && isset($legacyMap[(int) $item->id])) {
                    $legacyRef = $legacyRefs->get($legacyMap[(int) $item->id]);
                    if (is_string($legacyRef)) {
                        $supplierRef = trim($legacyRef);
                    }
                }

                return [
                    'id' => (int) $item->id,
                    'name' => (string) $item->name,
                    'reference' => $item->reference ? (string) $item->reference : null,
                    'supplier_reference' => $supplierRef !== '' ? $supplierRef : null,
                    'quantity' => (int) $item->quantity,
                    'total_ttc' => (float) $item->total_ttc,
                ];
            })->values();

        $payments = $this->ecommerce()
            ->table('payments')
            ->where('order_id', $id)
            ->orderByDesc('id')
            ->get()
            ->map(function ($p) {
                $provider = (string) $p->provider;
                $transactionRef = $p->transaction_reference ? (string) $p->transaction_reference : null;
                $paymentIntent = null;
                if (strtolower($provider) === 'stripe') {
                    if ($transactionRef && Str::startsWith($transactionRef, 'pi_')) {
                        $paymentIntent = $transactionRef;
                    } else {
                        $payload = $this->decodeJson($p->payload);
                        $candidate = Arr::get($payload, 'payment_intent.id')
                            ?? Arr::get($payload, 'checkout_session.payment_intent')
                            ?? Arr::get($payload, 'session.payment_intent');
                        if (is_string($candidate) && $candidate !== '') {
                            $paymentIntent = $candidate;
                        }
                    }
                }

                return [
                    'provider' => $provider,
                    'method' => (string) $p->method,
                    'status' => (string) $p->status,
                    'transaction_reference' => $transactionRef,
                    'payment_intent' => $paymentIntent,
                    'amount' => (float) $p->amount,
                    'currency' => (string) $p->currency,
                    'created_at' => (string) $p->created_at,
                ];
            })->values();

        $supplierOrder = null;
        if (Schema::hasTable('livreo_supplier_orders')) {
            $supplierOrder = SupplierOrder::query()
                ->with('items')
                ->where('order_id', $id)
                ->latest('id')
                ->first();
        }

        return response()->json([
            'order' => [
                'id' => (int) $order->id,
                'number' => (string) $order->number,
                'status' => (string) $order->status,
                'payment_status' => (string) $order->payment_status,
                'delivery_type' => (string) $order->delivery_type,
                'placed_at' => (string) $order->placed_at,
                'total_ttc' => (float) $order->total_ttc,
                'shipping_total' => (float) $order->shipping_total,
                'tracking_number' => $order->tracking_number ? (string) $order->tracking_number : null,
                'carrier_name' => $order->carrier_name ? (string) $order->carrier_name : null,
                'customer_email' => $userEmail ?: (string) Arr::get($billing, 'email', ''),
                'billing_address' => $billing,
                'shipping_address' => $shipping,
                'metadata' => $metadata,
                'shipping_choice' => $shippingChoice,
                'shop_links' => [
                    'admin' => rtrim((string) config('livreo.shop_base_url'), '/').'/admin/commandes/'.$order->number,
                    'customer' => rtrim((string) config('livreo.shop_base_url'), '/').'/mon-compte/commandes/'.$order->number,
                ],
            ],
            'items' => $items,
            'payments' => $payments,
            'supplier_order' => $supplierOrder,
        ]);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $order = $this->ecommerce()->table('orders')->where('id', $id)->first();
        if (! $order) {
            abort(404);
        }

        $updates = [];
        if (array_key_exists('internal_note', $data)) {
            $updates['internal_note'] = $data['internal_note'];
        }

        if (! empty($data['status'])) {
            $from = (string) $order->status;
            $to = (string) $data['status'];
            if ($from !== $to) {
                $updates['status'] = $to;
                $this->ecommerce()->table('order_status_histories')->insert([
                    'order_id' => $id,
                    'from_status' => $from,
                    'to_status' => $to,
                    'user_id' => null,
                    'comment' => 'Mise à jour via Livreo (Helix)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (! empty($updates)) {
            $updates['updated_at'] = now();
            $this->ecommerce()->table('orders')->where('id', $id)->update($updates);
        }

        $this->safeAudit([
            'actor_user_id' => $request->user()?->id,
            'entity_type' => 'order',
            'entity_id' => (string) $id,
            'action' => 'updated',
            'payload' => [
                'updates' => $updates,
            ],
        ]);

        return response()->json(['status' => 'ok']);
    }
}
