# Development and Testing

## Local Environment

Typical local setup in this project uses Docker containers:
- `ilias-dev-ilias-1`
- `ilias-dev-db-1`

Start/stop (from workspace root):

```bash
docker-compose up -d
docker-compose down
```

Check status:

```bash
docker ps
```

## Plugin Path in Container

```text
/var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz
```

## Test Commands

Run standalone PHP test scripts via container PHP:

```bash
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_input_validation.php
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_prompt_injection.php
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_encryption.php
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_rate_limiter.php
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_api_security.php
```

Run HTTP integration checks:

```bash
docker exec ilias-dev-ilias-1 bash -lc '
  cd /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz &&
  ./test/security_test.sh "http://localhost" <ref_id> "PHPSESSID=<session>; ilClientId=default"'
```

Note: running `security_test.sh` from host against `http://localhost:8080` depends on local network mapping and can fail with `HTTP 000` if unreachable from that execution context.

## Test File Roles

- `test/test_input_validation.php`: input and markdown validation helpers
- `test/test_prompt_injection.php`: prompt-input handling behavior
- `test/test_encryption.php`: encryption/decryption compatibility
- `test/test_rate_limiter.php`: session rate limiter behavior
- `test/test_api_security.php`: circuit breaker, signer, response validator
- `test/security_test.sh`: black-box HTTP security checks
- `test/reset_system_prompt.php`: maintenance script (not a test)

## Admin and Config Workflow

After code changes affecting plugin classes:

1. Update plugin in ILIAS admin UI.
2. Verify config tabs under MarkdownQuiz plugin settings.
3. Re-run the relevant tests above.

## Documentation Policy (Short)

- Keep `README.md` as entry point only.
- Put architecture details in `docs/ARCHITECTURE.md`.
- Put security details in `docs/SECURITY.md`.
- Put environment and test runbooks in `docs/DEVELOPMENT.md`.
