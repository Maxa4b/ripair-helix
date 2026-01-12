<?php

return [
    // Connexion DB utilisée pour lire/écrire les commandes du site e-commerce.
    'ecommerce_connection' => env('LIVREO_ECOMMERCE_CONNECTION', 'ecommerce'),

    // Base URL du site e-commerce pour construire des liens (commande, SAV, etc.).
    'shop_base_url' => env('LIVREO_SHOP_BASE_URL', env('RIPAIR_SITE_BASE_URL', 'https://boutique.ripair.shop')),
];

