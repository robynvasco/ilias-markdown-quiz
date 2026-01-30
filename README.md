# ILIAS MarkdownQuiz Plugin

An intelligent quiz plugin for ILIAS that automatically generates multiple-choice questions from Markdown documents and other file formats using AI.

## 🎯 Features

- **AI-powered Question Generation**: Automatic creation of multiple-choice questions from uploaded documents
- **Multi-Format Support**: Markdown (.md), Text (.txt), PDF, PowerPoint (.ppt, .pptx), Word (.docx)
- **Multiple AI Providers**: 
  - OpenAI (GPT-4, GPT-4-turbo, GPT-3.5-turbo)
  - Google Gemini (gemini-pro, gemini-1.5-pro)
  - GWDG (for German universities)
- **Online/Offline Management**: Flexible visibility control for quiz objects
- **Instant Feedback**: Direct visual feedback on correct and incorrect answers
- **Modern UI**: Integration with ILIAS UI Framework for intuitive operation
- **Multilingual**: Fully supported in German and English
- **Comprehensive Security**:
  - AES-256-GCM encryption for API keys
  - Rate limiting to protect against abuse
  - XSS protection for all user inputs
  - HMAC signing for answer validation

## 📋 Requirements

- **ILIAS**: Version 10 or higher
- **PHP**: Version 8.2 or higher
- **MySQL/MariaDB**: Version 5.7+ / 10.2+
- **PHP Extensions**:
  - `openssl` (for encryption)
  - `curl` (for API requests)
  - `json` (for data processing)
  - `mbstring` (for text processing)

## 🚀 Installation

1. Create subdirectories, if necessary for `public/Customizing/global/plugins/Services/Repository/RepositoryObject/`
2. Navigate to `public/Customizing/global/plugins/Services/Repository/RepositoryObject/`
3. Execute:

```bash
git clone https://github.com/robynvasco/ilias-markdown-quiz.git ./MarkdownQuiz
```

4. In ILIAS, navigate to **Administration → Plugins**
5. Find the **MarkdownQuiz** plugin
6. Click **Update** and then **Activate**

### Configure Plugin

