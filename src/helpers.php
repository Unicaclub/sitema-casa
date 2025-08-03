<?php

/**
 * Funções Helper do Sistema ERP
 */

if (!function_exists('app')) {
    /**
     * Obtém instância da aplicação
     */
    function app(?string $service = null)
    {
        $app = \ERP\Core\App::getInstance();
        
        if ($service) {
            return $app->get($service);
        }
        
        return $app;
    }
}

if (!function_exists('config')) {
    /**
     * Obtém configuração
     */
    function config(string $key, $default = null)
    {
        return app()->config($key) ?? $default;
    }
}

if (!function_exists('env')) {
    /**
     * Obtém variável de ambiente
     */
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        
        // Converte valores boolean
        if (is_string($value)) {
            switch (strtolower($value)) {
                case 'true':
                case '(true)':
                    return true;
                case 'false':
                case '(false)':
                    return false;
                case 'null':
                case '(null)':
                    return null;
                case 'empty':
                case '(empty)':
                    return '';
            }
        }
        
        return $value;
    }
}

if (!function_exists('auth')) {
    /**
     * Obtém instância de autenticação
     */
    function auth(): \ERP\Core\Auth
    {
        return app('auth');
    }
}

if (!function_exists('cache')) {
    /**
     * Obtém instância de cache
     */
    function cache(): \ERP\Core\Cache
    {
        return app('cache');
    }
}

if (!function_exists('db')) {
    /**
     * Obtém instância do banco
     */
    function db(): \ERP\Core\Database
    {
        return app('database');
    }
}

if (!function_exists('logger')) {
    /**
     * Obtém instância do logger
     */
    function logger(): \ERP\Core\Logger
    {
        return app('logger');
    }
}

if (!function_exists('events')) {
    /**
     * Obtém instância do event bus
     */
    function events(): \ERP\Core\EventBus
    {
        return app('eventBus');
    }
}

if (!function_exists('response')) {
    /**
     * Cria resposta JSON
     */
    function response($data = null, int $status = 200, array $headers = []): \ERP\Core\Response
    {
        return new \ERP\Core\Response($data, $status, $headers);
    }
}

if (!function_exists('json_response')) {
    /**
     * Resposta JSON de sucesso
     */
    function json_response($data, string $message = 'Success', int $status = 200): \ERP\Core\Response
    {
        return response([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $status);
    }
}

if (!function_exists('error_response')) {
    /**
     * Resposta JSON de erro
     */
    function error_response(string $message, $errors = null, int $status = 400): \ERP\Core\Response
    {
        $data = [
            'success' => false,
            'message' => $message
        ];
        
        if ($errors) {
            $data['errors'] = $errors;
        }
        
        return response($data, $status);
    }
}

if (!function_exists('money_format')) {
    /**
     * Formata valor monetário
     */
    function money_format(float $value, string $currency = 'BRL'): string
    {
        switch ($currency) {
            case 'BRL':
                return 'R$ ' . number_format($value, 2, ',', '.');
            case 'USD':
                return '$' . number_format($value, 2, '.', ',');
            case 'EUR':
                return '€' . number_format($value, 2, ',', '.');
            default:
                return number_format($value, 2, '.', ',');
        }
    }
}

if (!function_exists('format_document')) {
    /**
     * Formata documento (CPF/CNPJ)
     */
    function format_document(string $document): string
    {
        $document = preg_replace('/\D/', '', $document);
        
        if (strlen($document) === 11) {
            // CPF
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $document);
        } elseif (strlen($document) === 14) {
            // CNPJ
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $document);
        }
        
        return $document;
    }
}

if (!function_exists('format_phone')) {
    /**
     * Formata telefone
     */
    function format_phone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        
        if (strlen($phone) === 11) {
            // Celular
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $phone);
        } elseif (strlen($phone) === 10) {
            // Fixo
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $phone);
        }
        
        return $phone;
    }
}

if (!function_exists('slug')) {
    /**
     * Gera slug de string
     */
    function slug(string $text): string
    {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        $text = preg_replace('/[^a-zA-Z0-9\s]/', '', $text);
        $text = preg_replace('/\s+/', '-', trim($text));
        return strtolower($text);
    }
}

if (!function_exists('sanitize_filename')) {
    /**
     * Sanitiza nome de arquivo
     */
    function sanitize_filename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $filename);
        return trim($filename, '.-');
    }
}

if (!function_exists('generate_uuid')) {
    /**
     * Gera UUID v4
     */
    function generate_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('human_file_size')) {
    /**
     * Converte bytes para formato legível
     */
    function human_file_size(int $bytes, int $decimals = 2): string
    {
        $size = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $size[$factor];
    }
}

if (!function_exists('time_ago')) {
    /**
     * Calcula tempo relativo
     */
    function time_ago(string $datetime): string
    {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) {
            return 'agora mesmo';
        }
        
        $time = array(
            365*24*60*60 => 'ano',
            30*24*60*60 => 'mês',
            24*60*60 => 'dia',
            60*60 => 'hora',
            60 => 'minuto'
        );
        
        foreach ($time as $unit => $val) {
            if ($time < $unit) continue;
            $numberOfUnits = floor($time / $unit);
            return ($numberOfUnits > 1) ? $numberOfUnits . ' ' . $val . 's atrás' : '1 ' . $val . ' atrás';
        }
    }
}

if (!function_exists('validate_document')) {
    /**
     * Valida CPF/CNPJ
     */
    function validate_document(string $document): bool
    {
        $document = preg_replace('/\D/', '', $document);
        
        if (strlen($document) === 11) {
            return validate_cpf($document);
        } elseif (strlen($document) === 14) {
            return validate_cnpj($document);
        }
        
        return false;
    }
}

if (!function_exists('validate_cpf')) {
    /**
     * Valida CPF
     */
    function validate_cpf(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);
        
        if (strlen($cpf) !== 11 || preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }
        
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }
        
        return true;
    }
}

if (!function_exists('validate_cnpj')) {
    /**
     * Valida CNPJ
     */
    function validate_cnpj(string $cnpj): bool
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        
        if (strlen($cnpj) !== 14) {
            return false;
        }
        
        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += $cnpj[$i] * $weights1[$i];
        }
        
        $remainder = $sum % 11;
        $digit1 = $remainder < 2 ? 0 : 11 - $remainder;
        
        if ($cnpj[12] != $digit1) {
            return false;
        }
        
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += $cnpj[$i] * $weights2[$i];
        }
        
        $remainder = $sum % 11;
        $digit2 = $remainder < 2 ? 0 : 11 - $remainder;
        
        return $cnpj[13] == $digit2;
    }
}

if (!function_exists('mask_email')) {
    /**
     * Mascara email para privacidade
     */
    function mask_email(string $email): string
    {
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1] ?? '';
        
        $nameLength = strlen($name);
        if ($nameLength > 2) {
            $name = substr($name, 0, 2) . str_repeat('*', $nameLength - 2);
        }
        
        return $name . '@' . $domain;
    }
}

if (!function_exists('is_mobile')) {
    /**
     * Detecta se é dispositivo móvel
     */
    function is_mobile(): bool
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return preg_match('/(android|iphone|ipad|mobile|tablet)/i', $userAgent);
    }
}

if (!function_exists('get_client_ip')) {
    /**
     * Obtém IP real do cliente
     */
    function get_client_ip(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
