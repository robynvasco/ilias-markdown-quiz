<?php
declare(strict_types=1);
/**
 *  This file is part of the Markdown Quiz Repository Object plugin for ILIAS
 *  Provides encryption/decryption services for sensitive data like API keys
 */

namespace platform;

/**
 * Encryption Service for MarkdownQuiz Plugin
 * 
 * Provides AES-256-CBC encryption for sensitive config values (API keys).
 * Uses PBKDF2 key derivation from ILIAS client ID and password salt.
 * 
 * Format: base64(IV + encrypted_data)
 * - IV: 16 bytes (random per encryption)
 * - Key: 32 bytes (derived from client ID + salt)
 * 
 * @package platform
 */
class ilMarkdownQuizEncryption
{
    private const ENCRYPTED_PREFIX = 'xmdq:v1:';

    // Encryption method - AES-256-CBC is secure and widely supported
    private const ENCRYPTION_METHOD = 'aes-256-cbc';
    
    // Fixed IV length for AES-256-CBC (16 bytes)
    private const IV_LENGTH = 16;
    
    /**
     * Get encryption key using PBKDF2 derivation
     * 
     * Derives 32-byte key from ILIAS client ID and password salt.
     * Ensures keys are unique per installation but consistent across requests.
     * 
     * @return string 32-byte binary key
     */
    private static function getEncryptionKey(): string
    {
        // Use ILIAS client ID and a fixed salt to derive encryption key
        // This ensures keys are unique per installation but consistent across requests
        $clientId = CLIENT_ID ?? 'ilias';
        $salt = self::getSalt();
        
        // Derive a 32-byte key using hash_pbkdf2
        return hash_pbkdf2('sha256', $clientId, $salt, 10000, 32, true);
    }
    
    /**
     * Get salt for key derivation
     * @return string
     */
    private static function getSalt(): string
    {
        // Explicit override for environments that cannot expose ILIAS salt
        $envSalt = getenv('MDQUIZ_ENCRYPTION_SALT');
        if (is_string($envSalt) && $envSalt !== '') {
            return $envSalt;
        }

        // Try to use ILIAS secret from ilias.ini.php
        global $ilClientIniFile;
        
        if (isset($ilClientIniFile) && $ilClientIniFile !== null) {
            $passwordSalt = $ilClientIniFile->readVariable('auth', 'password_salt');
            if (!empty($passwordSalt)) {
                return $passwordSalt;
            }
        }
        
        // Fallback: derive deterministic installation-specific salt.
        // Must stay stable across container restarts/recreations.
        $material = (CLIENT_ID ?? 'ilias') . '|' . __DIR__;
        return hash('sha256', $material);
    }
    
    /**
     * Encrypt a value
     * @param string $value Plain text value to encrypt
     * @return string Base64-encoded encrypted value with IV prepended
     */
    public static function encrypt(string $value): string
    {
        if (empty($value)) {
            return '';
        }
        
        $key = self::getEncryptionKey();
        
        // Generate random IV for each encryption
        $iv = random_bytes(self::IV_LENGTH);
        
        // Encrypt the value
        $encrypted = openssl_encrypt(
            $value,
            self::ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($encrypted === false) {
            throw new ilMarkdownQuizException('Encryption failed');
        }

        // Prepend IV to encrypted data and encode as base64
        // Format: base64(iv + encrypted_data)
        return self::ENCRYPTED_PREFIX . base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt a value
     * @param string $encryptedValue Base64-encoded encrypted value with IV
     * @return string Decrypted plain text value
     */
    public static function decrypt(string $encryptedValue): string
    {
        if (empty($encryptedValue)) {
            return '';
        }

        $data = self::decodeEncryptedPayload($encryptedValue);
        $key = self::getEncryptionKey();

        // Extract IV from beginning of data
        $iv = substr($data, 0, self::IV_LENGTH);
        $encrypted = substr($data, self::IV_LENGTH);

        // Decrypt the value
        $decrypted = openssl_decrypt(
            $encrypted,
            self::ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($decrypted === false) {
            throw new ilMarkdownQuizException('Decryption failed');
        }
        
        return $decrypted;
    }
    
    /**
     * Check if a value is encrypted
     * @param string $value Value to check
     * @return bool True if value appears to be encrypted
     */
    public static function isEncrypted(string $value): bool
    {
        if (empty($value)) {
            return false;
        }

        if (str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            return true;
        }

        try {
            self::decrypt($value);
            return true;
        } catch (ilMarkdownQuizException $e) {
            return false;
        }
    }

    private static function decodeEncryptedPayload(string $value): string
    {
        if (str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            $value = substr($value, strlen(self::ENCRYPTED_PREFIX));
        }

        $data = base64_decode($value, true);
        if ($data === false) {
            throw new ilMarkdownQuizException('Invalid encrypted value format');
        }

        if (strlen($data) < self::IV_LENGTH) {
            throw new ilMarkdownQuizException('Invalid encrypted value length');
        }

        return $data;
    }
}
