<?php

declare(strict_types=1);

namespace ERP\Core\Auth;

/**
 * Two-Factor Authentication Data
 * 
 * Contains 2FA setup data including secret and backup codes
 * 
 * @package ERP\Core\Auth
 */
final readonly class TwoFactorData
{
    public function __construct(
        public string $secret,
        public array $backupCodes,
        public string $qrCodeUrl = '',
        public string $manualEntryKey = ''
    ) {}
    
    /**
     * Generate QR code URL for 2FA setup
     */
    public function generateQrCodeUrl(string $issuer, string $accountName): string
    {
        $params = http_build_query([
            'secret' => $this->secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
        
        return "otpauth://totp/{$accountName}?{$params}";
    }
    
    /**
     * Get formatted manual entry key
     */
    public function getFormattedSecret(): string
    {
        return chunk_split($this->secret, 4, ' ');
    }
    
    /**
     * Convert to array for response
     */
    public function toArray(): array
    {
        return [
            'secret' => $this->secret,
            'formatted_secret' => $this->getFormattedSecret(),
            'backup_codes' => $this->backupCodes,
            'qr_code_url' => $this->qrCodeUrl,
            'manual_entry_key' => $this->manualEntryKey,
        ];
    }
}