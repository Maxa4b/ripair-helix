# Assets libres — Gaulois (Helix)

Cette page répertorie les assets audio/visuels utilisés par la page Gaulois et leurs licences.

## Audio

- Nom attendu: `/audio/marseillaise.mp3`
- Recommandation (domaine public):
  - Titre: France National Anthem — U.S. Navy Band
  - Source: https://commons.wikimedia.org/wiki/File:La_Marseillaise_(France).ogg
  - Licence: Domaine public (œuvre du gouvernement fédéral des États‑Unis)
  - Justification: Les travaux du gouvernement fédéral des États‑Unis sont dans le domaine public; en conséquence, cet enregistrement peut être utilisé sans restriction.
  - Intégration: télécharger en MP3 et placer le fichier sous `site/Helix/frontend/public/audio/marseillaise.mp3`.

### Alternatives (si vous préférez une autre orchestration)

- Free Music Archive — Recherches “orchestral anthem” (vérifier licence piste par piste)
  - Licence: souvent CC BY / CC BY‑SA. Une attribution claire est alors requise (ajoutez la mention exacte ici et dans l’UI si nécessaire).

## Visuels

- La page Gaulois n’embarque pas d’images externes; tous les visuels (rubans tricolores, halos, parallax) sont dessinés par CSS/SVG inline.
- Drapeau/tricolore: représentations abstraites (formes et dégradés) — aucune ressource image tierce.

## Attribution

- Si vous remplacez l’audio par un asset CC BY/CC BY‑SA, ajoutez ci‑dessous l’attribution complète:

```
Titre — Auteur (Lien)
Licence: CC BY 4.0 — https://creativecommons.org/licenses/by/4.0/
Modifications: [décrivez si applicable]
```

## Intégration et vérifications

- Déposez les fichiers audio dans `site/Helix/frontend/public/audio/`.
- Vérifiez l’accessibilité (aria‑label, contrôle du volume, mute) — déjà pris en charge par l’UI.
- Contrôlez Lighthouse (Performance/Accessibilité ≥ 90) après intégration.

