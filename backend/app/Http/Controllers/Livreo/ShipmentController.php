<?php

namespace App\Http\Controllers\Livreo;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ShipmentController extends LivreoBaseController
{
    public function store(int $orderId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'carrier_name' => ['required', 'string', 'max:120'],
            'tracking_number' => ['required', 'string', 'max:190'],
            'set_status' => ['nullable', 'string'], // ex: shipped
        ]);

        $order = $this->ecommerce()->table('orders')->where('id', $orderId)->first();
        if (! $order) {
            abort(404);
        }

        $metadata = $this->decodeJson($order->metadata);
        $shipments = Arr::get($metadata, 'shipments', []);
        if (! is_array($shipments)) {
            $shipments = [];
        }

        $shipments[] = [
            'carrier_name' => $data['carrier_name'],
            'tracking_number' => $data['tracking_number'],
            'created_at' => now()->toISOString(),
        ];

        $metadata['shipments'] = $shipments;

        $updates = [
            'carrier_name' => $data['carrier_name'],
            'tracking_number' => $data['tracking_number'],
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        if (! empty($data['set_status'])) {
            $updates['status'] = $data['set_status'];
        }

        $this->ecommerce()->table('orders')->where('id', $orderId)->update($updates);

        if (! empty($data['set_status']) && (string) $order->status !== (string) $data['set_status']) {
            $this->ecommerce()->table('order_status_histories')->insert([
                'order_id' => $orderId,
                'from_status' => (string) $order->status,
                'to_status' => (string) $data['set_status'],
                'user_id' => null,
                'comment' => 'Mise à jour + tracking via Livreo (Helix)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->safeAudit([
            'actor_user_id' => $request->user()?->id,
            'entity_type' => 'order',
            'entity_id' => (string) $orderId,
            'action' => 'tracking_added',
            'payload' => [
                'carrier_name' => $data['carrier_name'],
                'tracking_number' => $data['tracking_number'],
                'set_status' => $data['set_status'] ?? null,
            ],
        ]);

        return response()->json(['status' => 'ok', 'shipments' => $shipments]);
    }
}
