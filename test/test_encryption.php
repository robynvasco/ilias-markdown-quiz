<?php
/**
 * Test script for API key encryption (standalone)
 * Run this: docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_encryption.php
 */

// Define CLIENT_ID constant for encryption key derivation
if (!defined('CLIENT_ID')) {
    define('CLIENT_ID', 'default');
}

// Mock ilClientIniFile for testing
global $ilClientIniFile;
$ilClientIniFile = null;

// Include encryption class
require_once __DIR__ . '/../classes/platform/class.ilMarkdownQuizException.php';
require_once __DIR__ . '/../classes/platform/class.ilMarkdownQuizEncryption.php';

use platform\ilMarkdownQuizEncryption;

echo "=== MarkdownQuiz API Key Encryption Test ===\n\n";

// Test 1: Basic encryption/decryption
echo "Test 1: Basic Encryption/Decryption\n";
$testKey = "sk-1234567890abcdefghijklmnopqrstuvwxyz";
echo "Original: $testKey\n";

$encrypted = ilMarkdownQuizEncryption::encrypt($testKey);
echo "Encrypted: $encrypted\n";
echo "Is Encrypted: " . (ilMarkdownQuizEncryption::isEncrypted($encrypted) ? "Yes" : "No") . "\n";

$decrypted = ilMarkdownQuizEncryption::decrypt($encrypted);
echo "Decrypted: $decrypted\n";
echo "Match: " . ($testKey === $decrypted ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 2: Empty value
echo "Test 2: Empty Value\n";
$emptyEncrypted = ilMarkdownQuizEncryption::encrypt("");
echo "Empty encrypted: '$emptyEncrypted'\n";
$emptyDecrypted = ilMarkdownQuizEncryption::decrypt($emptyEncrypted);
echo "Empty decrypted: '$emptyDecrypted'\n";
echo "Match: " . ($emptyEncrypted === $emptyDecrypted ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 3: Plain text detection (backward compatibility)
echo "Test 3: Plain Text Detection\n";
$plainText = "plain-text-api-key";
echo "Plain text: $plainText\n";
echo "Is Encrypted: " . (ilMarkdownQuizEncryption::isEncrypted($plainText) ? "Yes" : "No") . "\n";
$decryptedPlain = ilMarkdownQuizEncryption::decrypt($plainText);
echo "Decrypted plain: $decryptedPlain\n";
echo "Match (should return original): " . ($plainText === $decryptedPlain ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 4: Multiple encryptions produce different ciphertext (IV randomness)
echo "Test 4: IV Randomness\n";
$key = "test-api-key-123";
$enc1 = ilMarkdownQuizEncryption::encrypt($key);
$enc2 = ilMarkdownQuizEncryption::encrypt($key);
echo "Encryption 1: " . substr($enc1, 0, 30) . "...\n";
echo "Encryption 2: " . substr($enc2, 0, 30) . "...\n";
echo "Different ciphertext: " . ($enc1 !== $enc2 ? "✓ PASS" : "✗ FAIL") . "\n";
echo "Both decrypt correctly: " . (
    ilMarkdownQuizEncryption::decrypt($enc1) === $key && 
    ilMarkdownQuizEncryption::decrypt($enc2) === $key 
    ? "✓ PASS" : "✗ FAIL"
) . "\n\n";

// Test 5: Long API key
echo "Test 5: Long API Key\n";
$longKey = str_repeat("abcdefghijklmnopqrstuvwxyz0123456789", 10);
echo "Long key length: " . strlen($longKey) . " chars\n";
$encLong = ilMarkdownQuizEncryption::encrypt($longKey);
$decLong = ilMarkdownQuizEncryption::decrypt($encLong);
echo "Match: " . ($longKey === $decLong ? "✓ PASS" : "✗ FAIL") . "\n\n";

echo "=== All Tests Complete ===\n";
