<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ERP\Core\App;

// Carrega variáveis de ambiente
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Configura timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

// Configura error reporting
if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Inicia aplicação
try {
    $app = App::getInstance();
    $app->run();
} catch (\Throwable $e) {
    // Log de erro fatal
    error_log("Fatal error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
    // Resposta de erro
    http_response_code(500);
    
    if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
        echo "<h1>Erro Fatal</h1>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    } else {
        echo json_encode(['error' => 'Erro interno do servidor']);
    }
}
