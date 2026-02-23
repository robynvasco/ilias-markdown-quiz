<?php
declare(strict_types=1);

/**
 * Live provider smoke test (OpenAI / Google / GWDG).
 *
 * Reads plugin config from DB, decrypts API keys, and performs one real generation
 * call per configured provider.
 *
 * Run:
 * docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_provider_e2e.php
 */

const CLIENT_ID = 'default';
$ilClientIniFile = null;

require_once __DIR__ . '/../classes/platform/class.ilMarkdownQuizException.php';
require_once __DIR__ . '/../classes/platform/class.ilMarkdownQuizEncryption.php';
require_once __DIR__ . '/../classes/ai/class.ilMarkdownQuizLLM.php';
require_once __DIR__ . '/../classes/ai/class.ilMarkdownQuizOpenAI.php';
require_once __DIR__ . '/../classes/ai/class.ilMarkdownQuizGoogleAI.php';
require_once __DIR__ . '/../classes/ai/class.ilMarkdownQuizGWDG.php';

use ai\ilMarkdownQuizOpenAI;
use ai\ilMarkdownQuizGoogleAI;
use ai\ilMarkdownQuizGWDG;
use platform\ilMarkdownQuizEncryption;

final class OpenAIE2E extends ilMarkdownQuizOpenAI {
    protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string
    {
        return "Generate EXACTLY {$question_count} quiz questions with difficulty {$difficulty}. " .
            "Each question must have exactly 4 answers and at least one [x].\n\n" . $user_prompt;
    }
}

final class GoogleE2E extends ilMarkdownQuizGoogleAI {
    protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string
    {
        return "Generate EXACTLY {$question_count} quiz questions with difficulty {$difficulty}. " .
            "Each question must have exactly 4 answers and at least one [x].\n\n" . $user_prompt;
    }
}

final class GWDGE2E extends ilMarkdownQuizGWDG {
    protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string
    {
        return "Generate EXACTLY {$question_count} quiz questions with difficulty {$difficulty}. " .
            "Each question must have exactly 4 answers and at least one [x].\n\n" . $user_prompt;
    }
}

function readClientIni(string $path): array
{
    $ini = parse_ini_file($path, true, INI_SCANNER_TYPED);
    if (!is_array($ini)) {
        throw new RuntimeException('Failed to read client.ini.php');
    }
    return $ini;
}

function fetchConfig(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT name, value FROM xmdq_config');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $out = [];
    foreach ($rows as $row) {
        if (!isset($row['name'])) {
            continue;
        }
        $name = (string)$row['name'];
        $value = isset($row['value']) ? (string)$row['value'] : '';
        $decoded = json_decode($value, true);
        $out[$name] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
    }
    return $out;
}

function runProvider(string $name, callable $runner): array
{
    $start = microtime(true);
    try {
        $result = $runner();
        $elapsed = round((microtime(true) - $start) * 1000);
        return [true, $elapsed, $result, null];
    } catch (Throwable $e) {
        $elapsed = round((microtime(true) - $start) * 1000);
        return [false, $elapsed, null, $e->getMessage()];
    }
}

function redactSecrets(string $message): string
{
    $patterns = [
        '/sk-[A-Za-z0-9\-_]{16,}/',
        '/AIza[0-9A-Za-z\-_]{20,}/',
        '/Bearer\s+[A-Za-z0-9\-_\.]+/i',
        '/Incorrect API key provided:\s*[^\.]+/i',
    ];

    $redacted = $message;
    foreach ($patterns as $pattern) {
        $redacted = (string)preg_replace($pattern, '[REDACTED]', $redacted);
    }

    return $redacted;
}

echo "========================================\n";
echo "MarkdownQuiz Provider E2E Smoke Test\n";
echo "========================================\n\n";

$clientIniPath = '/var/www/html/public/data/default/client.ini.php';
$cfg = readClientIni($clientIniPath);
$db = $cfg['db'] ?? [];

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $db['host'] ?? 'db',
    (int)($db['port'] ?? 3306),
    $db['name'] ?? 'ilias'
);

$pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$config = fetchConfig($pdo);

$enabledModels = is_array($config['enabled_models'] ?? null) ? $config['enabled_models'] : [];

$apiKeys = [
    'openai' => ilMarkdownQuizEncryption::decrypt((string)($config['openai_api_key'] ?? '')),
    'google' => ilMarkdownQuizEncryption::decrypt((string)($config['google_api_key'] ?? '')),
    'gwdg' => ilMarkdownQuizEncryption::decrypt((string)($config['gwdg_api_key'] ?? '')),
];

$defaults = [
    'openai' => 'gpt-5-mini',
    'google' => 'gemini-2.5-flash',
    'gwdg' => 'meta-llama/Llama-3.3-70B-Instruct',
];

$selectedModel = $defaults;
foreach ($enabledModels as $model => $provider) {
    if (is_string($provider) && isset($selectedModel[$provider]) && $selectedModel[$provider] === $defaults[$provider]) {
        $selectedModel[$provider] = (string)$model;
    }
}

$prompt = "Create exactly one single-choice question about HTTP security headers.\n" .
    "Output format must be exactly:\n" .
    "Question text ending with ?\n" .
    "- [x] Correct answer\n" .
    "- [ ] Wrong answer 1\n" .
    "- [ ] Wrong answer 2\n" .
    "- [ ] Wrong answer 3";
$difficulty = 'easy';
$questionCount = 1;

$providers = [
    'openai' => function () use ($apiKeys, $selectedModel, $prompt, $difficulty, $questionCount): string {
        if ($apiKeys['openai'] === '') {
            throw new RuntimeException('OpenAI API key missing');
        }
        $ai = new OpenAIE2E($apiKeys['openai'], $selectedModel['openai']);
        return $ai->generateQuiz($prompt, $difficulty, $questionCount);
    },
    'google' => function () use ($apiKeys, $selectedModel, $prompt, $difficulty, $questionCount): string {
        if ($apiKeys['google'] === '') {
            throw new RuntimeException('Google API key missing');
        }
        $ai = new GoogleE2E($apiKeys['google'], $selectedModel['google']);
        return $ai->generateQuiz($prompt, $difficulty, $questionCount);
    },
    'gwdg' => function () use ($apiKeys, $selectedModel, $prompt, $difficulty, $questionCount): string {
        if ($apiKeys['gwdg'] === '') {
            throw new RuntimeException('GWDG API key missing');
        }
        $ai = new GWDGE2E($apiKeys['gwdg'], $selectedModel['gwdg']);
        return $ai->generateQuiz($prompt, $difficulty, $questionCount);
    },
];

$passed = 0;
$failed = 0;

foreach ($providers as $name => $runner) {
    [$ok, $ms, $output, $err] = runProvider($name, $runner);
    echo strtoupper($name) . ": ";
    if ($ok) {
        $preview = trim((string)$output);
        $preview = preg_replace('/\s+/', ' ', $preview ?? '');
        $preview = substr($preview, 0, 140);
        echo "PASS ({$ms} ms)\n";
        echo "  model: {$selectedModel[$name]}\n";
        echo "  preview: {$preview}\n\n";
        $passed++;
    } else {
        echo "FAIL ({$ms} ms)\n";
        echo "  model: {$selectedModel[$name]}\n";
        echo "  error: " . redactSecrets((string)$err) . "\n\n";
        $failed++;
    }
}

echo "========================================\n";
echo "Summary: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);
