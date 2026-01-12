<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ripair' => [
        'base_url' => env('RIPAIR_SITE_BASE_URL', ''),
        'options_cache_token' => env('RIPAIR_OPTIONS_CACHE_TOKEN', ''),
    ],

    'boxtal' => [
        'base_url' => env('BOXTAL_BASE_URL', 'https://test.envoimoinscher.com/api/v1'),
        'key' => env('BOXTAL_KEY'),
        'secret' => env('BOXTAL_SECRET'),
        'login' => env('BOXTAL_LOGIN'),
        'password' => env('BOXTAL_PASSWORD'),
        // Mode test Helix: ne paie pas de bordereau, génère un "bon d'envoi" factice imprimable.
        'dev_mode' => (bool) env('BOXTAL_DEV_MODE', false),
        'from' => [
            'country' => env('BOXTAL_FROM_COUNTRY', 'FR'),
            'zip' => env('BOXTAL_FROM_ZIP'),
            'city' => env('BOXTAL_FROM_CITY'),
            'type' => env('BOXTAL_FROM_TYPE', 'entreprise'), // entreprise | particulier
            'address' => env('BOXTAL_FROM_ADDRESS', ''),
            'company' => env('BOXTAL_FROM_COMPANY', ''),
            'first_name' => env('BOXTAL_FROM_FIRST_NAME', ''),
            'last_name' => env('BOXTAL_FROM_LAST_NAME', ''),
            'email' => env('BOXTAL_FROM_EMAIL', ''),
            'phone' => env('BOXTAL_FROM_PHONE', ''),
        ],
        'defaults' => [
            'length' => env('BOXTAL_DEFAULT_LENGTH', 20),
            'width' => env('BOXTAL_DEFAULT_WIDTH', 20),
            'height' => env('BOXTAL_DEFAULT_HEIGHT', 20),
            'content_code' => env('BOXTAL_CONTENT_CODE', '10150'), // code_contenu requis
        ],
    ],

    'mobilesentrix_mail' => [
        // IMAP inbox (Zimbra/OVH) pour récupérer les emails "Your Order #... has been Shipped!"
        // Pas besoin de boîte dédiée : vous pouvez pointer vers la boîte existante.
        'host' => env('MOBILESENTRIX_IMAP_HOST'),
        'port' => env('MOBILESENTRIX_IMAP_PORT', 993),
        // ssl | tls | none
        'encryption' => env('MOBILESENTRIX_IMAP_ENCRYPTION', 'ssl'),
        'username' => env('MOBILESENTRIX_IMAP_USERNAME'),
        'password' => env('MOBILESENTRIX_IMAP_PASSWORD'),
        'folder' => env('MOBILESENTRIX_IMAP_FOLDER', 'INBOX'),
        'since_days' => (int) env('MOBILESENTRIX_IMAP_SINCE_DAYS', 30),
        'from_contains' => env('MOBILESENTRIX_MAIL_FROM_CONTAINS', 'mobilesentrix'),
        // Auto-association des emails "shipped" à une commande fournisseur même si le n° n'est pas renseigné,
        // uniquement si l'association est jugée non ambiguë.
        'auto_link_unnumbered' => (bool) env('MOBILESENTRIX_AUTO_LINK_UNNUMBERED', false),
    ],

];
