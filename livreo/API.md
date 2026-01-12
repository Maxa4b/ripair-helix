# Livreo — API Helix (proposition)

Objectif : ajouter un prefix `/livreo` dans `Helix/backend/routes/api.php` comme `/gaulois`.

## Endpoints (V1)

### Auth
- réutilise `auth:sanctum` existant (Helix).

### Commandes
- `GET /livreo/orders` (filtres + pagination)
- `GET /livreo/orders/{id}` (détails + relations)
- `PATCH /livreo/orders/{id}` (statuts opérationnels + notes internes)

### Expéditions
- `POST /livreo/orders/{id}/shipments` (ajout tracking)
- `PATCH /livreo/shipments/{id}` (édition/correction)
- `POST /livreo/shipments/{id}/notify` (renvoi notification client)

### Approvisionnement
- `POST /livreo/orders/{id}/supplier-orders`
- `PATCH /livreo/supplier-orders/{id}` (status + n° commande + dates)

### SAV
- `GET /livreo/tickets`
- `GET /livreo/tickets/{id}`
- `POST /livreo/tickets/{id}/messages` (+ upload)
- `PATCH /livreo/tickets/{id}` (statut)

## Notes d’intégration Boxtal
- Livreo lit le mode choisi depuis la commande (stocké dans `orders.metadata.shipping.*`).
- L’API Boxtal ne doit pas être appelée au moment d’afficher une commande déjà passée.

