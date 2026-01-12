# Livreo (Helix) — Outil interne RIPAIR E-commerce

Livreo est un module Helix dédié aux opérations e-commerce : gestion des commandes, expéditions (tracking), approvisionnement fournisseur (ex: MobileSentrix) et SAV (tickets).

Objectif principal : depuis Helix, piloter le cycle complet **commande payée → approvisionnement → réception → expédition → suivi client**, tout en respectant le mode de livraison choisi par le client (Boxtal).

## Périmètre (V1)

- Commandes : recherche, filtres, détails, statuts opérationnels, notes internes, audit.
- Expédition : saisie transporteur + n° de suivi, envoi automatique au client, correction avec historique.
- Approvisionnement : suivi d’une commande fournisseur liée à une commande client (MobileSentrix).
- SAV : tickets liés à commande/produits, messages + pièces jointes, statuts, templates.

## Intégrations

- **Base e-commerce** : lecture/écriture sur les tables `orders`, `order_items`, `payments`, `users`, `rma_requests` (si existant), etc.
- **Boxtal** : le checkout choisit un mode de livraison. Livreo doit afficher ce choix (transporteur/service/type) et l’utiliser pour guider l’expédition.
  - Note : on garde l’appel Boxtal côté e-commerce, mais Livreo ne doit pas dépendre d’un appel réseau externe pour l’affichage d’une commande déjà passée.

## Dossier

- Spécifications : `Helix/livreo/SPEC.md`
- Données / modèle : `Helix/livreo/DATA_MODEL.md`
- Workflows : `Helix/livreo/WORKFLOWS.md`
- API Helix (proposition) : `Helix/livreo/API.md`

