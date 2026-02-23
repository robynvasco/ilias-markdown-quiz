# Security Guide

This file is the single source of truth for plugin-level security.

## Scope

MarkdownQuiz secures what the plugin controls. Some controls are owned by ILIAS or the web server.

Plugin scope:
- Input validation and sanitization
- AI request/response hardening
- API key encryption at rest
- Session-based rate limiting
- File processing safeguards

Infrastructure scope (not plugin-owned):
- Global HTTP headers like `Server`
- Reverse proxy / Apache hardening
- ILIAS-wide CSRF/session policies

## Current Controls (Code-Aligned)

### 1. Input Validation and XSS Protection

Implemented in `classes/platform/class.ilMarkdownQuizXSSProtection.php` and used in `classes/class.ilObjMarkdownQuizGUI.php`.

- `sanitizeUserInput($input, $max_length)`
  - Removes null bytes and control chars
  - Normalizes whitespace
  - Enforces max length
- `validateDifficulty()` allows only: `easy`, `medium`, `hard`, `mixed`
- `validateQuestionCount()` allows only `1..10`
- `escapeHTML()` and `sanitizeMarkdown()` protect rendering path
- `protectContent()` combines sanitization + markdown structure validation

### 2. Prompt Injection Strategy

Design choice: no regex-based "intent detection". It is easy to bypass and creates false positives.

Current defensive model:
- API keys are not placed into model prompt context
- Prompt and context are sanitized before use
- Model output is schema-validated and content-validated
- Non-quiz or malicious output is rejected

Related code:
- `classes/class.ilObjMarkdownQuizGUI.php`
- `classes/security/class.ilMarkdownQuizResponseValidator.php`

### 3. API Response Validation

Implemented in `classes/security/class.ilMarkdownQuizResponseValidator.php`.

- OpenAI Responses API structure validation (`output[].content[].text`)
- Google response validation (`candidates[].content.parts[].text`)
- GWDG/OpenAI-compatible response validation (`choices[].message.content`)
- Content safety checks block:
  - `<script>` tags
  - `<?php`
  - SQL-like payload fragments
  - `javascript:` in markdown image URLs
  - oversized responses (>100KB)
- Quiz format validation ensures usable markdown quiz structure

### 4. Rate Limiting

Implemented in `classes/platform/class.ilMarkdownQuizRateLimiter.php`.

Session-scoped limits:
- API calls: `20/hour`
- File processing: `20/hour`
- Quiz generation cooldown: `10 seconds`
- Concurrent requests: `3`

Integrated in generation flow in `classes/class.ilObjMarkdownQuizGUI.php`.

### 5. API Resilience and Integrity

Implemented in `classes/security/`:
- `class.ilMarkdownQuizCircuitBreaker.php`
  - Opens after 5 failures
  - 60s timeout before half-open retry
- `class.ilMarkdownQuizRequestSigner.php`
  - HMAC-SHA256 signature with timestamp
  - 5-minute replay window check
- `class.ilMarkdownQuizCertificatePinner.php`
  - Available but optional
  - Requires manual certificate fingerprint configuration

### 6. API Key Encryption at Rest

Implemented in:
- `classes/platform/class.ilMarkdownQuizConfig.php`
- `classes/platform/class.ilMarkdownQuizEncryption.php`

Details:
- Algorithm: `AES-256-CBC`
- Key derivation: PBKDF2-SHA256
- Encrypted config keys:
  - `gwdg_api_key`
  - `google_api_key`
  - `openai_api_key`
- Automatic migration helper: `migrateApiKeys()`

### 7. File Security

Implemented in `classes/platform/class.ilMarkdownQuizFileSecurity.php` and extraction flow in `classes/class.ilObjMarkdownQuizGUI.php`.

- Max file size: `10MB`
- ZIP safety checks:
  - max uncompressed size `50MB`
  - max compression ratio `10`
- Magic-byte checks for PDF/ZIP-based formats
- Timeout control for expensive extraction paths

Supported source types in GUI flow:
- `txt`, `tex`, `pdf`, `doc`, `docx`, `ppt`, `pptx`, ILIAS learning module (`lm`)

## CSRF and Permissions

- Permissions are enforced via ILIAS access checks (`checkPermission("write")` for edit/generation actions).
- Forms use ILIAS form/action flow (`ilCtrl::getFormAction(...)`).
- CSRF token behavior is primarily framework-level in ILIAS.

## Test Coverage

Primary scripts in `test/`:
- `test_input_validation.php`
- `test_prompt_injection.php`
- `test_encryption.php`
- `test_rate_limiter.php`
- `test_api_security.php`
- `security_test.sh` (HTTP integration checks)

`reset_system_prompt.php` is a maintenance script, not a test.

## Quick Runbook

From host (inside project root):

```bash
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_input_validation.php
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_prompt_injection.php
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_encryption.php
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_rate_limiter.php
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_api_security.php
```

HTTP security checks (recommended from container network namespace):

```bash
docker exec ilias-dev-ilias-1 bash -lc '
  cd /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz &&
  ./test/security_test.sh "http://localhost" <ref_id> "PHPSESSID=<session>; ilClientId=default"'
```
