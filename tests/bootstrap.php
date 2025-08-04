<?php

declare(strict_types=1);

// Bootstrap file for PHPUnit tests

// Set timezone
date_default_timezone_set('UTC');

// Define constants
define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('TESTS_PATH', ROOT_PATH . '/tests');

// Include Composer autoloader
$autoloader = ROOT_PATH . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    die('Composer autoloader not found. Please run "composer install"' . PHP_EOL);
}

require_once $autoloader;

// Load environment variables for testing
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'true';

// Set default test environment variables if not set
if (!isset($_ENV['JWT_SECRET'])) {
    $_ENV['JWT_SECRET'] = 'test-jwt-secret-key-32-characters-minimum-length-for-testing';
}

if (!isset($_ENV['REDIS_HOST'])) {
    $_ENV['REDIS_HOST'] = '127.0.0.1';
}

if (!isset($_ENV['REDIS_PORT'])) {
    $_ENV['REDIS_PORT'] = '6379';
}

if (!isset($_ENV['REDIS_DB'])) {
    $_ENV['REDIS_DB'] = '1'; // Use separate DB for tests
}

if (!isset($_ENV['REDIS_PREFIX'])) {
    $_ENV['REDIS_PREFIX'] = 'test:';
}

// Error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Memory limit for tests
ini_set('memory_limit', '512M');

// Create necessary directories for test outputs
$testDirectories = [
    ROOT_PATH . '/tests/results',
    ROOT_PATH . '/coverage',
    ROOT_PATH . '/coverage/html',
    ROOT_PATH . '/coverage/xml',
    ROOT_PATH . '/.phpunit.cache'
];

foreach ($testDirectories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Helper function to clean test data
function cleanTestData(): void
{
    if (extension_loaded('redis')) {
        try {
            $redis = new Redis();
            if ($redis->connect($_ENV['REDIS_HOST'], (int)$_ENV['REDIS_PORT'])) {
                $redis->select((int)$_ENV['REDIS_DB']);
                $redis->flushDB();
                $redis->close();
            }
        } catch (Exception $e) {
            // Redis not available, skip cleanup
        }
    }
}

// Helper function for test assertions
function assertArrayStructure(array $expected, array $actual, string $message = ''): void
{
    foreach ($expected as $key => $value) {
        if (!array_key_exists($key, $actual)) {
            throw new InvalidArgumentException(
                ($message ? $message . ': ' : '') . "Missing key '$key' in array"
            );
        }
        
        if (is_array($value) && is_array($actual[$key])) {
            assertArrayStructure($value, $actual[$key], $message);
        } elseif ($value !== null && gettype($actual[$key]) !== gettype($value)) {
            throw new InvalidArgumentException(
                ($message ? $message . ': ' : '') . 
                "Type mismatch for key '$key'. Expected " . gettype($value) . 
                ", got " . gettype($actual[$key])
            );
        }
    }
}

// Helper function to create test JWT token
function createTestJWTToken(array $payload = []): string
{
    $defaultPayload = [
        'user_id' => 1,
        'email' => 'test@example.com',
        'role' => 'user',
        'iat' => time(),
        'exp' => time() + 3600,
        'type' => 'access'
    ];
    
    $tokenPayload = array_merge($defaultPayload, $payload);
    
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode($tokenPayload);
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $_ENV['JWT_SECRET'], true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
}

// Helper function to generate test data
function generateTestSecurityEvent(): array
{
    return [
        'event_id' => 'test_event_' . uniqid(),
        'type' => 'test_security_event',
        'severity' => 'medium',
        'user_id' => 1,
        'ip_address' => '192.168.1.100',
        'user_agent' => 'PHPUnit Test Client',
        'timestamp' => time(),
        'details' => [
            'action' => 'test_action',
            'resource' => '/test/resource'
        ]
    ];
}

// Helper function to generate test user data
function generateTestUser(array $overrides = []): array
{
    $defaultUser = [
        'id' => 1,
        'tenant_id' => 1,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'role' => 'user',
        'is_active' => true,
        'permissions' => json_encode(['read' => ['*']]),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    return array_merge($defaultUser, $overrides);
}

// Mock das funções helper para testes
if (!function_exists('auth')) {
    function auth() {
        return new class {
            public function id(): int { return 1; }
            public function companyId(): int { return 1; }
            public function user(): array { return ['id' => 1, 'name' => 'Test User']; }
        };
    }
}

if (!function_exists('json_response')) {
    function json_response($data = null, string $message = null, int $status = 200): array {
        $response = ['status' => $status];
        
        if ($message) {
            $response['message'] = $message;
        }
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        return $response;
    }
}

// Setup test environment
echo "PHPUnit Bootstrap: Test environment initialized" . PHP_EOL;
echo "PHP Version: " . PHP_VERSION . PHP_EOL;

// Check required extensions
$requiredExtensions = ['json', 'openssl', 'hash'];
$optionalExtensions = ['redis', 'xdebug'];

foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        die("Required extension '$ext' is not loaded" . PHP_EOL);
    }
}

foreach ($optionalExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "Optional extension '$ext': Available" . PHP_EOL;
    } else {
        echo "Optional extension '$ext': Not available" . PHP_EOL;
    }
}

// Clean test data before running tests
cleanTestData();

// Register shutdown function to cleanup
register_shutdown_function(function () {
    cleanTestData();
});

echo "Bootstrap completed successfully" . PHP_EOL;