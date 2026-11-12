<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'google_sheets' => [
        // Service-account JSON key, kept outside the repository (never commit it).
        'key_path' => env('GOOGLE_SHEETS_KEY_PATH'),
        // The shared "Buildiwick Project Entries" spreadsheet that Data Entry types into.
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
        // Username of the Data Entry account whose drafts receive the sheet lines.
        'draft_username' => env('GOOGLE_SHEETS_DRAFT_USERNAME', 'user'),
    ],

];
