# Livreo — Modèle de données (proposition)

> But : ne pas casser l’e-commerce public et limiter les migrations. On privilégie des ajouts simples (tables Livreo) + enrichissement JSON `metadata` si nécessaire.

## 1) Données e-commerce existantes (à exploiter)

### Orders
- `orders.number`
- `orders.status` (statut global)
- `orders.payment_status`
- `orders.tracking_number` + `orders.carrier_name` (si déjà utilisés)
- `orders.billing_address` / `orders.shipping_address` (JSON)
- `orders.metadata` (JSON)

### Point critique : mode de livraison (Boxtal)
Actuellement le checkout peut stocker un identifiant "method_id" non relationnel (ex: `manual:*` / `boxtal:*`).
Pour Livreo, il faut que la commande persiste le choix client :
- `orders.metadata.shipping.method_id` (ex: `boxtal:...` / `manual:...`)
- `orders.metadata.shipping.name`
- `orders.metadata.shipping.operator` (COLI, CHRP, MONR…)
- `orders.metadata.shipping.service`
- `orders.delivery_type` (shipping/relay)
- `orders.shipping_total`

## 2) Tables Livreo (ajouts)

### 2.1 `livreo_shipments`
Permet multi-colis + historique.
- `id`
- `order_id`
- `carrier` (ex: Colissimo, Chronopost, Mondial Relay)
- `tracking_number`
- `tracking_url` (optionnel)
- `status` (created/sent/delivered/exception)
- `notified_at` (date d’envoi au client)
- timestamps

### 2.2 `livreo_supplier_orders`
Commande fournisseur liée à une commande client.
- `id`
- `order_id`
- `supplier` (ex: mobilesentrix)
- `supplier_order_number`
- `status` (to_order/ordered/received/problem)
- `ordered_at`, `received_at`
- `invoice_path` (upload)
- `notes` (interne)
- timestamps

### 2.3 `livreo_supplier_order_items`
Lignes fournisseur (mapping minimal).
- `id`
- `supplier_order_id`
- `product_variant_id` (ou `order_item_id`)
- `supplier_sku` / `supplier_ref`
- `quantity`
- `unit_cost` (optionnel)
- timestamps

### 2.4 `livreo_audit_events`
Audit “append-only”.
- `id`
- `actor_user_id` (helix_user)
- `entity_type` (order/shipment/supplier_order/ticket)
- `entity_id`
- `action` (status_changed, tracking_added, note_added…)
- `payload` (JSON)
- timestamps

## 3) SAV
Si un système SAV e-commerce existe déjà (ex: `rma_requests`), Livreo le consomme.
Sinon : ajouter tables `livreo_tickets`, `livreo_ticket_messages`, `livreo_ticket_attachments` (V2).

