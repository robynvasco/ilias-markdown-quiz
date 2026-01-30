# ILIAS MarkdownQuiz Plugin

Ein intelligentes Quiz-Plugin für ILIAS, das automatisch Multiple-Choice-Fragen aus Markdown-Dokumenten und anderen Dateiformaten mittels KI generiert.

## 🎯 Features

- **KI-gestützte Fragengenerierung**: Automatische Erstellung von Multiple-Choice-Fragen aus hochgeladenen Dokumenten
- **Multi-Format-Unterstützung**: Markdown (.md), Text (.txt), PDF, PowerPoint (.ppt, .pptx), Word (.docx)
- **Mehrere KI-Provider**: 
  - OpenAI (GPT-4, GPT-4-turbo, GPT-3.5-turbo)
  - Google Gemini (gemini-pro, gemini-1.5-pro)
  - GWDG (für deutsche Hochschulen)
- **Online/Offline-Verwaltung**: Flexible Sichtbarkeitssteuerung für Quiz-Objekte
- **Sofortiges Feedback**: Direktes visuelles Feedback zu richtigen und falschen Antworten
- **Moderne UI**: Integration mit ILIAS UI Framework für intuitive Bedienung
- **Mehrsprachig**: Deutsch und Englisch vollständig unterstützt
- **Umfassende Sicherheit**:
  - AES-256-GCM Verschlüsselung für API-Keys
  - Rate Limiting zum Schutz vor Missbrauch
  - XSS-Schutz für alle Benutzereingaben
  - HMAC-Signierung für Antwort-Validierung

## 📋 Voraussetzungen

- **ILIAS**: Version 10 oder höher
- **PHP**: Version 8.2 oder höher
- **MySQL/MariaDB**: Version 5.7+ / 10.2+
- **PHP-Erweiterungen**:
  - `openssl` (für Verschlüsselung)
  - `curl` (für API-Anfragen)
  - `json` (für Datenverarbeitung)
  - `mbstring` (für Textverarbeitung)

## 🚀 Installation

### 1. Plugin herunterladen

```bash
cd /pfad/zu/ilias/Customizing/global/plugins/Services/Repository/RepositoryObject/
git clone https://github.com/robynvasco/ilias-markdown-quiz.git MarkdownQuiz
```

### 2. Datenbank einrichten

```bash
cd MarkdownQuiz
mysql -u [username] -p [database] < sql/dbupdate.php
```

Oder verwenden Sie das ILIAS-Administrations-Interface:
1. Navigieren Sie zu **Administration → Plugins**
2. Suchen Sie das **MarkdownQuiz** Plugin
3. Klicken Sie auf **Aktualisieren** und dann **Aktivieren**

### 3. Plugin konfigurieren

