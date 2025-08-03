<?php

return [
    'enabled' => [
        'Dashboard',
        'CRM', 
        'Estoque',
        'PDV',
        'Financeiro',
        'Marketing',
        'BI',
        'Sistema'
    ],
    
    'routing' => [
        'Dashboard' => [
            'prefix' => 'dashboard',
            'middleware' => ['auth']
        ],
        'CRM' => [
            'prefix' => 'crm',
            'middleware' => ['auth', 'permission:crm']
        ],
        'Estoque' => [
            'prefix' => 'estoque',
            'middleware' => ['auth', 'permission:estoque']
        ],
        'PDV' => [
            'prefix' => 'pdv',
            'middleware' => ['auth', 'permission:pdv']
        ],
        'Financeiro' => [
            'prefix' => 'financeiro',
            'middleware' => ['auth', 'permission:financeiro']
        ],
        'Marketing' => [
            'prefix' => 'marketing',
            'middleware' => ['auth', 'permission:marketing']
        ],
        'BI' => [
            'prefix' => 'bi',
            'middleware' => ['auth', 'permission:bi']
        ],
        'Sistema' => [
            'prefix' => 'sistema',
            'middleware' => ['auth', 'permission:sistema.admin']
        ]
    ],
    
    'permissions' => [
        'dashboard' => ['view'],
        'crm' => ['view', 'create', 'edit', 'delete', 'export'],
        'estoque' => ['view', 'create', 'edit', 'delete', 'movement', 'report'],
        'pdv' => ['view', 'sell', 'return', 'cash_management'],
        'financeiro' => ['view', 'create', 'edit', 'delete', 'approve', 'report'],
        'marketing' => ['view', 'create', 'edit', 'delete', 'send'],
        'bi' => ['view', 'export', 'schedule'],
        'sistema' => ['view', 'admin', 'users', 'integrations', 'backup']
    ]
];
