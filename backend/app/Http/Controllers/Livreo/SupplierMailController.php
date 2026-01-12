<?php

namespace App\Http\Controllers\Livreo;

use App\Services\Suppliers\MobileSentrixMailSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\Livreo\SupplierMailEvent;
use App\Models\Livreo\SupplierOrder;

class SupplierMailController extends LivreoBaseController
{
    public function listMobileSentrixShipments(Request $request): JsonResponse
    {
        abort_unless($this->hasLivreoTables(), 503, 'Livreo non installé (migrations manquantes).');
        abort_unless(Schema::hasTable('livreo_supplier_mail_events'), 503, 'Livreo non installé (migrations manquantes).');

        $data = $request->validate([
            // Laravel "boolean" refuse les chaînes vides; on parse nous-même de façon tolérante.
            'only_unassigned' => ['nullable'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($data['limit'] ?? 120);
        $rawOnly = $data['only_unassigned'] ?? null;
        if ($rawOnly === '' || $rawOnly === null) {
            $onlyUnassigned = true;
        } else {
            $parsed = filter_var($rawOnly, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            abort_unless($parsed !== null, 422, 'Le paramètre only_unassigned doit être un booléen.');
            $onlyUnassigned = (bool) $parsed;
        }

        // Récupère les derniers événements par n° commande fournisseur (MobileSentrix).
        $events = SupplierMailEvent::query()
            ->where('supplier', 'mobilesentrix')
            ->whereNotNull('supplier_order_number')
            ->where('supplier_order_number', '!=', '')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit * 5)
            ->get([
                'id',
                'supplier_order_number',
                'carrier',
                'tracking_number',
                'received_at',
                'subject',
                'from_email',
            ]);

        $latestByNumber = [];
        foreach ($events as $e) {
            $num = trim((string) $e->supplier_order_number);
            if ($num === '') {
                continue;
            }
            if (! isset($latestByNumber[$num])) {
                $latestByNumber[$num] = $e;
            }
        }

        $numbers = array_keys($latestByNumber);

        $assigned = [];
        if ($onlyUnassigned && ! empty($numbers)) {
            $assigned = SupplierOrder::query()
                ->whereIn('supplier', ['mobilesentrix', 'mobile_sentrix'])
                ->whereIn('supplier_order_number', $numbers)
                ->pluck('supplier_order_number')
                ->map(fn ($v) => trim((string) $v))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $assignedSet = array_fill_keys($assigned, true);

        $items = [];
        foreach ($numbers as $num) {
            if ($onlyUnassigned && isset($assignedSet[$num])) {
                continue;
            }
            $e = $latestByNumber[$num];
            $items[] = [
                'supplier_order_number' => $num,
                'carrier' => $e->carrier ? (string) $e->carrier : null,
                'tracking_number' => $e->tracking_number ? (string) $e->tracking_number : null,
                'received_at' => $e->received_at ? $e->received_at->toIso8601String() : null,
                'subject' => $e->subject ? (string) $e->subject : null,
                'from_email' => $e->from_email ? (string) $e->from_email : null,
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return response()->json([
            'ok' => true,
            'only_unassigned' => $onlyUnassigned,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    public function assignMobileSentrixToSupplierOrder(int $supplierOrderId, Request $request): JsonResponse
    {
        abort_unless($this->hasLivreoTables(), 503, 'Livreo non installé (migrations manquantes).');
        abort_unless(Schema::hasTable('livreo_supplier_mail_events'), 503, 'Livreo non installé (migrations manquantes).');

        $data = $request->validate([
            'supplier_order_number' => ['required', 'string', 'max:190'],
        ]);

        $supplierOrderNumber = trim((string) $data['supplier_order_number']);
        abort_unless($supplierOrderNumber !== '', 422, 'Numéro MobileSentrix invalide.');
        abort_unless(preg_match('/^\d{5,}$/', $supplierOrderNumber) === 1, 422, 'Numéro MobileSentrix invalide.');

        $supplierOrder = SupplierOrder::findOrFail($supplierOrderId);

        // Récupère le dernier email connu pour ce n°.
        $event = SupplierMailEvent::query()
            ->where('supplier', 'mobilesentrix')
            ->where('supplier_order_number', $supplierOrderNumber)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        $updates = [
            'supplier_order_number' => $supplierOrderNumber,
        ];

        if ($event) {
            $updates = array_merge($updates, [
                'supplier_carrier' => $event->carrier,
                'supplier_tracking_number' => $event->tracking_number,
                'supplier_tracking_url' => $event->tracking_number ? $this->guessTrackingUrl($event->carrier, $event->tracking_number) : null,
                'supplier_shipped_at' => $event->received_at,
                'supplier_email_message_id' => $event->message_id,
                'supplier_email_subject' => $event->subject,
                'supplier_email_from' => $event->from_email,
                'supplier_email_received_at' => $event->received_at,
            ]);

            if ($supplierOrder->status === 'to_order') {
                $updates['status'] = 'ordered';
            }
            if (! $supplierOrder->ordered_at) {
                $updates['ordered_at'] = $event->received_at ?? now();
            }

            // Marque cet event comme rattaché (utile pour le filtre "à attribuer").
            $event->update([
                'matched_supplier_order_id' => (int) $supplierOrder->id,
                'matched_order_id' => (int) $supplierOrder->order_id,
            ]);
        }

        $supplierOrder->update($updates);

        $this->safeAudit([
            'actor_user_id' => $request->user()?->id,
            'entity_type' => 'supplier_order',
            'entity_id' => (string) $supplierOrder->id,
            'action' => 'assigned_mobilesentrix',
            'payload' => [
                'supplier_order_number' => $supplierOrderNumber,
                'event_id' => $event?->id,
            ],
        ]);

        return response()->json(['ok' => true, 'supplier_order' => $supplierOrder->refresh()->load('items')]);
    }

    public function syncMobileSentrix(Request $request): JsonResponse
    {
        abort_unless($this->hasLivreoTables(), 503, 'Livreo non installé (migrations manquantes).');

        $data = $request->validate([
            'since_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $service = MobileSentrixMailSyncService::make();
        $result = $service->sync(
            $data['since_days'] ?? null,
            (int) ($data['limit'] ?? 60),
        );

        $this->safeAudit([
            'actor_user_id' => $request->user()?->id,
            'entity_type' => 'supplier_mail',
            'entity_id' => 'mobilesentrix',
            'action' => 'sync',
            'payload' => [
                'since_days' => $data['since_days'] ?? null,
                'limit' => $data['limit'] ?? null,
                'summary' => $result,
            ],
        ]);

        if (! ($result['ok'] ?? false)) {
            $status = (int) ($result['status'] ?? 500);
            return response()->json($result, $status);
        }

        return response()->json($result);
    }

    private function guessTrackingUrl(?string $carrier, ?string $trackingNumber): ?string
    {
        $tn = trim((string) $trackingNumber);
        if ($tn === '') {
            return null;
        }
        $c = strtolower(trim((string) $carrier));
        $t = urlencode($tn);

        if (str_contains($c, 'fedex')) {
            return "https://www.fedex.com/fedextrack/?trknbr={$t}";
        }
        if (str_contains($c, 'ups')) {
            return "https://www.ups.com/track?loc=fr_FR&tracknum={$t}";
        }
        if (str_contains($c, 'dhl')) {
            return "https://www.dhl.com/fr-fr/home/tracking/tracking-express.html?submit=1&tracking-id={$t}";
        }

        return "https://www.google.com/search?q={$t}";
    }
}
