<?php

return [
    'session_lifetime' => 8 * 60 * 60, // 8 horas
    'max_login_attempts' => 5,
    'lockout_duration' => 15 * 60, // 15 minutos
    
    'password' => [
        'min_length' => 8,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_symbols' => false,
    ],
    
    'two_factor' => [
        'enabled' => true,
        'issuer' => 'ERP Sistema',
        'window' => 1, // ±1 período para TOTP
    ],
    
    'security' => [
        'bcrypt_rounds' => 12,
        'token_length' => 32,
        'session_regenerate' => true,
    ],
    
    'audit' => [
        'enabled' => true,
        'track_changes' => true,
        'retention_days' => 365,
    ]
];