1. Go to **Administration → Plugins → MarkdownQuiz → Configuration**
2. Select your preferred **AI Service**:
   - **OpenAI**: Enter API key from [platform.openai.com](https://platform.openai.com)
   - **Google Gemini**: Enter API key from [ai.google.dev](https://ai.google.dev)
   - **GWDG**: Obtain credentials from your institution
3. Save the configuration

## 📖 Usage

### For Instructors/Trainers

#### Create Quiz

1. Navigate to your desired course or repository
2. Click **Add New Object → MarkdownQuiz**
3. Enter a **Title** and optionally a **Description**
4. Click **Create Quiz**

#### Generate Questions

1. Open your MarkdownQuiz object
2. Upload a file (supported formats: .md, .txt, .pdf, .ppt, .pptx, .docx)
3. Click **Generate Questions**
4. Wait while the AI creates the questions (may take 10-30 seconds)
5. Review the generated questions

#### Adjust Settings

- **Online/Offline**: Control visibility for learners
  - **Online**: Quiz is visible to all participants
  - **Offline**: Quiz is only visible to administrators/trainers
- **Title & Description**: Edit the quiz metadata

### For Learners

1. Open the MarkdownQuiz object in the course
2. Read the question carefully
3. Select one or more answers (depending on question type)
4. Receive instant feedback:
   - ✅ **Green**: Correct answer
   - ❌ **Red**: Incorrect answer

## 🔒 Security Features

The plugin implements multi-layered security measures:

### API Key Encryption
- **AES-256-GCM**: Military-grade encryption for stored API keys
- **Unique Key**: Individually generated per ILIAS installation
- **Secure Storage**: Encrypted keys in `ilias.ini.php`

### Rate Limiting
- **Session-based**: Protection against automated requests
- **Configurable Limits**: Default 5 requests per 60 seconds
- **User-friendly**: Clear error messages when exceeded

### Input Validation
- **XSS Protection**: All user inputs are filtered
- **Type Safety**: Strict PHP typing in all classes
- **SQL Injection Protection**: Use of prepared statements

### Answer Validation
- **HMAC Signing**: Tamper protection for quiz answers
- **Session Validation**: Protection against CSRF attacks

## 🏗️ Architecture

The plugin follows the ILIAS Repository Object Pattern:

```
MarkdownQuiz/
├── classes/
│   ├── class.ilObjMarkdownQuiz.php          # Data model
│   ├── class.ilObjMarkdownQuizGUI.php       # UI Controller
│   ├── class.ilObjMarkdownQuizAccess.php    # Access control
│   ├── class.ilObjMarkdownQuizListGUI.php   # List view
│   ├── class.ilMarkdownQuizPlugin.php       # Plugin entry point
│   └── AI/
│       ├── ilMarkdownQuizAIService.php      # AI base service
│       ├── ilMarkdownQuizOpenAIService.php  # OpenAI integration
│       ├── ilMarkdownQuizGeminiService.php  # Gemini integration
│       └── ilMarkdownQuizGWDGService.php    # GWDG integration
├── lang/                                     # Language files (de/en)
├── sql/                                      # Database setup
├── templates/                                # UI templates
├── docs/                                     # Extended documentation
└── test/                                     # Unit tests
```

Detailed architecture documentation can be found in [CODE_STRUCTURE.md](CODE_STRUCTURE.md).

## 🧪 Testing

```bash
# Run unit tests
cd test/
php run_tests.php

# Run specific tests
php run_tests.php --filter testQuizGeneration
```

## 🔧 Configuration

### Global Settings (Administration)

| Setting | Description | Default |
|---------|-------------|---------|
| **AI Service** | Which provider to use | OpenAI |
| **API Key** | Encrypted access key | - |
| **Model** | Specific AI model (e.g. gpt-4) | gpt-4 |
| **Rate Limit** | Max requests per time window | 5/60s |

### Object Settings (per Quiz)

| Setting | Description | Default |
|---------|-------------|---------|
| **Online** | Visibility for learners | Online |
| **Title** | Name of the quiz object | - |
| **Description** | Detailed explanation | - |

## 🐛 Troubleshooting

### "Rate limit exceeded"
- **Cause**: Too many requests in a short time
- **Solution**: Wait 60 seconds and try again

### "API key not configured"
- **Cause**: No valid API key configured
- **Solution**: Go to Administration → Plugins → MarkdownQuiz → Configuration

### "Failed to generate questions"
- **Cause**: AI service unreachable or file too large
- **Solution**: 
  - Check your internet connection
  - Reduce file size (recommended: < 5 MB)
  - Try a different file format

### Quiz shows no questions
- **Cause**: Generation not yet completed or failed
- **Solution**: 
  - Check ILIAS logs under `data/logs/`
  - Regenerate questions with "Generate Questions"

## 📄 License

This plugin is licensed under the **GNU General Public License v3.0**.

See [LICENSE](LICENSE) for details.

## 👤 Author

**Robyn Vasco**
- GitHub: [@robynvasco](https://github.com/robynvasco)

## 📝 Changelog

### Version 1.0.0 (January 2026)
- ✨ Initial release
- ✨ Multi-format support (MD, TXT, PDF, PPT, DOCX)
- ✨ Three AI providers (OpenAI, Gemini, GWDG)
- ✨ Online/Offline management
- ✨ Comprehensive security features
- ✨ Multilingual support (DE/EN)

## 🔮 Roadmap

- [ ] Export/Import of quiz questions
- [ ] Advanced question types (free text, matching)
- [ ] Quiz statistics and analytics
- [ ] Question pool and reusability
- [ ] Integration with ILIAS Test & Assessment
- [ ] Support for additional AI providers

## 📞 Support

For questions or issues:
1. Check the [CODE_STRUCTURE.md](CODE_STRUCTURE.md) documentation
2. Search the [Issues](https://github.com/robynvasco/ilias-markdown-quiz/issues)
3. Create a new issue with detailed description

---

**Made with ❤️ for the ILIAS Community**

