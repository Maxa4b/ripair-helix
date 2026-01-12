# Livreo — Workflows opérationnels

## A) Commande standard (MobileSentrix → client)
1. Commande client payée (visible dans Livreo : “À approvisionner”).
2. Création commande fournisseur (MobileSentrix) depuis la commande.
3. Passage “Commandé” + saisie n° commande fournisseur.
4. À réception : “Reçu” (partiel possible) + contrôle.
5. Préparation colis client.
6. Saisie transporteur + tracking (+ multi-colis si besoin).
7. Passage “Expédiée” :
   - notification client
   - tracking visible dans “Mon compte”
8. Passage “Livrée” (manuel ou via tracking webhook V2).

## B) Commande relai (Boxtal)
- Le client a choisi un mode relai (delivery_type=relay).
- Livreo doit :
  - afficher l’opérateur/service choisi,
  - forcer la cohérence : tracking URL, transporteur recommandé,
  - prévoir la gestion d’un identifiant point relai si disponible (V2).

## C) SAV
1. Ticket ouvert (lié à commande + article).
2. Opérateur répond, demande photos si besoin.
3. Statut “En attente client” / “En cours”.
4. Résolution : remplacement / remboursement / retour (selon process).
5. Clôture (audit + notification).

