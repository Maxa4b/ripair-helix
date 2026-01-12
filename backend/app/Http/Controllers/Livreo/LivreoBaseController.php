<?php

namespace App\Http\Controllers\Livreo;

use App\Http\Controllers\Controller;
use App\Models\Livreo\AuditEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class LivreoBaseController extends Controller
{
    protected function ecommerce(): ConnectionInterface
    {
        return DB::connection((string) config('livreo.ecommerce_connection', 'ecommerce'));
    }

    protected function hasLivreoTables(): bool
    {
        // Tables internes de Helix (connexion par défaut).
        return Schema::hasTable('livreo_supplier_orders')
            && Schema::hasTable('livreo_supplier_order_items')
            && Schema::hasTable('livreo_audit_events');
    }

    protected function safeAudit(array $payload): void
    {
        try {
            if (! Schema::hasTable('livreo_audit_events')) {
                return;
            }
            AuditEvent::create($payload);
        } catch (QueryException) {
            // Ne bloque pas l'UI si les migrations Livreo ne sont pas installées.
        }
    }

    protected function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
