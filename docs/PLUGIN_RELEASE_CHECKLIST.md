# Plugin Release Checklist

Nur plugin-spezifische Punkte, die noch offen sind.

## Vor Release

- [ ] Gewünschte Provider festlegen (z. B. nur OpenAI).
- [ ] Für jeden aktivierten Provider: `test/test_provider_e2e.php` muss PASS sein.
- [ ] Prüfen, dass API-Keys in `xmdq_config` verschlüsselt gespeichert sind (`openai_api_key`, `google_api_key`, `gwdg_api_key`).
- [ ] UI-Smoke-Test im Plugin:
  - [ ] AI-Generierung im Tab `Generate` funktioniert
  - [ ] Ergebnis kann gespeichert und im `View` korrekt angezeigt werden

## Rollback (Plugin)

- [ ] Backup der Plugin-Tabellen vor Deployment (`xmdq_config`, `rep_robj_xmdq_data`).
- [ ] Bei Fehlern: vorherige Plugin-Dateien + diese beiden Tabellen zurückspielen.