1. Gehen Sie zu **Administration → Plugins → MarkdownQuiz → Konfiguration**
2. Wählen Sie Ihren bevorzugten **KI-Service**:
   - **OpenAI**: API-Key von [platform.openai.com](https://platform.openai.com) einfügen
   - **Google Gemini**: API-Key von [ai.google.dev](https://ai.google.dev) einfügen
   - **GWDG**: Zugangsdaten von Ihrer Institution erhalten
3. Speichern Sie die Konfiguration

## 📖 Verwendung

### Für Dozenten/Trainer

#### Quiz erstellen

1. Navigieren Sie zu Ihrem gewünschten Kurs oder Magazin
2. Klicken Sie **Neues Objekt hinzufügen → MarkdownQuiz**
3. Geben Sie einen **Titel** und optional eine **Beschreibung** ein
4. Klicken Sie **Quiz erstellen**

#### Fragen generieren

1. Öffnen Sie Ihr MarkdownQuiz-Objekt
2. Laden Sie eine Datei hoch (unterstützte Formate: .md, .txt, .pdf, .ppt, .pptx, .docx)
3. Klicken Sie **Fragen generieren**
4. Warten Sie, während die KI die Fragen erstellt (kann 10-30 Sekunden dauern)
5. Überprüfen Sie die generierten Fragen

#### Einstellungen anpassen

- **Online/Offline**: Steuern Sie die Sichtbarkeit für Lernende
  - **Online**: Quiz ist für alle Teilnehmer sichtbar
  - **Offline**: Quiz ist nur für Administratoren/Trainer sichtbar
- **Titel & Beschreibung**: Bearbeiten Sie die Metadaten des Quiz

### Für Lernende

1. Öffnen Sie das MarkdownQuiz-Objekt im Kurs
2. Lesen Sie die Frage sorgfältig durch
3. Wählen Sie eine oder mehrere Antworten (je nach Fragetyp)
4. Erhalten Sie sofortiges Feedback:
   - ✅ **Grün**: Richtige Antwort
   - ❌ **Rot**: Falsche Antwort

## 🔒 Sicherheitsfeatures

Das Plugin implementiert mehrschichtige Sicherheitsmaßnahmen:

### API-Key-Verschlüsselung
- **AES-256-GCM**: Militärische Verschlüsselung für gespeicherte API-Keys
- **Einzigartiger Schlüssel**: Pro ILIAS-Installation individuell generiert
- **Sichere Speicherung**: Verschlüsselte Keys in `ilias.ini.php`

### Rate Limiting
- **Session-basiert**: Schutz vor automatisierten Anfragen
- **Konfigurierbare Limits**: Standard 5 Anfragen pro 60 Sekunden
- **Benutzerfreundlich**: Klare Fehlermeldungen bei Überschreitung

### Input-Validierung
- **XSS-Schutz**: Alle Benutzereingaben werden gefiltert
- **Type Safety**: Strikte PHP-Typisierung in allen Klassen
- **SQL-Injection-Schutz**: Verwendung von Prepared Statements

### Antwort-Validierung
- **HMAC-Signierung**: Manipulationsschutz für Quiz-Antworten
- **Session-Validierung**: Schutz vor CSRF-Angriffen

## 🏗️ Architektur

Das Plugin folgt dem ILIAS Repository Object Pattern:

```
MarkdownQuiz/
├── classes/
│   ├── class.ilObjMarkdownQuiz.php          # Datenmodell
│   ├── class.ilObjMarkdownQuizGUI.php       # UI Controller
│   ├── class.ilObjMarkdownQuizAccess.php    # Zugriffssteuerung
│   ├── class.ilObjMarkdownQuizListGUI.php   # Listen-Ansicht
│   ├── class.ilMarkdownQuizPlugin.php       # Plugin-Einstieg
│   └── AI/
│       ├── ilMarkdownQuizAIService.php      # KI-Basis-Service
│       ├── ilMarkdownQuizOpenAIService.php  # OpenAI-Integration
│       ├── ilMarkdownQuizGeminiService.php  # Gemini-Integration
│       └── ilMarkdownQuizGWDGService.php    # GWDG-Integration
├── lang/                                     # Sprachdateien (de/en)
├── sql/                                      # Datenbank-Setup
├── templates/                                # UI-Templates
├── docs/                                     # Erweiterte Dokumentation
└── test/                                     # Unit Tests

```

Detaillierte Architektur-Dokumentation finden Sie in [CODE_STRUCTURE.md](CODE_STRUCTURE.md).

## 🧪 Testing

```bash
# Unit Tests ausführen
cd test/
php run_tests.php

# Spezifische Tests
php run_tests.php --filter testQuizGeneration
```

## 🔧 Konfiguration

### Globale Einstellungen (Administration)

| Einstellung | Beschreibung | Standard |
|------------|--------------|----------|
| **KI-Service** | Welcher Anbieter verwendet werden soll | OpenAI |
| **API-Key** | Verschlüsselter Zugriffsschlüssel | - |
| **Modell** | Spezifisches KI-Modell (z.B. gpt-4) | gpt-4 |
| **Rate Limit** | Max. Anfragen pro Zeitfenster | 5/60s |

### Objekt-Einstellungen (pro Quiz)

| Einstellung | Beschreibung | Standard |
|------------|--------------|----------|
| **Online** | Sichtbarkeit für Lernende | Online |
| **Titel** | Name des Quiz-Objekts | - |
| **Beschreibung** | Detaillierte Erklärung | - |

## 🐛 Troubleshooting

### "Rate limit exceeded"
- **Ursache**: Zu viele Anfragen in kurzer Zeit
- **Lösung**: Warten Sie 60 Sekunden und versuchen Sie es erneut

### "API key not configured"
- **Ursache**: Kein gültiger API-Key hinterlegt
- **Lösung**: Gehen Sie zu Administration → Plugins → MarkdownQuiz → Konfiguration

### "Failed to generate questions"
- **Ursache**: KI-Service nicht erreichbar oder Datei zu groß
- **Lösung**: 
  - Überprüfen Sie Ihre Internetverbindung
  - Reduzieren Sie die Dateigröße (empfohlen: < 5 MB)
  - Versuchen Sie ein anderes Dateiformat

### Quiz zeigt keine Fragen
- **Ursache**: Generierung noch nicht abgeschlossen oder fehlgeschlagen
- **Lösung**: 
  - Überprüfen Sie die ILIAS-Logs unter `data/logs/`
  - Regenerieren Sie die Fragen mit "Fragen generieren"

## 📄 Lizenz

Dieses Plugin ist unter der **GNU General Public License v3.0** lizenziert.

Siehe [LICENSE](LICENSE) für Details.

## 👤 Autor

**Robyn Vasco**
- GitHub: [@robynvasco](https://github.com/robynvasco)


## 📝 Changelog

### Version 1.0.0 (Januar 2026)
- ✨ Initiales Release
- ✨ Multi-Format-Unterstützung (MD, TXT, PDF, PPT, DOCX)
- ✨ Drei KI-Provider (OpenAI, Gemini, GWDG)
- ✨ Online/Offline-Verwaltung
- ✨ Umfassende Sicherheitsfeatures
- ✨ Mehrsprachige Unterstützung (DE/EN)


## 📞 Support

Bei Fragen oder Problemen:
1. Überprüfen Sie die [CODE_STRUCTURE.md](CODE_STRUCTURE.md) Dokumentation
2. Durchsuchen Sie die [Issues](https://github.com/robynvasco/ilias-markdown-quiz/issues)
3. Erstellen Sie ein neues Issue mit detaillierter Beschreibung

---

