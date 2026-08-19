<?php

declare(strict_types=1);

use Framework\Config\Env;

return [
    // 'private' = storage/app/uploads (not web-accessible — serve via a
    //             controller route that can enforce auth/ownership checks)
    // 'public'  = public/uploads (served directly by the web server, no PHP
    //             in the request path — use for content anyone may view)
    'default' => Env::get('FILESYSTEM_DRIVER', 'private'),

    // Both are relative to the app basePath (same convention as
    // config/cache.php's route_cache_path).
    'private_path' => Env::get('STORAGE_PRIVATE_PATH', 'storage/app/uploads'),
    'public_path'  => Env::get('STORAGE_PUBLIC_PATH', 'public/uploads'),

    'max_size' => (int) Env::get('UPLOAD_MAX_SIZE', 5 * 1024 * 1024), // bytes, default 5MB

    // Checked against the file's actual bytes (finfo), never the client-reported
    // Content-Type — that header is fully attacker-controlled.
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
    ],
];
