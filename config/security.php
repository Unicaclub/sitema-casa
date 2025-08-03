<?php

return [
    'rate_limiting' => [
        'enabled' => true,
        'requests_per_minute' => 60,
        'burst_limit' => 10,
    ],
    
    'csrf' => [
        'enabled' => true,
        'token_lifetime' => 3600,
    ],
    
    'headers' => [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'",
    ],
    
    'encryption' => [
        'algorithm' => 'AES-256-CBC',
        'key' => $_ENV['APP_KEY'] ?? 'base64:' . base64_encode(random_bytes(32)),
    ],
    
    'ip_whitelist' => [
        'admin_routes' => [
            '127.0.0.1',
            '::1',
            // Adicionar IPs autorizados para admin
        ]
    ],
    
    'file_upload' => [
        'max_size' => 10 * 1024 * 1024, // 10MB
        'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
        'scan_uploads' => true,
    ]
];
