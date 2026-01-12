# Livreo — Spécification produit (V1)

## 1) Écrans

### 1.1 Liste commandes
- Recherche globale : n° commande, email, nom, tracking.
- Filtres : statut paiement, statut expédition, mode livraison (domicile/relai), date, "à approvisionner", "urgence".
- Tri : récent, montant, délai, urgence.
- Actions rapides : ouvrir, marquer “reçu”, marquer “expédié”, ajouter tracking (selon permissions).

### 1.2 Détail commande
- Résumé : n° commande, date, total, paiement, mode livraison (Boxtal), statut opérationnel.
- Client : identité, email, téléphone.
- Adresses : livraison / facturation.
- Articles : SKU/référence, quantité, prix, notes.
- Paiements : provider, statut, références.
- Logs/audit : liste des actions.
- Actions :
  - Créer/lier commande fournisseur (MobileSentrix).
  - Marquer réception fournisseur (tout/partiel, manquant).
  - Saisir transporteur + tracking (multi-colis possible).
  - Passer statut expédition : “En préparation” → “Expédiée” → “Livrée”.
  - Envoyer message au client (template) / ajouter note interne.

### 1.3 Approvisionnement (MobileSentrix)
- Vue “à commander” : commandes payées non approvisionnées.
- Formulaire “commande fournisseur” :
  - fournisseur (MobileSentrix), n° commande fournisseur, date, lignes, coût, pièce jointe (facture PDF).
  - mapping SKU fournisseur ↔ produits RIPAIR (au minimum manuel en V1).
- Statuts : À commander / Commandé / Reçu / Problème.

### 1.4 SAV
- Liste tickets (ouvert/en cours/en attente client/résolu/fermé).
- Détail ticket : messages, pièces jointes, items concernés, statut, historisation.
- Actions : demander infos, proposer solution, valider retour, clôturer.

## 2) Règles métier clés

- On ne marque pas “Expédiée” sans au moins 1 tracking valide (sauf override + audit).
- On n’expédie pas (par défaut) si la commande fournisseur est en “À commander/Commandé” (configurable).
- Le mode de livraison Boxtal sélectionné par le client doit être visible et utilisé :
  - type (domicile/relai), opérateur (COLI/CHRP/MONR…), service.
  - Livreo guide le transporteur recommandé et pré-remplit si possible.
- Notifications client :
  - à l’ajout du tracking : email + affichage dans “Mon compte”.
  - à chaque changement statut SAV (selon paramétrage).

## 3) Permissions (minimum)
- Lecture : voir commandes/tickets.
- Opérateur : éditer statuts, tracking, notes internes.
- Admin : annulation, overrides, suppression pièces jointes, actions sensibles.

## 4) Non-fonctionnel
- Audit obligatoire sur toute écriture (statuts, tracking, SAV).
- Idempotence : un événement (ex: “tracking ajouté”) ne doit pas doubler des notifications.
- Résilience : afficher les données déjà stockées (ne pas dépendre d’appels externes).

