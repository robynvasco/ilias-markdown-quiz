# ILIAS MarkdownQuiz Plugin

MarkdownQuiz is a repository object plugin for ILIAS to create and run quizzes in markdown, with optional AI-assisted generation.

## Core Features

- Markdown-based quiz editor with preview
- AI quiz generation (OpenAI, Google Gemini, GWDG)
- Admin-controlled model enablement
- File/context support (`txt`, `tex`, `pdf`, `doc`, `docx`, `ppt`, `pptx`, learning modules)
- Math rendering via MathJax
- Security controls (validation, rate limiting, API key encryption, response checks)

## Requirements

- ILIAS 10+
- PHP 8.2+
- MySQL/MariaDB
- PHP extensions: `openssl`, `curl`, `json`, `mbstring`

## Installation

1. Place plugin in:
   `public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz`
2. In ILIAS Admin: **Plugins** -> **MarkdownQuiz** -> **Update** -> **Activate**

## Usage (Short)

1. Add object: **Markdown Quiz**
2. Edit questions in **Edit Questions** tab or use **AI Generate**
3. Save and set object online/offline

Question count in AI generation is currently limited to `1..10`.

## Testing

See full runbook in `docs/DEVELOPMENT.md`.

Quick example:

```bash
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_input_validation.php
```

## Documentation

- Architecture: `docs/ARCHITECTURE.md`
- Security: `docs/SECURITY.md`
- Development and testing: `docs/DEVELOPMENT.md`

## License

GNU General Public License v3.0.
