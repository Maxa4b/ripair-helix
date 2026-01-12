<?php

namespace App\Http\Controllers\Livreo;

use App\Models\Livreo\SupplierOrder;
use App\Models\Livreo\SupplierOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierOrderController extends LivreoBaseController
{
    public function store(int $orderId, Request $request): JsonResponse
    {
        abort_unless($this->hasLivreoTables(), 503, 'Livreo non installé (migrations manquantes).');

        $data = $request->validate([
            'supplier' => ['nullable', 'string', 'max:60'],
            'supplier_order_number' => ['nullable', 'string', 'max:190'],
            'status' => ['nullable', 'in:to_order,ordered,received,problem'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['nullable', 'array'],
            'items.*.order_item_id' => ['nullable', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:190'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $supplierOrder = null;

        DB::transaction(function () use ($orderId, $data, $request, &$supplierOrder): void {
            $supplierOrder = SupplierOrder::create([
                'order_id' => $orderId,
                'supplier' => $data['supplier'] ?? 'mobilesentrix',
                'supplier_order_number' => $data['supplier_order_number'] ?? null,
                'status' => $data['status'] ?? 'to_order',
                'notes' => $data['notes'] ?? null,
                'ordered_at' => ($data['status'] ?? null) === 'ordered' ? now() : null,
                'received_at' => ($data['status'] ?? null) === 'received' ? now() : null,
            ]);

            foreach (($data['items'] ?? []) as $item) {
                SupplierOrderItem::create([
                    'supplier_order_id' => $supplierOrder->id,
                    'order_item_id' => $item['order_item_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'supplier_sku' => $item['supplier_sku'] ?? null,
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'unit_cost' => $item['unit_cost'] ?? null,
                ]);
            }

            $this->safeAudit([
                'actor_user_id' => $request->user()?->id,
                'entity_type' => 'supplier_order',
                'entity_id' => (string) $supplierOrder->id,
                'action' => 'created',
                'payload' => [
                    'order_id' => $orderId,
                ],
            ]);
        });

        return response()->json(['supplier_order' => $supplierOrder->load('items')], 201);
    }

    public function update(int $supplierOrderId, Request $request): JsonResponse
    {
        abort_unless($this->hasLivreoTables(), 503, 'Livreo non installé (migrations manquantes).');

        $data = $request->validate([
            'supplier_order_number' => ['nullable', 'string', 'max:190'],
            'status' => ['nullable', 'in:to_order,ordered,received,problem'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $supplierOrder = SupplierOrder::findOrFail($supplierOrderId);

        $updates = [];
        foreach (['supplier_order_number', 'status', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (($updates['status'] ?? null) === 'ordered' && ! $supplierOrder->ordered_at) {
            $updates['ordered_at'] = now();
        }
        if (($updates['status'] ?? null) === 'received' && ! $supplierOrder->received_at) {
            $updates['received_at'] = now();
        }

        $supplierOrder->update($updates);

        $this->safeAudit([
            'actor_user_id' => $request->user()?->id,
            'entity_type' => 'supplier_order',
            'entity_id' => (string) $supplierOrder->id,
            'action' => 'updated',
            'payload' => [
                'updates' => $updates,
            ],
        ]);

        return response()->json(['supplier_order' => $supplierOrder->refresh()->load('items')]);
    }
}
