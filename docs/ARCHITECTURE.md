# Architecture

This document describes the current code structure (aligned with the repository state).

## High-Level Components

- `classes/class.ilObjMarkdownQuiz.php`
  - Repository object model and persistence hooks
- `classes/class.ilObjMarkdownQuizGUI.php`
  - Main UI/controller logic for view, edit, and AI generation
- `classes/class.ilObjMarkdownQuizAccess.php`
  - Access checks integrated with ILIAS permissions
- `classes/class.ilObjMarkdownQuizListGUI.php`
  - Repository list integration
- `classes/class.ilObjMarkdownQuizStakeholder.php`
  - IRSS stakeholder integration
- `classes/class.ilMarkdownQuizPlugin.php`
  - Plugin lifecycle/update hooks
- `classes/class.ilMarkdownQuizConfigGUI.php`
  - Admin configuration UI

## Directory Layout

```text
MarkdownQuiz/
├── classes/
│   ├── ai/
│   │   ├── class.ilMarkdownQuizLLM.php
│   │   ├── class.ilMarkdownQuizOpenAI.php
│   │   ├── class.ilMarkdownQuizGoogleAI.php
│   │   └── class.ilMarkdownQuizGWDG.php
│   ├── platform/
│   │   ├── class.ilMarkdownQuizConfig.php
│   │   ├── class.ilMarkdownQuizDatabase.php
│   │   ├── class.ilMarkdownQuizEncryption.php
│   │   ├── class.ilMarkdownQuizException.php
│   │   ├── class.ilMarkdownQuizFileSecurity.php
│   │   ├── class.ilMarkdownQuizRateLimiter.php
│   │   └── class.ilMarkdownQuizXSSProtection.php
│   ├── security/
│   │   ├── class.ilMarkdownQuizCertificatePinner.php
│   │   ├── class.ilMarkdownQuizCircuitBreaker.php
│   │   ├── class.ilMarkdownQuizRequestSigner.php
│   │   └── class.ilMarkdownQuizResponseValidator.php
│   ├── class.ilMarkdownQuizConfigGUI.php
│   ├── class.ilMarkdownQuizPlugin.php
│   ├── class.ilObjMarkdownQuiz.php
│   ├── class.ilObjMarkdownQuizAccess.php
│   ├── class.ilObjMarkdownQuizGUI.php
│   ├── class.ilObjMarkdownQuizListGUI.php
│   └── class.ilObjMarkdownQuizStakeholder.php
├── docs/
├── lang/
├── sql/
├── templates/
└── test/
```

## Request Flow (AI Generation)

1. User submits AI generation form in `ilObjMarkdownQuizGUI::generate()`.
2. Input is read through ILIAS UI form handling (`withRequest`).
3. Security checks run before provider call:
   - rate limiter (`recordQuizGeneration`, `incrementConcurrent`, `recordApiCall`)
   - input sanitization and enum/range validation
4. Provider client (`OpenAI`, `GoogleAI`, or `GWDG`) generates markdown.
5. Response validator checks provider schema and output safety.
6. Quiz markdown is validated/sanitized (`protectContent`) and stored.

## AI Provider Layer

Common abstraction:
- `classes/ai/class.ilMarkdownQuizLLM.php`

Providers:
- `class.ilMarkdownQuizOpenAI.php`
- `class.ilMarkdownQuizGoogleAI.php`
- `class.ilMarkdownQuizGWDG.php`

Provider selection is driven by admin configuration (`enabled_models` + provider API keys).

## Configuration Model

Config is stored in `xmdq_config` and accessed via `ilMarkdownQuizConfig`.

Important behaviors:
- Automatic encryption/decryption for API key fields
- Admin UI segmented by tabs (General, GWDG, Google, OpenAI)
- Per-model enable/disable mapping

## File and Context Ingestion

`ilObjMarkdownQuizGUI` supports context from:
- text input
- repository files (`txt`, `tex`, `pdf`, `doc`, `docx`, `ppt`, `pptx`)
- ILIAS learning modules (`lm`)

Security and extraction helpers:
- `ilMarkdownQuizFileSecurity`
- internal extraction methods for PDF/Office/XML content

## Security Modules

- Input/XSS: `class.ilMarkdownQuizXSSProtection.php`
- Rate limiting: `class.ilMarkdownQuizRateLimiter.php`
- API resilience: `class.ilMarkdownQuizCircuitBreaker.php`
- Request integrity: `class.ilMarkdownQuizRequestSigner.php`
- Response schema/safety: `class.ilMarkdownQuizResponseValidator.php`
- API key encryption: `class.ilMarkdownQuizEncryption.php`

For details, see `docs/SECURITY.md`.
