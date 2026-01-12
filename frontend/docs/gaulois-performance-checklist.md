# Gaulois – Validation Performance & Accessibilité

Checklist à dérouler avant livraison/staging.

## Build & Analyse
- `npm install`
- `npm run build`
- `npm run preview` (Chrome/Edge 114+, mode desktop 1440×900)
- Profiler Lighthouse (Chrome DevTools > Lighthouse) – cibler Performance & Accessibilité ≥ 90.
- DevTools Performance (CPU throttle ×4) : vérifier que la timeline reste fluide, pas de long tasks > 50 ms.
- DevTools Memory : prendre un snapshot après 2 min d’animations, s’assurer < 200 MB pour l’onglet.

## Modes d’animation
- Tester les 3 états du toggle « Animations » (Système / Pleines / Réduites) et `prefers-reduced-motion`.
- Vérifier que les composantes visuelles se figent en mode Réduites (intro, parallax, banderoles, feu, rideau).

## Audio
- Autoplay bloqué → bouton « Activer la bande-son ». Confirmer volume slider + raccourcis clavier (`↑`/`↓`/`M`/`Space`).
- Mutation dry-run (console) : `reducedMotion` effectif logué uniquement en mode dev.

## Interaction & Navigation
- Transition menu → Gaulois (rideau tricolore + intro).
- Retour/rechargement : état du toggle animations persistant (localStorage).
- Feux d’artifice : intro terminée, activation audio, volume qui passe en bande `high`, clic manuel.

## Accessibilité
- Tab order cohérent (nav → toggle → intro → audio …).
- Contraste AA pour les nouveaux éléments (toggle & placeholders).
- `role="status"` sur états fallback (`Préparation du décor…`, etc.).

Documenter les mesures dans le ticket avant mise en prod (captures Lighthouse + notes CPU/mémoire).

