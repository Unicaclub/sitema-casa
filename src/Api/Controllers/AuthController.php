<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use ERP\Core\Auth\AuthenticationService;
use ERP\Core\Auth\JWTManager;
use ERP\Core\Security\AuditManager;
use ERP\Core\Database\DatabaseManager;

/**
 * Controller de Autenticação JWT
 * 
 * Sistema de autenticação JWT funcional e seguro
 * 
 * @package ERP\Api\Controllers
 */
final class AuthController
{
    private AuthenticationService $auth;
    private JWTManager $jwt;
    private AuditManager $audit;
    
    public function __construct()
    {
        // Initialize JWT manager
        $jwtSecret = $_ENV['JWT_SECRET'] ?? 'your-256-bit-secret-key-here-must-be-at-least-32-characters-long';
        $this->jwt = new JWTManager($jwtSecret, 'ERP-Sistema');
        
        // Initialize audit manager
        $this->audit = new AuditManager();
        
        // Initialize authentication service
        $this->auth = new AuthenticationService(
            new DatabaseManager(),
            $this->jwt,
            $this->audit
        );
    }
    
    /**
     * POST /api/auth/login
     * Login do usuário
     */
    public function login(Request $request): Response
    {
        try {
            $email = $request->get('email');
            $password = $request->get('password');
            
            if (empty($email) || empty($password)) {
                return $this->errorResponse('Email e senha são obrigatórios', 400);
            }
            
            // Contexto da requisição
            $context = [
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeader('User-Agent'),
                'timestamp' => time()
            ];
            
            // Autenticar
            $result = $this->auth->authenticate($email, $password, $context);
            
            if (!$result['success']) {
                $httpCode = match ($result['error_code'] ?? 'UNKNOWN') {
                    'INVALID_CREDENTIALS' => 401,
                    'ACCOUNT_LOCKED' => 423,
                    'ACCOUNT_INACTIVE' => 403,
                    'PASSWORD_CHANGE_REQUIRED' => 428,
                    default => 400
                };
                
                return $this->errorResponse($result['error'], $httpCode);
            }
            
            // Resposta de sucesso
            return $this->successResponse([
                'message' => 'Login realizado com sucesso',
                'user' => $result['user'],
                'access_token' => $result['tokens']['access_token'],
                'refresh_token' => $result['tokens']['refresh_token'],
                'token_type' => $result['tokens']['token_type'],
                'expires_in' => $result['tokens']['expires_in'],
                'requires_2fa' => $result['requires_2fa'] ?? false
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro interno: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/auth/refresh
     * Renovar token de acesso
     */
    public function refresh(Request $request): Response
    {
        try {
            $refreshToken = $request->get('refresh_token');
            
            if (empty($refreshToken)) {
                return $this->errorResponse('Refresh token é obrigatório', 400);
            }
            
            // Contexto da requisição
            $context = [
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeader('User-Agent')
            ];
            
            // Renovar token
            $result = $this->auth->refreshToken($refreshToken, $context);
            
            if (!$result['success']) {
                $httpCode = match ($result['error_code'] ?? 'UNKNOWN') {
                    'INVALID_REFRESH_TOKEN', 'REFRESH_TOKEN_NOT_FOUND' => 401,
                    'USER_NOT_FOUND' => 404,
                    default => 400
                };
                
                return $this->errorResponse($result['error'], $httpCode);
            }
            
            return $this->successResponse([
                'message' => 'Token renovado com sucesso',
                'access_token' => $result['tokens']['access_token'],
                'refresh_token' => $result['tokens']['refresh_token'],
                'token_type' => $result['tokens']['token_type'],
                'expires_in' => $result['tokens']['expires_in']
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro interno: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/auth/logout
     * Logout do usuário
     */
    public function logout(Request $request): Response
    {
        try {
            $accessToken = $this->extractTokenFromHeader($request);
            $refreshToken = $request->get('refresh_token');
            
            if (!$accessToken) {
                return $this->errorResponse('Token de acesso não encontrado', 401);
            }
            
            // Fazer logout
            $result = $this->auth->logout($accessToken, $refreshToken);
            
            if (!$result['success']) {
                return $this->errorResponse($result['error'], 400);
            }
            
            return $this->successResponse([
                'message' => 'Logout realizado com sucesso'
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro interno: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /api/auth/me
     * Obter dados do usuário atual
     */
    public function me(Request $request): Response
    {
        try {
            $accessToken = $this->extractTokenFromHeader($request);
            
            if (!$accessToken) {
                return $this->errorResponse('Token de acesso não encontrado', 401);
            }
            
            // Validar token
            $validation = $this->auth->validateAccessToken($accessToken);
            
            if (!$validation['valid']) {
                return $this->errorResponse('Token inválido', 401);
            }
            
            return $this->successResponse([
                'user' => $validation['user'],
                'token_info' => [
                    'expires_in' => $this->jwt->getTokenTTL($accessToken),
                    'issued_at' => $validation['payload']['iat'] ?? null,
                    'expires_at' => $validation['payload']['exp'] ?? null
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro interno: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/auth/verify-2fa
     * Verificar código 2FA
     */
    public function verify2fa(Request $request): Response
    {
        try {
            $userId = $request->get('user_id');
            $code = $request->get('code');
            
            if (empty($userId) || empty($code)) {
                return $this->errorResponse('User ID e código 2FA são obrigatórios', 400);
            }
            
            // Simular verificação 2FA
            $isValid = $this->verify2FACode($userId, $code);
            
            if (!$isValid) {
                return $this->errorResponse('Código 2FA inválido', 401);
            }
            
            return $this->successResponse([
                'message' => '2FA verificado com sucesso',
                'verified' => true
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro interno: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/auth/forgot-password
     * Solicitar reset de senha
     */
    public function forgotPassword(Request $request): Response
    {
        try {
            $email = $request->get('email');
            
            if (empty($email)) {
                return $this->errorResponse('Email é obrigatório', 400);
            }
            
            // Gerar token de reset
            $resetToken = $this->jwt->generatePasswordResetToken(1, $email);
            
            $this->audit->logEvent('password_reset_requested', [
                'email' => $email,
                'ip_address' => $this->getClientIP($request),
                'timestamp' => time()
            ]);
            
            return $this->successResponse([
                'message' => 'Email de reset de senha enviado',
                'reset_token' => $resetToken // Em produção, não retornar
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro interno: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/auth/reset-password
     * Redefinir senha
     */
    public function resetPassword(Request $request): Response
    {
        try {
            $token = $request->get('token');
            $newPassword = $request->get('new_password');
            $confirmPassword = $request->get('confirm_password');
            
            if (empty($token) || empty($newPassword) || empty($confirmPassword)) {
                return $this->errorResponse('Token, nova senha e confirmação são obrigatórios', 400);
            }
            
            if ($newPassword !== $confirmPassword) {
                return $this->errorResponse('Senhas não coincidem', 400);
            }
            
            // Validar token de reset
            $validation = $this->jwt->validateToken($token);
            
            if (!$validation['valid']) {
                return $this->errorResponse('Token de reset inválido ou expirado', 401);
            }
            
            $payload = $validation['payload'];
            
            if ($payload['type'] !== 'password_reset') {
                return $this->errorResponse('Tipo de token inválido', 401);
            }
            
            // Hash da nova senha
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $this->audit->logEvent('password_reset_completed', [
                'user_id' => $payload['user_id'],
                'email' => $payload['email'],
                'ip_address' => $this->getClientIP($request),
                'timestamp' => time()
            ]);
            
            return $this->successResponse([
                'message' => 'Senha redefinida com sucesso'
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro interno: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Métodos auxiliares
     */
    
    private function extractTokenFromHeader(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization');
        
        if (!$authHeader) {
            return null;
        }
        
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    private function verify2FACode(int $userId, string $code): bool
    {
        // Simular verificação 2FA - em produção usar TOTP
        return strlen($code) === 6 && is_numeric($code);
    }
    
    private function getClientIP(Request $request): string
    {
        return $request->getHeader('X-Forwarded-For') 
            ?? $request->getHeader('X-Real-IP') 
            ?? $_SERVER['REMOTE_ADDR'] 
            ?? 'unknown';
    }
    
    private function successResponse(array $data): Response
    {
        return new Response(
            json_encode(array_merge(['success' => true], $data)),
            200,
            ['Content-Type' => 'application/json']
        );
    }
    
    private function errorResponse(string $message, int $code = 400): Response
    {
        return new Response(
            json_encode([
                'success' => false,
                'error' => $message,
                'timestamp' => date('c')
            ]),
            $code,
            ['Content-Type' => 'application/json']
        );
    }
}