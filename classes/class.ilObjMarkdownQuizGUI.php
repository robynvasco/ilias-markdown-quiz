<?php
declare(strict_types=1);

use ILIAS\UI\Factory;
use ILIAS\UI\Renderer;
use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizFileSecurity;
use platform\ilMarkdownQuizXSSProtection;
use platform\ilMarkdownQuizRateLimiter;
use ai\ilMarkdownQuizGoogleAI;
use ai\ilMarkdownQuizGWDG;
use ai\ilMarkdownQuizOpenAI;

require_once __DIR__ . '/platform/class.ilMarkdownQuizConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownQuizFileSecurity.php';
require_once __DIR__ . '/platform/class.ilMarkdownQuizXSSProtection.php';
require_once __DIR__ . '/platform/class.ilMarkdownQuizRateLimiter.php';
require_once __DIR__ . '/ai/class.ilMarkdownQuizLLM.php';
require_once __DIR__ . '/ai/class.ilMarkdownQuizGWDG.php';
require_once __DIR__ . '/ai/class.ilMarkdownQuizGoogleAI.php';
require_once __DIR__ . '/ai/class.ilMarkdownQuizOpenAI.php';

/**
 * MarkdownQuiz GUI Controller
 * 
 * Main controller class handling all user interactions with quiz objects.
 * 
 * Key Features:
 * - View quiz content with markdown rendering
 * - Edit quiz settings (title, online status, content)
 * - Generate quizzes via AI (OpenAI, Google Gemini, GWDG)
 * - File upload and content extraction
 * - Rate limiting and security controls
 * 
 * Security Measures:
 * - Input validation on all user data
 * - XSS protection via HTML escaping
 * - Rate limiting (20 API calls/hour, 20 files/hour, 10s cooldown)
 * - File type whitelist (txt, tex, pdf, doc, docx, ppt, pptx, lm)
 * - SQL injection prevention via type casting
 * 
 * @ilCtrl_isCalledBy ilObjMarkdownQuizGUI: ilRepositoryGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjMarkdownQuizGUI: ilPermissionGUI, ilInfoScreenGUI, ilCommonActionDispatcherGUI
 * 
 */
class ilObjMarkdownQuizGUI extends ilObjectPluginGUI
{
    /** @var Factory ILIAS UI factory for creating UI components */
    private Factory $factory;
    
    /** @var Renderer ILIAS UI renderer for rendering components */
    private Renderer $renderer;
    
    /** @var \ILIAS\Refinery\Factory Data refinement factory for transformations */
    protected \ILIAS\Refinery\Factory $refinery;
    
    /** @var ilLanguage Language service */
    protected ilLanguage $lng;

    /**
     * Command to execute after object creation
     * Redirects to settings to configure the new quiz
     * 
     * @return string Command name
     */
    public function getAfterCreationCmd(): string
    {
        return "settings";
    }

    /**
     * Initialize dependencies after constructor
     * Sets up UI factory, renderer, and language service
     */
    protected function afterConstructor(): void
    {
        global $DIC;
        $this->factory = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->refinery = $DIC->refinery();
        $this->lng = $DIC->language();
    }

    /**
     * Get the object type identifier
     * 
     * @return string Type identifier "xmdq"
     */
    public function getType(): string
    {
        return "xmdq";
    }

    /**
     * Get the default command
     * Command executed when user opens the quiz without specifying an action
     * 
     * @return string Command name "view"
     */
    public function getStandardCmd(): string
    {
        return "view";
    }

    /**
     * Command dispatcher
     * Routes commands to appropriate handler methods
     * 
     * @param string $cmd Command to execute
     */
    public function performCommand(string $cmd): void
    {
        $allowed_commands = ['view', 'settings', 'editQuestions', 'addSampleQuestion', 'generate'];
        if (!in_array($cmd, $allowed_commands, true)) {
            throw new ilException("command not defined");
        }

        $this->checkPermission("read");
        $this->setTitleAndDescription();
        $this->{$cmd}();
    }

    /**
     * Initialize tab structure
     * Creates navigation tabs based on user permissions
     */
    protected function setTabs(): void
    {
        global $DIC;

        $this->tabs->addTab("view", $this->plugin->txt("tab_view"), $DIC->ctrl()->getLinkTarget($this, "view"));

        if ($this->checkPermissionBool("write")) {
            $this->tabs->addTab("settings", $this->plugin->txt("tab_settings"), $DIC->ctrl()->getLinkTarget($this, "settings"));
            $this->tabs->addTab("editQuestions", $this->plugin->txt("tab_edit_questions"), $DIC->ctrl()->getLinkTarget($this, "editQuestions"));

            // Only show AI Generate tab if AI is enabled in admin config
            ilMarkdownQuizConfig::load();
            if (ilMarkdownQuizConfig::get('ai_enabled', true)) {
                $this->tabs->addTab("generate", $this->plugin->txt("tab_ai_generate"), $DIC->ctrl()->getLinkTarget($this, "generate"));
            }
        }

        if ($this->checkPermissionBool("edit_permission")) {
            $this->tabs->addTab(
                "perm_settings",
                $this->lng->txt("perm_settings"),
                $DIC->ctrl()->getLinkTargetByClass([get_class($this), "ilPermissionGUI"], "perm")
            );
        }
    }

    /**
     * Display quiz view (default view for users)
     * 
     * Renders the quiz content with markdown formatting and interactive features.
     * 
     * Security:
     * - Sets Content Security Policy headers
     * - Sanitizes content before rendering
     * - Escapes HTML to prevent XSS
     */
    public function view(): void
    {
        $this->tabs->activateTab("view");
        ilMarkdownQuizXSSProtection::setCSPHeaders();

        $raw_content = $this->object->getMarkdownContent();
        
        // Check if quiz is empty
        if (empty($raw_content) || trim($raw_content) === '') {
            // Show friendly message for empty quiz
            $html_output = $this->getModernStyles() . "
                <div class='quiz-empty-state'>
                    <h3>" . $this->plugin->txt("view_no_content_title") . "</h3>
                    <p>" . $this->plugin->txt("view_no_content_text") . "</p>
                </div>";
        } else {
            // SECURITY: Protect content before rendering
            try {
                $protected_content = ilMarkdownQuizXSSProtection::protectContent($raw_content);
                $html_output = $this->getModernStyles() . $this->renderQuiz($protected_content);
            } catch (\Exception $e) {
                // Show validation error (e.g., malformed markdown)
                $html_output = $this->getModernStyles() .
                              "<div class='quiz-error'>" .
                              "<strong>" . $this->plugin->txt("view_invalid_format") . "</strong> " .
                              ilMarkdownQuizXSSProtection::escapeHTML($e->getMessage()) .
                              "</div>";

                if ($this->checkPermissionBool("write")) {
                    $html_output .= "<p>" . $this->plugin->txt("view_fix_content") . "</p>";
                }
            }
        }

        $this->tpl->setContent($html_output);
    }

    /**
     * Settings form handler
     * 
     * Displays and processes the settings form for:
     * - Quiz title
     * - Online/offline status
     * - Markdown content
     * 
     * Uses ILIAS UI components with transformations for automatic saving
     */
    public function settings(): void
    {
        $this->checkPermission("write");
        $this->tabs->activateTab("settings");

        $form = $this->buildSettingsForm();

        if ($this->request->getMethod() === "POST") {
            $form = $form->withRequest($this->request);
            $data = $form->getData();
            if ($data !== null) {
                // Reload object to ensure we have the latest data
                $this->object->read();
                
                // Data already saved via transformations
                $this->tpl->setOnScreenMessage('success', $this->plugin->txt('settings_saved'));

                // Rebuild form with fresh data
                $form = $this->buildSettingsForm();
            }
        }

        $html = $this->getFormStyles();
        $html .= "<div class='quiz-form-wrapper'>";
        $html .= "<div class='quiz-form-header'><h2>" . $this->plugin->txt('settings_title') . "</h2></div>";
        $html .= $this->renderer->render($form);
        $html .= "</div>";
        
        $this->tpl->setContent($html);
    }

    /**
     * Edit Questions Tab
     * Clean interface for editing all questions in markdown format
     */
    public function editQuestions(): void
    {
        global $DIC;
        $this->checkPermission("write");
        $this->tabs->activateTab("editQuestions");

        // Handle POST request
        if ($this->request->getMethod() === 'POST') {
            // CSRF protection: ILIAS 10 uses session-based token validation automatically
            // The token is validated by the framework when using ilCtrl::getFormAction()

            $post_data = $this->request->getParsedBody();
            $markdown_content = (string)($post_data['markdown_content'] ?? '');

            // Validate markdown content exists
            if (isset($post_data['markdown_content'])) {
                try {
                    $sanitized_content = ilMarkdownQuizXSSProtection::sanitizeMarkdown($markdown_content);
                    if (trim($sanitized_content) !== '') {
                        ilMarkdownQuizXSSProtection::validateMarkdownStructure($sanitized_content);
                    }

                    $this->object->setMarkdownContent($sanitized_content);
                    $this->object->update();

                    $this->tpl->setOnScreenMessage('success', $this->plugin->txt('edit_saved'));
                } catch (\Exception $e) {
                    $this->tpl->setOnScreenMessage(
                        'failure',
                        $this->plugin->txt('edit_error_prefix') . $e->getMessage()
                    );
                }
            }
            $this->ctrl->redirect($this, 'editQuestions');
        }

        // Modern styling
        $html = $this->getMathJaxScript();
        $html .= $this->getEditQuestionsStyles();
        $html .= "<div class='quiz-edit-wrapper'>";

        // Compact header with dropdown and save button
        $question_count = $this->countQuestions($this->object->getMarkdownContent());

        $html .= "<div class='quiz-edit-header'>";
        $html .= "<div class='quiz-edit-info'>";
        $html .= "<h2>" . $this->plugin->txt('edit_title') . "</h2>";
        $html .= "<span class='quiz-question-count'>" . $question_count . " " . $this->plugin->txt('edit_questions_count') . "</span>";
        $html .= "</div>";

        $html .= "<div class='quiz-edit-actions'>";

        // Dropdown for sample questions
        $html .= "<div class='quiz-sample-dropdown' id='sampleDropdown'>";
        $html .= "<button type='button' class='quiz-sample-btn' id='sampleDropdownBtn'>" . $this->plugin->txt('edit_btn_add_question') . "</button>";
        $html .= "<div class='quiz-sample-menu' id='sampleMenu'>";
        $html .= "<a href='" . $DIC->ctrl()->getLinkTarget($this, "addSampleQuestion") . "&type=single' class='quiz-sample-item'>" . $this->plugin->txt('edit_sample_single') . "</a>";
        $html .= "<a href='" . $DIC->ctrl()->getLinkTarget($this, "addSampleQuestion") . "&type=multiple' class='quiz-sample-item'>" . $this->plugin->txt('edit_sample_multiple') . "</a>";
        $html .= "</div>";
        $html .= "</div>";

        // Preview toggle button
        $html .= "<button type='button' class='quiz-preview-toggle-btn' id='previewToggleBtn'>" . $this->plugin->txt('edit_btn_preview') . "</button>";

        // Save button
        $html .= "<button type='button' class='quiz-save-btn' id='saveQuestionsBtn'>" . $this->plugin->txt('edit_btn_save') . "</button>";

        $html .= "</div>";
        $html .= "</div>";

        // Custom HTML form (no ILIAS form system)
        // CSRF protection is handled automatically by ilCtrl::getFormAction() in ILIAS 10
        $form_action = $DIC->ctrl()->getFormAction($this, 'editQuestions');
        // Encode content for textarea: htmlspecialchars for XSS safety, then encode
        // curly braces as HTML entities to prevent ILIAS template engine from stripping them
        $current_content = htmlspecialchars($this->object->getMarkdownContent());
        $current_content = str_replace(['{', '}'], ['&#123;', '&#125;'], $current_content);

        $html .= "<form method='post' action='{$form_action}' id='questionsForm'>";
        $html .= "<textarea name='markdown_content' id='questionsTextarea'>{$current_content}</textarea>";
        $html .= "</form>";

        // LaTeX preview panel
        $html .= "<div id='latexPreviewPanel' class='quiz-latex-preview' style='display:none;'></div>";

        $html .= "</div>";

        $previewLabel = $this->plugin->txt('edit_btn_preview');
        $editorLabel = $this->plugin->txt('edit_btn_editor');
        $this->tpl->addOnLoadCode("
            (function() {
                // Dropdown functionality
                var dropdownBtn = document.getElementById('sampleDropdownBtn');
                var menu = document.getElementById('sampleMenu');
                var dropdown = document.getElementById('sampleDropdown');

                if (dropdownBtn && menu && dropdown) {
                    dropdownBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        menu.classList.toggle('show');
                    });

                    document.addEventListener('click', function(event) {
                        if (!dropdown.contains(event.target)) {
                            menu.classList.remove('show');
                        }
                    });
                }

                // Save button functionality
                var saveBtn = document.getElementById('saveQuestionsBtn');
                var form = document.getElementById('questionsForm');

                if (saveBtn && form) {
                    saveBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        form.submit();
                    });
                }

                // LaTeX Preview toggle
                var previewBtn = document.getElementById('previewToggleBtn');
                var textarea = document.getElementById('questionsTextarea');
                var preview = document.getElementById('latexPreviewPanel');
                var isPreview = false;

                function escapeHtml(str) {
                    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                }

                function convertLatexDelimiters(text) {
                    text = text.replace(/\\$\\$(.+?)\\$\\$/gs, '\\\\[$1\\\\]');
                    text = text.replace(/(?<!\\$)\\$(?!\\s)([^\\$]+?)(?<!\\s)\\$(?!\\$)/g, '\\\\($1\\\\)');
                    return text;
                }

                function renderPreview(content) {
                    var lines = content.split('\\n');
                    var html = '';
                    var qNum = 0;

                    lines.forEach(function(line) {
                        var trimmed = line.trim();
                        if (!trimmed) return;

                        if (trimmed.match(/^-\\s*\\[x\\]/i)) {
                            var text = trimmed.replace(/^-\\s*\\[x\\]\\s*/i, '');
                            html += '<div class=\"preview-option preview-correct\">' + convertLatexDelimiters(escapeHtml(text)) + '</div>';
                        } else if (trimmed.match(/^-\\s*\\[\\s\\]/)) {
                            var text = trimmed.replace(/^-\\s*\\[\\s\\]\\s*/, '');
                            html += '<div class=\"preview-option\">' + convertLatexDelimiters(escapeHtml(text)) + '</div>';
                        } else {
                            qNum++;
                            html += '<h4 class=\"preview-question\">' + convertLatexDelimiters(escapeHtml(trimmed)) + '</h4>';
                        }
                    });
                    return html;
                }

                if (previewBtn && textarea && preview && form) {
                    previewBtn.addEventListener('click', function() {
                        isPreview = !isPreview;
                        if (isPreview) {
                            form.style.display = 'none';
                            preview.style.display = 'block';
                            previewBtn.textContent = " . json_encode($editorLabel) . ";
                            previewBtn.classList.add('active');
                            preview.innerHTML = renderPreview(textarea.value);
                            if (window.MathJax && MathJax.typesetPromise) {
                                MathJax.typesetPromise([preview]);
                            } else if (window.MathJax && MathJax.Hub) {
                                MathJax.Hub.Queue(['Typeset', MathJax.Hub, preview]);
                            }
                        } else {
                            form.style.display = 'block';
                            preview.style.display = 'none';
                            previewBtn.textContent = " . json_encode($previewLabel) . ";
                            previewBtn.classList.remove('active');
                        }
                    });
                }
            })();
        ");

        $this->tpl->setContent($html);
    }

    /**
     * Count questions in markdown content
     * Counts any non-empty line that is followed by answer options (lines starting with -)
     */
    private function countQuestions(string $markdown): int
    {
        if (empty(trim($markdown))) {
            return 0;
        }

        $lines = explode("\n", $markdown);
        $question_count = 0;
        $in_question = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines
            if (empty($trimmed)) {
                continue;
            }

            // Check if this is an answer option (starts with -)
            if (str_starts_with($trimmed, '-')) {
                // If we were in a question, this confirms it
                if ($in_question) {
                    $question_count++;
                    $in_question = false;
                }
            } else {
                // This is potential question text
                $in_question = true;
            }
        }

        return $question_count;
    }

    /**
     * Add a single sample question based on type
     */
    public function addSampleQuestion(): void
    {
        global $DIC;

        $query_params = $this->request->getQueryParams();
        $type = $query_params['type'] ?? 'single';

        // Validate type - only allow single and multiple
        $allowed_types = ['single', 'multiple'];
        if (!in_array($type, $allowed_types, true)) {
            $type = 'single';
        }

        $sample_question = '';

        switch ($type) {
            case 'single':
                $sample_question = $this->plugin->txt('sample_single_question') . "
- [ ] " . $this->plugin->txt('sample_answer_wrong') . "
- [x] " . $this->plugin->txt('sample_answer_right') . "
- [ ] " . $this->plugin->txt('sample_answer_wrong') . "
- [ ] " . $this->plugin->txt('sample_answer_wrong');
                break;

            case 'multiple':
                $sample_question = $this->plugin->txt('sample_multiple_question') . "
- [x] " . $this->plugin->txt('sample_answer_right') . "
- [x] " . $this->plugin->txt('sample_answer_right') . "
- [ ] " . $this->plugin->txt('sample_answer_wrong') . "
- [x] " . $this->plugin->txt('sample_answer_right');
                break;
        }

        $current_content = $this->object->getMarkdownContent();

        if (!empty($current_content)) {
            $new_content = trim($current_content) . "\n\n" . $sample_question;
        } else {
            $new_content = $sample_question;
        }

        $this->object->setMarkdownContent($new_content);
        $this->object->update();

        $this->tpl->setOnScreenMessage('success', $this->plugin->txt('edit_sample_added'));
        $DIC->ctrl()->redirect($this, 'editQuestions');
    }

    /**
     * Get clean styles for edit questions view
     */
    private function getEditQuestionsStyles(): string
    {
        return <<<'CSS'
<style>
    .quiz-edit-wrapper {
        max-width: 1000px;
        margin: 0 auto;
        padding: 24px;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    }

    /* Compact header */
    .quiz-edit-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid #e5e9f0;
    }

    .quiz-edit-info {
        display: flex;
        align-items: baseline;
        gap: 12px;
    }

    .quiz-edit-info h2 {
        font-size: 20px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
    }

    .quiz-question-count {
        font-size: 13px;
        color: #7f8ea3;
        font-weight: 500;
    }

    /* Action buttons container */
    .quiz-edit-actions {
        display: flex;
        gap: 12px;
        align-items: center;
    }

    /* Sample dropdown */
    .quiz-sample-dropdown {
        position: relative;
    }

    .quiz-sample-btn {
        background: #f0f4f8;
        color: #5a7894;
        border: 1px solid #e5e9f0;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .quiz-sample-btn:hover {
        background: #e5ebf1;
        border-color: #c8d3de;
    }

    /* Save button in header */
    .quiz-save-btn {
        background: linear-gradient(135deg, #5a7894 0%, #7b93b0 100%);
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
    }

    .quiz-save-btn:hover {
        background: linear-gradient(135deg, #4a6884 0%, #6b83a0 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(90, 120, 148, 0.25);
    }

    .quiz-save-btn:active {
        transform: translateY(0);
    }

    .quiz-sample-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        margin-top: 4px;
        background: white;
        border: 1px solid #e5e9f0;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        min-width: 180px;
        z-index: 1000;
    }

    .quiz-sample-menu.show {
        display: block;
    }

    .quiz-sample-item {
        display: block;
        padding: 10px 16px;
        color: #2c3e50;
        text-decoration: none;
        font-size: 13px;
        transition: background 0.15s;
        border-bottom: 1px solid #f0f4f8;
    }

    .quiz-sample-item:first-child {
        border-radius: 8px 8px 0 0;
    }

    .quiz-sample-item:last-child {
        border-bottom: none;
        border-radius: 0 0 8px 8px;
    }

    .quiz-sample-item:hover {
        background: #f8fafb;
        text-decoration: none;
    }

    /* Custom form styling */
    .quiz-edit-wrapper form {
        width: 100%;
        margin: 0;
        padding: 0;
    }

    /* Textarea styling */
    #questionsTextarea {
        display: block;
        width: 100%;
        box-sizing: border-box;
        font-family: 'SF Mono', Monaco, Consolas, monospace;
        font-size: 14px;
        line-height: 1.8;
        min-height: 650px;
        padding: 20px;
        border: 2px solid #e5e9f0;
        border-radius: 8px;
        background: white;
        color: #2c3e50;
        resize: vertical;
        transition: border-color 0.2s ease;
    }

    #questionsTextarea:hover {
        border-color: #c8d3de;
    }

    #questionsTextarea:focus {
        border-color: #5a7894;
        outline: none;
    }

    /* Preview toggle button */
    .quiz-preview-toggle-btn {
        background: #f0f4f8;
        color: #5a7894;
        border: 1px solid #e5e9f0;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .quiz-preview-toggle-btn:hover {
        background: #e5ebf1;
        border-color: #c8d3de;
    }

    .quiz-preview-toggle-btn.active {
        background: #5a7894;
        color: white;
        border-color: #5a7894;
    }

    /* LaTeX preview panel */
    .quiz-latex-preview {
        background: white;
        border: 2px solid #e5e9f0;
        border-radius: 8px;
        padding: 24px;
        min-height: 650px;
    }

    .quiz-latex-preview .preview-question {
        font-size: 16px;
        font-weight: 600;
        color: #2c3e50;
        margin: 20px 0 8px 0;
        padding-top: 12px;
        border-top: 1px solid #f0f4f8;
    }

    .quiz-latex-preview .preview-question:first-child {
        margin-top: 0;
        padding-top: 0;
        border-top: none;
    }

    .quiz-latex-preview .preview-option {
        font-size: 14px;
        color: #4a5568;
        padding: 6px 12px;
        margin: 4px 0 4px 16px;
        border-radius: 4px;
        background: #f8fafb;
    }

    .quiz-latex-preview .preview-correct {
        background: #f0fdf4;
        color: #166534;
        font-weight: 500;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .quiz-edit-wrapper {
            padding: 16px;
        }

        .quiz-edit-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }

        .quiz-edit-info h2 {
            font-size: 18px;
        }

        .quiz-edit-actions {
            width: 100%;
            justify-content: flex-start;
        }
    }
</style>
CSS;
    }


    /**
     * Build the settings form
     *
     * Creates a form with inline save functionality using transformations:
     * - Each field saves immediately when form is submitted
     * - Uses ILIAS UI components for modern interface
     * - Handles title and online status only
     *
     * @return \ILIAS\UI\Component\Input\Container\Form\Form The configured form
     */
    private function buildSettingsForm(): \ILIAS\UI\Component\Input\Container\Form\Form
    {
        // Set form action to explicitly point back to settings command
        $form_action = $this->ctrl->getFormAction($this, 'settings');

        $title_field = $this->factory->input()->field()->text($this->plugin->txt('form_quiz_title'))
            ->withValue((string)$this->object->getTitle())
            ->withAdditionalTransformation(
                $this->refinery->custom()->transformation(
                    function ($v) {
                        $this->object->setTitle(trim($v));
                        $this->object->update();
                        return $v;
                    }
                )
            )->withRequired(true);

        $online_field = $this->factory->input()->field()->checkbox($this->plugin->txt('form_online_label'), $this->plugin->txt('form_online_desc'))
            ->withValue($this->object->getOnline())
            ->withAdditionalTransformation(
                $this->refinery->custom()->transformation(
                    function ($v) {
                        // Checkbox returns true when checked, null/false when unchecked
                        $is_online = ($v === true || $v === 1 || $v === "1");
                        $this->object->setOnline($is_online);
                        $this->object->update();
                        return $is_online;
                    }
                )
            );

        $form_fields = [
            'title' => $title_field,
            'online' => $online_field
        ];

        $form = $this->factory->input()->container()->form()->standard(
            $form_action,
            $form_fields
        );

        return $form;
    }

    /**
     * AI Quiz Generation View
     * 
     * Main interface for generating quizzes using AI services.
     * 
     * Features:
     * - Supports OpenAI, Google Gemini, and GWDG Academic Cloud
     * - Prompt input with 5000 char limit
     * - Optional context field (10000 chars) or file selection
     * - Difficulty selection (easy, medium, hard, mixed)
     * - Question count (1-10)
     * - Pre-fills last used values for convenience
     * 
     * Security:
     * - Rate limiting enforced
     * - Input validation
     * - File type whitelist
     */
    public function generate(): void
    {
        global $DIC;
        
        $this->checkPermission("write");
        $this->tabs->activateTab("generate");

        ilMarkdownQuizConfig::load();

        // Build list of enabled models for user dropdown
        $available_services = ilMarkdownQuizConfig::get('available_services');
        if (!is_array($available_services)) {
            $available_services = [];
        }

        $enabled_models = ilMarkdownQuizConfig::get('enabled_models');
        if (!is_array($enabled_models)) {
            $enabled_models = [];
        }

        $registry = ilMarkdownQuizConfigGUI::getModelRegistry();
        $provider_labels = ['gwdg' => 'GWDG', 'google' => 'Google', 'openai' => 'OpenAI'];
        $model_options = [];

        // Iterate over registry to preserve defined order (fastest first)
        foreach ($registry as $model_id => $info) {
            if (!isset($enabled_models[$model_id])) {
                continue;
            }
            $provider = $info['provider'];
            if (!empty($available_services[$provider])) {
                $api_key = ilMarkdownQuizConfig::get($provider . '_api_key');
                if (!empty($api_key)) {
                    $label = $this->plugin->txt($info['label_key']);
                    $model_options[$model_id] = $label . ' (' . ($provider_labels[$provider] ?? $provider) . ')';
                }
            }
        }

        $config_complete = !empty($model_options);

        if (!$config_complete) {
            $info = $this->factory->messageBox()->info(
                $this->plugin->txt('ai_config_incomplete')
            );
            $html = $this->getFormStyles();
            $html .= "<div class='quiz-form-wrapper'>";
            $html .= "<div class='quiz-form-header'><h2>" . $this->plugin->txt('ai_title') . "</h2><p>" . $this->plugin->txt('ai_subtitle') . "</p></div>";
            $html .= $this->renderer->render($info);
            $html .= "</div>";
            $this->tpl->setContent($html);
            return;
        }

        $form_action = $this->ctrl->getLinkTargetByClass("ilObjMarkdownQuizGUI", "generate");
        
        // Load last used prompt from this quiz object
        $last_prompt = $this->object->getLastPrompt() ?: 'Generate a quiz about ';
        
        $prompt_field = $this->factory->input()->field()->textarea($this->plugin->txt('ai_label_prompt'), $this->plugin->txt('ai_desc_prompt'))
            ->withValue($last_prompt)
            ->withRequired(true);

        $question_type_field = $this->factory->input()->field()->select($this->plugin->txt('ai_label_question_type'), [
            "single" => $this->plugin->txt('ai_option_type_single'),
            "multiple" => $this->plugin->txt('ai_option_type_multiple'),
            "mixed" => $this->plugin->txt('ai_option_type_mixed')
        ])->withValue("single")->withRequired(true);

        $difficulty_field = $this->factory->input()->field()->select($this->plugin->txt('ai_label_difficulty'), [
            "easy" => $this->plugin->txt('ai_option_easy'),
            "medium" => $this->plugin->txt('ai_option_medium'),
            "hard" => $this->plugin->txt('ai_option_hard'),
            "mixed" => $this->plugin->txt('ai_option_mixed')
        ])->withValue("medium")->withRequired(true);

        $question_count_options = [];
        for ($i = 1; $i <= 10; $i++) {
            $question_count_options[(string)$i] = (string)$i;
        }
        $question_count_field = $this->factory->input()->field()->select(
            $this->plugin->txt('ai_label_question_count'),
            $question_count_options,
            $this->plugin->txt('ai_desc_question_count')
        )->withValue('3')->withRequired(true);

        $context_field = $this->factory->input()->field()->textarea(
            $this->plugin->txt('ai_label_context'),
            $this->plugin->txt('ai_desc_context')
        )->withValue($this->object->getLastContext());

        // Get available files from parent container
        $available_files = $this->getAvailableFiles();

        // Validate saved file ref_id - reset if deleted or inaccessible
        $saved_ref_id = $this->object->getLastFileRefId();
        if ($saved_ref_id > 0 && !isset($available_files[(string)$saved_ref_id])) {
            // File was deleted or is no longer accessible, reset to 0
            $saved_ref_id = 0;
            $this->object->setLastFileRefId(0);
            $this->object->update();
        }
        
        if (!empty($available_files)) {
            $file_ref_field = $this->factory->input()->field()->select(
                $this->plugin->txt('ai_label_file'),
                $available_files,
                $this->plugin->txt('ai_desc_file')
            )->withRequired(false);
        } else {
            $file_ref_field = $this->factory->input()->field()->numeric(
                $this->plugin->txt('ai_label_file_ref'),
                $this->plugin->txt('ai_desc_file_ref')
            )->withRequired(false);
        }

        $first_model = array_key_first($model_options);
        $model_field = $this->factory->input()->field()->select(
            $this->plugin->txt('ai_label_model'),
            $model_options,
            $this->plugin->txt('ai_desc_model')
        )->withValue($first_model)->withRequired(true);

        $form = $this->factory->input()->container()->form()->standard(
            $form_action,
            [
                'model' => $model_field,
                'prompt' => $prompt_field,
                'question_type' => $question_type_field,
                'difficulty' => $difficulty_field,
                'question_count' => $question_count_field,
                'context' => $context_field,
                'file_ref_id' => $file_ref_field
            ]
        )->withSubmitLabel($this->plugin->txt('ai_btn_generate'));

        if ($this->request->getMethod() == "POST") {
            $form = $form->withRequest($this->request);
            $data = $form->getData();
            
            
            if ($data) {
                try {
                    // RATE LIMIT: Check quiz generation cooldown
                    ilMarkdownQuizRateLimiter::recordQuizGeneration();
                    
                    // RATE LIMIT: Increment concurrent request counter
                    ilMarkdownQuizRateLimiter::incrementConcurrent();
                    
                    // SECURITY: Validate and sanitize inputs
                    $prompt = ilMarkdownQuizXSSProtection::sanitizeUserInput($data['prompt'], 5000);
                    $original_prompt = $prompt;

                    $question_type = $data['question_type'];
                    $difficulty = $data['difficulty'];
                    $question_count = (int)$data['question_count'];

                    // Append question type instruction to prompt
                    $type_instructions = [
                        'single' => "\n\n[QUESTION TYPE: Single-choice only. Each question must have EXACTLY ONE correct answer marked with [x].]",
                        'multiple' => "\n\n[QUESTION TYPE: Multiple-choice only. Each question must have TWO or MORE correct answers marked with [x].]",
                        'mixed' => "\n\n[QUESTION TYPE: Mix of single-choice and multiple-choice. Aim for ~60% single-choice (one [x]) and ~40% multiple-choice (two or more [x]).]"
                    ];
                    $prompt .= $type_instructions[$question_type] ?? $type_instructions['mixed'];

                    // Validate difficulty and question count
                    if (!ilMarkdownQuizXSSProtection::validateDifficulty($difficulty)) {
                        throw new \Exception($this->plugin->txt('ai_error_difficulty'));
                    }
                    if (!ilMarkdownQuizXSSProtection::validateQuestionCount($question_count)) {
                        throw new \Exception($this->plugin->txt('ai_error_question_count'));
                    }
                    
                    // Get context from textarea or file
                    $context = ilMarkdownQuizXSSProtection::sanitizeUserInput($data['context'] ?? '', 10000);
                    $context_to_save = $context;

                    // If file ref_id provided, fetch file content
                    if (!empty($data['file_ref_id']) && $data['file_ref_id'] > 0) {
                        $file_context = $this->getFileContent((int)$data['file_ref_id']);
                        if (!empty($file_context)) {
                            $context .= ($context ? "\n\n" : "") . $file_context;
                        }
                    }


                    $markdown = $this->generateMarkdownQuiz(
                        $prompt,
                        $difficulty,
                        $question_count,
                        $context,
                        $data['model'] ?? ''
                    );

                    // SECURITY: Protect generated content before storing
                    $markdown = ilMarkdownQuizXSSProtection::protectContent($markdown);

                    if (empty($markdown)) {
                        ilMarkdownQuizRateLimiter::decrementConcurrent();
                        $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('ai_error_no_content'));
                    } else {
                        // Get existing content and append new questions
                        $existing_content = $this->object->getMarkdownContent();

                        if (!empty($existing_content)) {
                            // Append new questions to existing ones
                            $combined_content = trim($existing_content) . "\n\n" . trim($markdown);
                            $this->object->setMarkdownContent($combined_content);
                        } else {
                            // No existing content, just set the new content
                            $this->object->setMarkdownContent($markdown);
                        }

                        $this->object->setLastPrompt($original_prompt);
                        $this->object->setLastDifficulty($difficulty);
                        $this->object->setLastQuestionCount($question_count);
                        $this->object->setLastContext($context_to_save);
                        $this->object->setLastFileRefId((int)($data['file_ref_id'] ?? 0));
                        $this->object->update();

                        ilMarkdownQuizRateLimiter::decrementConcurrent();
                        $this->tpl->setOnScreenMessage('success', $this->plugin->txt('ai_success'));
                        $this->ctrl->redirect($this, 'editQuestions');
                        return;
                    }
                } catch (\Exception $e) {
                    ilMarkdownQuizRateLimiter::decrementConcurrent();
                    $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('ai_error_prefix') . $e->getMessage());
                }
            }
        }

        $html = $this->getFormStyles();
        $html .= "<div class='quiz-form-wrapper'>";
        $html .= "<div class='quiz-form-header'><h2>" . $this->plugin->txt('ai_title') . "</h2><p>" . $this->plugin->txt('ai_subtitle') . "</p></div>";
        $html .= $this->renderer->render($form);
        $html .= "</div>";
        $html .= $this->getLoadingOverlay();

        $this->tpl->setContent($html);
    }

    /**
     * Get available files from parent container and nearby objects
     */
    private function logFileProcessingError(string $context, \Throwable $e, array $extra = []): void
    {
        $details = empty($extra) ? '' : ' ' . json_encode($extra);
        error_log('MarkdownQuiz file processing error [' . $context . ']: ' . $e->getMessage() . $details);
    }

    /**
     * Get available files from parent container and nearby objects
     */
    private function getAvailableFiles(): array
    {
        global $DIC;
        
        $files = [];
        
        // Supported file extensions
        $supported_extensions = ['txt', 'tex', 'pdf', 'doc', 'docx', 'ppt', 'pptx'];
        
        try {
            // Get parent ref_id
            $parent_ref_id = $DIC->repositoryTree()->getParentId($this->object->getRefId());
            
            if ($parent_ref_id > 0) {
                // Get files
                $children = $DIC->repositoryTree()->getChildsByType($parent_ref_id, 'file');
                
                foreach ($children as $child) {
                    $ref_id = $child['ref_id'];
                    
                    // Check read permission
                    if ($DIC->access()->checkAccess('read', '', $ref_id)) {
                        $obj_id = ilObject::_lookupObjectId($ref_id);
                        $title = ilObject::_lookupTitle($obj_id);
                        
                        // Get file info
                        try {
                            $file_obj = new ilObjFile($obj_id, false);
                            $size_kb = round($file_obj->getFileSize() / 1024, 2);
                            $ext = strtolower($file_obj->getFileExtension());
                            
                            // Only include supported file types
                            if (in_array($ext, $supported_extensions)) {
                                $files[$ref_id] = "$title ($ext, $size_kb KB)";
                            }
                        } catch (\Exception $e) {
                            $this->logFileProcessingError('getAvailableFiles:file', $e, ['ref_id' => $ref_id]);
                            continue;
                        }
                    }
                }
                
                // Get learning modules
                $lms = $DIC->repositoryTree()->getChildsByType($parent_ref_id, 'lm');
                
                foreach ($lms as $lm) {
                    $ref_id = $lm['ref_id'];
                    
                    if ($DIC->access()->checkAccess('read', '', $ref_id)) {
                        $obj_id = ilObject::_lookupObjectId($ref_id);
                        $title = ilObject::_lookupTitle($obj_id);
                        
                        $files[$ref_id] = "$title [Learning Module]";
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logFileProcessingError('getAvailableFiles', $e);
        }
        
        // Filter out any empty keys/values that might cause UI issues
        $files = array_filter($files, function($key, $value) {
            return !empty($key) && $key !== '' && $key !== '-' && !empty($value);
        }, ARRAY_FILTER_USE_BOTH);

        return $files;
    }

    /**
     * Get content from an ILIAS File object
     */
    private function getFileContent(int $ref_id): string
    {
        try {
            // RATE LIMIT: Check file processing limit
            ilMarkdownQuizRateLimiter::recordFileProcessing();
            
            global $DIC;
            
            // Check if object exists and is a file
            if (!ilObject::_exists($ref_id, true)) {
                return '';
            }
            
            $type = ilObject::_lookupType($ref_id, true);
            
            // Check read permission
            if (!$DIC->access()->checkAccess('read', '', $ref_id)) {
                return '';
            }
            
            $obj_id = ilObject::_lookupObjectId($ref_id);
            
            // Handle Learning Module
            if ($type === 'lm') {
                return $this->getLearningModuleContent($obj_id);
            }
            
            // Handle File
            if ($type !== 'file') {
                return '';
            }
            
            $file_obj = new ilObjFile($obj_id, false);
            
            // Get file via resource storage
            $resource_id_string = $file_obj->getResourceId();
            $resource_identification = $DIC->resourceStorage()->manage()->find($resource_id_string);
            
            if (!$resource_identification) {
                return '';
            }
            
            // Use consume to get stream
            $stakeholder = new ilObjMarkdownQuizStakeholder();
            $stream = $DIC->resourceStorage()->consume()->stream($resource_identification)->getStream();
            $content = $stream->getContents();
            
            // SECURITY: Validate file size
            $suffix = strtolower($file_obj->getFileExtension());
            ilMarkdownQuizFileSecurity::validateFileSize($content);
            
            // Try to extract text based on file type
            
            if ($suffix === 'txt' || $suffix === 'tex') {
                return $content;
            } elseif ($suffix === 'pdf') {
                return $this->extractTextFromPDF($content);
            } elseif (in_array($suffix, ['ppt', 'pptx'])) {
                return $this->extractTextFromPowerPoint($content, $suffix);
            } elseif (in_array($suffix, ['doc', 'docx'])) {
                return $this->extractTextFromWord($content, $suffix);
            } else {
                // Unsupported file type
                throw new \Exception(
                    "Unsupported file type: {$suffix}. Supported types: txt, tex, pdf, doc, docx, ppt, pptx"
                );
            }
            
        } catch (\Exception $e) {
            $this->logFileProcessingError('getFileContent', $e, ['ref_id' => $ref_id]);
            return '';
        }
    }
    
    /**
     * Get content from Learning Module pages
     */
    private function getLearningModuleContent(int $obj_id): string
    {
        try {
            $lm_obj = new ilObjLearningModule($obj_id, false);
            $text = '';
            
            // Get all pages
            $pages = ilLMPageObject::getPageList($obj_id);

            foreach ($pages as $page) {
                $page_obj = new ilLMPageObject($lm_obj, $page['obj_id']);
                $page_xml = $page_obj->getPageObject()->getXMLContent();

                // Extract text from XML/HTML content
                $page_text = $this->extractTextFromHTML($page_xml);

                if (!empty($page_text)) {
                    $text .= $page['title'] . ": " . $page_text . "\n\n";
                }
            }

            // Limit length
            if (strlen($text) > 8000) {
                $text = substr($text, 0, 8000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            $this->logFileProcessingError('getLearningModuleContent', $e, ['obj_id' => $obj_id]);
            return '';
        }
    }
    
    /**
     * Extract text from HTML/XML content
     */
    private function extractTextFromHTML(string $html): string
    {
        // Remove script and style tags
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        
        // Strip all HTML tags
        $text = strip_tags($html);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Extract text from PDF content
     */
    private function extractTextFromPDF(string $content): string
    {
        try {
            // SECURITY: Set timeout and validate file
            ilMarkdownQuizFileSecurity::setProcessingTimeout();
            ilMarkdownQuizFileSecurity::validateFile($content, 'pdf');
            
            $text = '';
            
            // Extract text from Tj and TJ operators (text showing operators in PDF)
            // Look for patterns like: (text string) Tj or [(text) (string)] TJ
            if (preg_match_all('/\(([^)]*)\)\s*T[jJ*\']/', $content, $matches)) {
                foreach ($matches[1] as $match) {
                    $decoded = $this->decodePDFString($match);
                    if (!empty(trim($decoded))) {
                        $text .= $decoded . ' ';
                    }
                }
            }
            
            // Also look for text in array format: [(text1) (text2)] TJ
            if (preg_match_all('/\[\s*\((.*?)\)\s*\]\s*TJ/', $content, $matches)) {
                foreach ($matches[1] as $match) {
                    $decoded = $this->decodePDFString($match);
                    if (!empty(trim($decoded))) {
                        $text .= $decoded . ' ';
                    }
                }
            }
            
            // Fallback: If still empty, try to extract readable text from anywhere
            if (empty($text)) {
                // Look for any parenthesized content that looks like text
                if (preg_match_all('/\(([A-Za-z0-9äöüÄÖÜß\s,\.;:\-?!]{3,})\)/', $content, $matches)) {
                    foreach ($matches[1] as $match) {
                        $decoded = $this->decodePDFString($match);
                        if (!empty(trim($decoded))) {
                            $text .= $decoded . ' ';
                        }
                    }
                }
            }
            
            // Clean up
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            // Limit length
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            $this->logFileProcessingError('extractTextFromPDF', $e);
            return '';
        }
    }
    
    /**
     * Decode PDF string (handle escape sequences)
     */
    private function decodePDFString(string $str): string
    {
        // Handle common PDF escape sequences
        $str = str_replace(['\\n', '\\r', '\\t', '\\\\', '\\(', '\\)'], ["\n", "\r", "\t", "\\", "(", ")"], $str);
        // Remove octal codes
        $str = preg_replace('/\\\\[0-7]{1,3}/', '', $str);
        return $str;
    }
    
    /**
     * Extract text from PowerPoint content (.pptx)
     */
    private function extractTextFromPowerPoint(string $content, string $format): string
    {
        try {
            // SECURITY: Set timeout and validate file size
            ilMarkdownQuizFileSecurity::setProcessingTimeout();
            ilMarkdownQuizFileSecurity::validateFileSize($content);
            
            if ($format !== 'pptx') {
                return '';
            }

            // Save content to temp file (PPTX is a ZIP archive)
            $temp_file = tempnam(sys_get_temp_dir(), 'mdquiz_pptx_');
            file_put_contents($temp_file, $content);

            // SECURITY: Validate ZIP file (magic bytes, compression ratio, virus scan)
            ilMarkdownQuizFileSecurity::validateFile($content, 'pptx', $temp_file);
            
            $text = '';
            
            // Open PPTX as ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($temp_file) === true) {
                // Extract text from all slides
                for ($i = 1; $i <= 100; $i++) { // Try up to 100 slides
                    $slide_path = "ppt/slides/slide{$i}.xml";
                    $slide_content = $zip->getFromName($slide_path);

                    if ($slide_content === false) {
                        break; // No more slides
                    }

                    // Parse XML and extract text
                    $slide_text = $this->extractTextFromPowerPointXML($slide_content);
                    if (!empty($slide_text)) {
                        $text .= "Slide $i: " . $slide_text . "\n\n";
                    }
                }

                $zip->close();
            }
            
            // Clean up temporary file
            unlink($temp_file);
            
            // Clean up whitespace (preserve single newlines for slide breaks)
            $text = preg_replace('/[^\S\n]+/', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);
            $text = trim($text);

            // Limit length (avoid cutting inside a $...$ formula)
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000);
                $dollar_count = substr_count($text, '$');
                if ($dollar_count % 2 !== 0) {
                    $last_dollar = strrpos($text, '$');
                    if ($last_dollar !== false) {
                        $text = substr($text, 0, $last_dollar);
                    }
                }
                $text = trim($text) . '...';
            }

            return $text;
        } catch (\Exception $e) {
            $this->logFileProcessingError('extractTextFromPowerPoint', $e, ['format' => $format]);
            return '';
        }
    }

    /**
     * Extract text and formulas from PowerPoint slide XML.
     * Formulas (OMML) are converted to LaTeX notation.
     */
    private function extractTextFromPowerPointXML(string $xml): string
    {
        try {
            // Disable external entity loading to prevent XXE attacks
            $previous_value = libxml_disable_entity_loader(true);

            // Parse XML with security flags
            $dom = new DOMDocument();
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_DTDLOAD | LIBXML_DTDATTR);

            // Restore previous setting
            libxml_disable_entity_loader($previous_value);

            if (!$loaded) {
                throw new Exception('Failed to parse PowerPoint XML');
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/officeDocument/2006/math');

            $text = '';

            // Iterate paragraphs to preserve text and formula ordering
            $paragraphs = $xpath->query('//a:p');
            foreach ($paragraphs as $p_node) {
                $para_text = $this->extractPptxParagraphContent($p_node, $xpath);
                if (!empty(trim($para_text))) {
                    $text .= trim($para_text) . "\n";
                }
            }

            // Fallback: if no paragraphs found, extract all a:t text
            if (empty(trim($text))) {
                $text_nodes = $xpath->query('//a:t');
                foreach ($text_nodes as $node) {
                    $text .= $node->textContent . ' ';
                }
            }

            return trim($text);
        } catch (\Exception $e) {
            $this->logFileProcessingError('extractTextFromPowerPointXML', $e);
            return '';
        }
    }

    /**
     * Recursively extract text and formulas from a PowerPoint paragraph node.
     * Walks the DOM tree in document order to keep formulas inline with text.
     */
    private function extractPptxParagraphContent(\DOMNode $node, \DOMXPath $xpath): string
    {
        $result = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            // a:t — text content
            if ($child->localName === 't' && $child->namespaceURI === 'http://schemas.openxmlformats.org/drawingml/2006/main') {
                $result .= $child->textContent;
            }
            // m:oMath — formula
            elseif ($child->localName === 'oMath' && $child->namespaceURI === 'http://schemas.openxmlformats.org/officeDocument/2006/math') {
                $latex = trim($this->convertOmmlToLatex($child, $xpath));
                if (!empty($latex)) {
                    $result .= ' $' . $latex . '$ ';
                }
            }
            // Recurse into other elements (a:r, mc:AlternateContent, etc.)
            else {
                $result .= $this->extractPptxParagraphContent($child, $xpath);
            }
        }
        return $result;
    }
    
    /**
     * Extract text from Word content (.docx)
     */
    private function extractTextFromWord(string $content, string $format): string
    {
        try {
            // SECURITY: Set timeout and validate file size
            ilMarkdownQuizFileSecurity::setProcessingTimeout();
            ilMarkdownQuizFileSecurity::validateFileSize($content);
            
            if ($format !== 'docx') {
                return '';
            }
            
            // Save content to temp file (DOCX is a ZIP archive)
            $temp_file = tempnam(sys_get_temp_dir(), 'mdquiz_docx_');
            file_put_contents($temp_file, $content);
            
            // SECURITY: Validate ZIP file (magic bytes, compression ratio, virus scan)
            ilMarkdownQuizFileSecurity::validateFile($content, 'docx', $temp_file);
            
            $text = '';
            
            // Open DOCX as ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($temp_file) === true) {
                // Extract text from main document
                $doc_content = $zip->getFromName('word/document.xml');

                if ($doc_content !== false) {
                    $text = $this->extractTextFromWordXML($doc_content);
                }

                $zip->close();
            }
            
            // Clean up temporary file
            unlink($temp_file);
            
            // Clean up whitespace (preserve single newlines for paragraph breaks)
            $text = preg_replace('/[^\S\n]+/', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);
            $text = trim($text);

            // Limit length (avoid cutting inside a $...$ formula)
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000);
                $dollar_count = substr_count($text, '$');
                if ($dollar_count % 2 !== 0) {
                    $last_dollar = strrpos($text, '$');
                    if ($last_dollar !== false) {
                        $text = substr($text, 0, $last_dollar);
                    }
                }
                $text = trim($text) . '...';
            }

            return $text;
        } catch (\Exception $e) {
            $this->logFileProcessingError('extractTextFromWord', $e, ['format' => $format]);
            return '';
        }
    }

    /**
     * Extract text and formulas from Word document XML.
     * Formulas (OMML) are converted to LaTeX notation.
     */
    private function extractTextFromWordXML(string $xml): string
    {
        try {
            // Disable external entity loading to prevent XXE attacks
            $previous_value = libxml_disable_entity_loader(true);

            // Parse XML with security flags
            $dom = new DOMDocument();
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_DTDLOAD | LIBXML_DTDATTR);

            // Restore previous setting
            libxml_disable_entity_loader($previous_value);

            if (!$loaded) {
                throw new Exception('Failed to parse Word XML');
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/officeDocument/2006/math');

            $text = '';

            // Iterate paragraphs, extracting both text runs and math elements in order
            $paragraph_nodes = $xpath->query('//w:p');
            foreach ($paragraph_nodes as $p_node) {
                $paragraph_text = '';

                foreach ($p_node->childNodes as $child) {
                    // w:r — text run
                    if ($child->localName === 'r' && $child->namespaceURI === 'http://schemas.openxmlformats.org/wordprocessingml/2006/main') {
                        $t_nodes = $xpath->query('.//w:t', $child);
                        foreach ($t_nodes as $t_node) {
                            $paragraph_text .= $t_node->textContent;
                        }
                    }
                    // m:oMath — formula
                    elseif ($child->localName === 'oMath') {
                        $latex = trim($this->convertOmmlToLatex($child, $xpath));
                        if (!empty($latex)) {
                            $paragraph_text .= ' $' . $latex . '$ ';
                        }
                    }
                    // m:oMathPara — math paragraph (display math)
                    elseif ($child->localName === 'oMathPara') {
                        $latex = trim($this->convertOmmlChildren($child, $xpath, 0));
                        if (!empty($latex)) {
                            $paragraph_text .= ' $$' . $latex . '$$ ';
                        }
                    }
                }

                if (!empty(trim($paragraph_text))) {
                    $text .= trim($paragraph_text) . "\n";
                }
            }

            // Fallback: if no paragraphs found, extract all w:t text
            if (empty(trim($text))) {
                $text_nodes = $xpath->query('//w:t');
                foreach ($text_nodes as $node) {
                    $text .= $node->textContent . ' ';
                }
            }

            return trim($text);
        } catch (\Exception $e) {
            $this->logFileProcessingError('extractTextFromWordXML', $e);
            return '';
        }
    }

    /**
     * Convert an OMML (Office Math) DOM node to LaTeX notation.
     *
     * Handles fractions, superscripts, subscripts, radicals, n-ary operators,
     * delimiters, functions, accents, bars, and equation arrays.
     * Unsupported elements fall back to extracting their m:t text.
     *
     * @param DOMNode $node The OMML node to convert
     * @param DOMXPath $xpath XPath instance with 'm' namespace registered
     * @param int $depth Current recursion depth (max 20)
     * @return string LaTeX representation
     */
    private function convertOmmlToLatex(\DOMNode $node, \DOMXPath $xpath, int $depth = 0): string
    {
        if ($depth > 20) {
            return '';
        }

        // Text node — convert Unicode math symbols to LaTeX
        if ($node->nodeType === XML_TEXT_NODE) {
            return $this->convertUnicodeToLatex($node->textContent);
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $localName = $node->localName;

        switch ($localName) {
            // m:t — text content (convert Unicode math symbols to LaTeX)
            case 't':
                return $this->convertUnicodeToLatex($node->textContent);

            // m:r — run (contains m:t)
            case 'r':
                $text = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 't') {
                        $text .= $this->convertUnicodeToLatex($child->textContent);
                    }
                }
                return $text;

            // m:f — fraction: \frac{numerator}{denominator}
            case 'f':
                $num = '';
                $den = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'num') {
                        $num = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    } elseif ($child->localName === 'den') {
                        $den = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                return "\\frac{" . trim($num) . "}{" . trim($den) . "}";

            // m:sSup — superscript: {base}^{sup}
            case 'sSup':
                $base = '';
                $sup = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'e') {
                        $base = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    } elseif ($child->localName === 'sup') {
                        $sup = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                return "{" . trim($base) . "}^{" . trim($sup) . "}";

            // m:sSub — subscript: {base}_{sub}
            case 'sSub':
                $base = '';
                $sub = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'e') {
                        $base = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    } elseif ($child->localName === 'sub') {
                        $sub = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                return "{" . trim($base) . "}_{" . trim($sub) . "}";

            // m:sSubSup — subscript + superscript: {base}_{sub}^{sup}
            case 'sSubSup':
                $base = '';
                $sub = '';
                $sup = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'e') {
                        $base = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    } elseif ($child->localName === 'sub') {
                        $sub = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    } elseif ($child->localName === 'sup') {
                        $sup = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                return "{" . trim($base) . "}_{" . trim($sub) . "}^{" . trim($sup) . "}";

            // m:rad — radical: \sqrt{content} or \sqrt[n]{content}
            case 'rad':
                $degree = '';
                $content = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'deg') {
                        $degree = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    } elseif ($child->localName === 'e') {
                        $content = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                $degree = trim($degree);
                if (!empty($degree) && $degree !== '2') {
                    return "\\sqrt[" . $degree . "]{" . trim($content) . "}";
                }
                return "\\sqrt{" . trim($content) . "}";

            // m:nary — n-ary operator (sum, product, integral)
            case 'nary':
                $chr = '∑'; // default
                $sub = '';
                $sup = '';
                $content = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'naryPr') {
                        // Get the operator character
                        foreach ($child->childNodes as $prop) {
                            if ($prop->localName === 'chr') {
                                $chr = $prop->getAttribute('m:val') ?: $prop->getAttribute('val') ?: $chr;
                            }
                        }
                    } elseif ($child->localName === 'sub') {
                        $sub = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    } elseif ($child->localName === 'sup') {
                        $sup = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    } elseif ($child->localName === 'e') {
                        $content = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                $naryMap = [
                    '∑' => '\\sum', '∏' => '\\prod', '∫' => '\\int',
                    '∬' => '\\iint', '∭' => '\\iiint', '∮' => '\\oint',
                    '⋃' => '\\bigcup', '⋂' => '\\bigcap',
                ];
                $op = $naryMap[$chr] ?? '\\sum';
                $result = $op;
                $sub = trim($sub);
                $sup = trim($sup);
                if (!empty($sub)) {
                    $result .= "_{" . $sub . "}";
                }
                if (!empty($sup)) {
                    $result .= "^{" . $sup . "}";
                }
                return $result . " " . trim($content);

            // m:d — delimiter (parentheses, brackets, etc.)
            case 'd':
                $begChr = '(';
                $endChr = ')';
                $content = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'dPr') {
                        foreach ($child->childNodes as $prop) {
                            if ($prop->localName === 'begChr') {
                                $begChr = $prop->getAttribute('m:val') ?: $prop->getAttribute('val') ?: '(';
                            }
                            if ($prop->localName === 'endChr') {
                                $endChr = $prop->getAttribute('m:val') ?: $prop->getAttribute('val') ?: ')';
                            }
                        }
                    } elseif ($child->localName === 'e') {
                        if (!empty($content)) {
                            $content .= ', ';
                        }
                        $content .= $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                return "\\left" . $begChr . " " . trim($content) . " \\right" . $endChr;

            // m:func — function (sin, cos, log, etc.)
            case 'func':
                $funcName = '';
                $arg = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'fName') {
                        $funcName = trim($this->convertOmmlChildren($child, $xpath, $depth + 1));
                    } elseif ($child->localName === 'e') {
                        $arg = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                $knownFuncs = ['sin', 'cos', 'tan', 'log', 'ln', 'exp', 'lim', 'max', 'min', 'det'];
                if (in_array(strtolower($funcName), $knownFuncs)) {
                    return "\\" . strtolower($funcName) . "{" . trim($arg) . "}";
                }
                return $funcName . "(" . trim($arg) . ")";

            // m:acc — accent (hat, vec, dot, etc.)
            case 'acc':
                $chr = '^'; // default hat
                $content = '';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'accPr') {
                        foreach ($child->childNodes as $prop) {
                            if ($prop->localName === 'chr') {
                                $chr = $prop->getAttribute('m:val') ?: $prop->getAttribute('val') ?: '^';
                            }
                        }
                    } elseif ($child->localName === 'e') {
                        $content = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                $accMap = [
                    '̂' => '\\hat', '^' => '\\hat', '˜' => '\\tilde', '̃' => '\\tilde',
                    '→' => '\\vec', '⃗' => '\\vec', '̇' => '\\dot', '˙' => '\\dot',
                    '̈' => '\\ddot', '¨' => '\\ddot', '¯' => '\\bar', '̄' => '\\bar',
                ];
                $cmd = $accMap[$chr] ?? '\\hat';
                return $cmd . "{" . trim($content) . "}";

            // m:bar — overline/underline
            case 'bar':
                $content = '';
                $pos = 'top';
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'barPr') {
                        foreach ($child->childNodes as $prop) {
                            if ($prop->localName === 'pos') {
                                $pos = $prop->getAttribute('m:val') ?: $prop->getAttribute('val') ?: 'top';
                            }
                        }
                    } elseif ($child->localName === 'e') {
                        $content = $this->convertOmmlChildren($child, $xpath, $depth + 1);
                    }
                }
                return ($pos === 'bot' ? '\\underline' : '\\overline') . "{" . trim($content) . "}";

            // m:eqArr — equation array (multi-line)
            case 'eqArr':
                $lines = [];
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'e') {
                        $lines[] = trim($this->convertOmmlChildren($child, $xpath, $depth + 1));
                    }
                }
                return implode(" \\\\ ", $lines);

            // m:m — matrix
            case 'm':
                $rows = [];
                foreach ($node->childNodes as $child) {
                    if ($child->localName === 'mr') {
                        $cells = [];
                        foreach ($child->childNodes as $cell) {
                            if ($cell->localName === 'e') {
                                $cells[] = trim($this->convertOmmlChildren($cell, $xpath, $depth + 1));
                            }
                        }
                        $rows[] = implode(' & ', $cells);
                    }
                }
                if (!empty($rows)) {
                    return "\\begin{pmatrix} " . implode(" \\\\ ", $rows) . " \\end{pmatrix}";
                }
                return '';

            // m:oMath — math container
            case 'oMath':
                return $this->convertOmmlChildren($node, $xpath, $depth + 1);

            // m:oMathPara — math paragraph container
            case 'oMathPara':
                return $this->convertOmmlChildren($node, $xpath, $depth + 1);

            // Property elements — skip (contain styling, not math content)
            case 'rPr':
            case 'fPr':
            case 'sSubPr':
            case 'sSupPr':
            case 'sSubSupPr':
            case 'radPr':
            case 'naryPr':
            case 'dPr':
            case 'funcPr':
            case 'accPr':
            case 'barPr':
            case 'eqArrPr':
            case 'mPr':
            case 'ctrlPr':
            case 'oMathParaPr':
                return '';

            // Default: recurse into children
            default:
                return $this->convertOmmlChildren($node, $xpath, $depth);
        }
    }

    /**
     * Convert all child nodes of an OMML element to LaTeX.
     */
    private function convertOmmlChildren(\DOMNode $node, \DOMXPath $xpath, int $depth): string
    {
        $result = '';
        foreach ($node->childNodes as $child) {
            $result .= $this->convertOmmlToLatex($child, $xpath, $depth);
        }
        return $result;
    }

    /**
     * Convert Unicode math symbols to LaTeX commands.
     *
     * OMML stores Greek letters, operators, and other math symbols as Unicode
     * characters in m:t elements. MathJax and LaTeX require backslash commands.
     */
    private function convertUnicodeToLatex(string $text): string
    {
        static $map = [
            // Greek lowercase
            'α' => '\\alpha', 'β' => '\\beta', 'γ' => '\\gamma', 'δ' => '\\delta',
            'ε' => '\\varepsilon', 'ζ' => '\\zeta', 'η' => '\\eta', 'θ' => '\\theta',
            'ι' => '\\iota', 'κ' => '\\kappa', 'λ' => '\\lambda', 'μ' => '\\mu',
            'ν' => '\\nu', 'ξ' => '\\xi', 'π' => '\\pi', 'ρ' => '\\rho',
            'σ' => '\\sigma', 'τ' => '\\tau', 'υ' => '\\upsilon', 'φ' => '\\varphi',
            'χ' => '\\chi', 'ψ' => '\\psi', 'ω' => '\\omega',
            // Greek uppercase
            'Α' => 'A', 'Β' => 'B', 'Γ' => '\\Gamma', 'Δ' => '\\Delta',
            'Θ' => '\\Theta', 'Λ' => '\\Lambda', 'Ξ' => '\\Xi', 'Π' => '\\Pi',
            'Σ' => '\\Sigma', 'Φ' => '\\Phi', 'Ψ' => '\\Psi', 'Ω' => '\\Omega',
            // Operators
            '×' => '\\times', '÷' => '\\div', '±' => '\\pm', '∓' => '\\mp',
            '·' => '\\cdot', '∗' => '\\ast', '∘' => '\\circ',
            // Relations
            '≤' => '\\leq', '≥' => '\\geq', '≠' => '\\neq', '≈' => '\\approx',
            '≡' => '\\equiv', '∝' => '\\propto', '≪' => '\\ll', '≫' => '\\gg',
            '∼' => '\\sim', '≃' => '\\simeq',
            // Arrows
            '→' => '\\rightarrow', '←' => '\\leftarrow', '↔' => '\\leftrightarrow',
            '⇒' => '\\Rightarrow', '⇐' => '\\Leftarrow', '⇔' => '\\Leftrightarrow',
            // Set theory
            '∈' => '\\in', '∉' => '\\notin', '⊂' => '\\subset', '⊃' => '\\supset',
            '⊆' => '\\subseteq', '⊇' => '\\supseteq', '∪' => '\\cup', '∩' => '\\cap',
            '∅' => '\\emptyset',
            // Logic
            '∧' => '\\land', '∨' => '\\lor', '¬' => '\\neg', '∀' => '\\forall',
            '∃' => '\\exists',
            // Calculus / misc
            // Note: √, ∑, ∏, ∫ are NOT mapped here because they need arguments {}.
            // When Word uses them as OMML elements (m:rad, m:nary), the structural
            // handlers produce correct LaTeX with arguments. When they appear as
            // Unicode text, the AI can still understand them directly.
            '∞' => '\\infty', '∂' => '\\partial', '∇' => '\\nabla',
            'ℏ' => '\\hbar', 'ℓ' => '\\ell',
            // Dots
            '…' => '\\ldots', '⋯' => '\\cdots', '⋮' => '\\vdots', '⋱' => '\\ddots',
        ];

        return strtr($text, $map);
    }

    /**
     * @throws ilMarkdownQuizAIException
     */
    private function generateMarkdownQuiz(string $user_prompt, string $difficulty, int $question_count, string $context = '', string $selected_model = ''): string
    {
        // RATE LIMIT: Check API call limit
        ilMarkdownQuizRateLimiter::recordApiCall();

        ilMarkdownQuizConfig::load();

        $ai = null;

        // Determine provider from selected model
        if (!empty($selected_model)) {
            $enabled_models = ilMarkdownQuizConfig::get('enabled_models');
            if (is_array($enabled_models) && isset($enabled_models[$selected_model])) {
                $provider = $enabled_models[$selected_model];
                $api_key = ilMarkdownQuizConfig::get($provider . '_api_key');

                if (!empty($api_key)) {
                    switch ($provider) {
                        case 'openai':
                            $ai = new ilMarkdownQuizOpenAI($api_key, $selected_model);
                            break;
                        case 'google':
                            $ai = new ilMarkdownQuizGoogleAI($api_key, $selected_model);
                            break;
                        case 'gwdg':
                            $ai = new ilMarkdownQuizGWDG($api_key, $selected_model);
                            break;
                    }
                }
            }
        }

        if ($ai === null) {
            throw new ilMarkdownQuizAIException(
                "Selected AI model is not available or not configured"
            );
        }

        // Combine user prompt with context if available
        $full_prompt = $user_prompt;
        if (!empty($context)) {
            $full_prompt .= "\n\n[Additional Context:]\n" . $context;
        }

        return $ai->generateQuiz($full_prompt, $difficulty, $question_count);
    }

    private function renderQuiz(string $markdown_content): string
    {
        // First pass: parse questions to detect multiple choice
        $questions = $this->parseQuestionsForRendering($markdown_content);

        $html = $this->getMathJaxScript();
        $html .= "<div class='quiz-wrapper'>";
        $html .= "<div class='quiz-header'>";
        $html .= "<h2>" . ilMarkdownQuizXSSProtection::escapeHTML($this->object->getTitle()) . "</h2>";
        $html .= "</div>";

        $question_num = 0;
        foreach ($questions as $question) {
            $question_num++;
            $is_multiple_choice = $question['is_multiple_choice'];
            $input_type = $is_multiple_choice ? 'checkbox' : 'radio';
            $safe_name = ilMarkdownQuizXSSProtection::createSafeDataAttribute("q_{$question_num}");

            $html .= "<div class='quiz-question-card' data-multiple='" . ($is_multiple_choice ? 'true' : 'false') . "'>";
            $html .= "<div class='quiz-question-number'>" . $this->plugin->txt('quiz_question_prefix') . " " . $question_num . "</div>";
            $html .= "<h3 class='quiz-question-text'>" . $this->processLatex($question['text']) . "</h3>";
            $html .= "<div class='quiz-options'>";

            foreach ($question['options'] as $option_index => $option) {
                $correct_attr = $option['is_correct'] ? "data-correct='true'" : "data-correct='false'";
                $input_name = $is_multiple_choice ? $safe_name . "_" . $option_index : $safe_name;

                $html .= "<label class='quiz-option'>";
                $html .= "<input type='{$input_type}' name='{$input_name}' {$correct_attr}>";
                $html .= "<span class='quiz-option-text'>" . $this->processLatex($option['text']) . "</span>";
                $html .= "</label>";
            }

            $html .= "</div></div>";
        }

        $html .= "<div class='quiz-actions'>";
        $html .= "<button type='button' class='quiz-btn quiz-btn-primary' onclick='checkQuiz()'>" . $this->plugin->txt('quiz_btn_check') . "</button>";
        $html .= "<button type='button' class='quiz-btn quiz-btn-secondary' onclick='resetQuiz()'>" . $this->plugin->txt('quiz_btn_reset') . "</button>";
        $html .= "</div>";
        $html .= "<div id='quiz-result' class='quiz-result' style='display:none;'></div>";
        $html .= $this->getCheckQuizScript();
        $html .= "</div>";

        return $html;
    }

    /**
     * Escape HTML but keep LaTeX $...$ delimiters intact for MathJax
     */
    private function processLatex(string $text): string
    {
        $text = ilMarkdownQuizXSSProtection::escapeHTML($text);
        // Encode curly braces as HTML entities to prevent ILIAS template engine from stripping them.
        // MathJax reads the rendered DOM text (where &#123; is decoded back to {) so LaTeX still works.
        return str_replace(['{', '}'], ['&#123;', '&#125;'], $text);
    }

    /**
     * MathJax loader: config + script + FOUC prevention
     *
     * 1. Hides quiz text via CSS until MathJax finishes
     * 2. Configures MathJax 2 for $...$ delimiters BEFORE it loads
     * 3. Loads MathJax 2 from CDN
     * 4. Shows content after typesetting completes
     */
    private function getMathJaxScript(): string
    {
        return <<<'HTML'
<style>.quiz-question-text,.quiz-option-text{visibility:hidden}</style>
<script type="text/x-mathjax-config">
MathJax.Hub.Config({
    skipStartupTypeset: true,
    "fast-preview": { disabled: true },
    menuSettings: { assistiveMML: false },
    tex2jax: {
        inlineMath: [['$','$'], ['\\(','\\)']],
        displayMath: [['$$','$$'], ['\\[','\\]']],
        processEscapes: true
    },
    jax: ["input/TeX", "output/SVG"],
    SVG: {
        font: "STIX-Web",
        matchFontHeight: true,
        styles: {
            ".MathJax_SVG svg > g, .MathJax_SVG_Display svg > g": {
                fill: "currentColor",
                stroke: "currentColor"
            }
        }
    },
    messageStyle: "none"
});
MathJax.Hub.Queue(["Typeset", MathJax.Hub]);
MathJax.Hub.Queue(function(){
    var els = document.querySelectorAll('.quiz-question-text,.quiz-option-text');
    for(var i=0;i<els.length;i++) els[i].style.visibility='visible';
});
</script>
<script async src="https://cdn.jsdelivr.net/npm/mathjax@2.7.9/MathJax.js?config=TeX-AMS-MML_SVG,Safe"></script>
HTML;
    }

    /**
     * Parse questions with detection of multiple choice questions
     */
    private function parseQuestionsForRendering(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        $questions = [];
        $current_question = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (empty($trimmed)) {
                continue;
            }

            // Check if this is an answer option (starts with -)
            if (str_starts_with($trimmed, '-')) {
                if ($current_question !== null) {
                    $is_correct = str_contains($line, '[x]');
                    $option_text = trim(preg_replace('/^-\s*\[[x ]\]\s*/i', '', $trimmed));

                    $current_question['options'][] = [
                        'text' => $option_text,
                        'is_correct' => $is_correct
                    ];

                    if ($is_correct) {
                        $current_question['correct_count']++;
                    }
                }
            } else {
                // This is a question line - save previous question if exists
                if ($current_question !== null) {
                    // Determine if multiple choice (more than one correct answer)
                    $current_question['is_multiple_choice'] = $current_question['correct_count'] > 1;
                    $questions[] = $current_question;
                }

                // Start new question
                $current_question = [
                    'text' => $trimmed,
                    'options' => [],
                    'correct_count' => 0,
                    'is_multiple_choice' => false
                ];
            }
        }

        // Add last question
        if ($current_question !== null && !empty($current_question['options'])) {
            $current_question['is_multiple_choice'] = $current_question['correct_count'] > 1;
            $questions[] = $current_question;
        }

        return $questions;
    }

    private function getCheckQuizScript(): string
    {
        // Inject language strings for JavaScript
        $lang_excellent = ilMarkdownQuizXSSProtection::escapeHTML($this->plugin->txt('quiz_result_excellent'));
        $lang_practice = ilMarkdownQuizXSSProtection::escapeHTML($this->plugin->txt('quiz_result_practice'));
        $lang_good = ilMarkdownQuizXSSProtection::escapeHTML($this->plugin->txt('quiz_result_good'));
        $lang_unanswered = ilMarkdownQuizXSSProtection::escapeHTML($this->plugin->txt('quiz_result_unanswered'));

        return <<<JS
<script>
const LANG = {
    excellent: '{$lang_excellent}',
    practice: '{$lang_practice}',
    good: '{$lang_good}',
    unanswered: '{$lang_unanswered}'
};

function checkQuiz() {
    const questions = document.querySelectorAll('.quiz-question-card');
    let correct = 0;
    let total = 0;
    let unanswered = 0;

    questions.forEach(question => {
        const isMultiple = question.getAttribute('data-multiple') === 'true';
        const inputs = question.querySelectorAll('input[type="radio"], input[type="checkbox"]');
        const options = question.querySelectorAll('.quiz-option');
        total++;

        let hasAnswer = false;
        let question_correct = false;

        if (isMultiple) {
            // Multiple choice: all correct must be checked, no incorrect ones
            let allCorrectChecked = true;
            let noIncorrectChecked = true;

            inputs.forEach(input => {
                if (input.checked) {
                    hasAnswer = true;
                    if (input.getAttribute('data-correct') === 'false') {
                        noIncorrectChecked = false;
                    }
                } else {
                    if (input.getAttribute('data-correct') === 'true') {
                        allCorrectChecked = false;
                    }
                }
            });

            question_correct = hasAnswer && allCorrectChecked && noIncorrectChecked;
        } else {
            // Single choice: one correct must be checked
            inputs.forEach(input => {
                if (input.checked) {
                    hasAnswer = true;
                    if (input.getAttribute('data-correct') === 'true') {
                        question_correct = true;
                    }
                }
            });
        }

        if (!hasAnswer) {
            unanswered++;
        }

        // Reset all options
        options.forEach(opt => {
            opt.classList.remove('quiz-option-correct', 'quiz-option-wrong');
        });

        if (question_correct) {
            correct++;
            inputs.forEach((input, idx) => {
                if (input.getAttribute('data-correct') === 'true') {
                    options[idx].classList.add('quiz-option-correct');
                }
            });
        } else if (hasAnswer) {
            inputs.forEach((input, idx) => {
                if (input.getAttribute('data-correct') === 'true') {
                    options[idx].classList.add('quiz-option-correct');
                } else if (input.checked) {
                    options[idx].classList.add('quiz-option-wrong');
                }
            });
        }
    });

    // Show result
    const percentage = total > 0 ? Math.round(correct / total * 100) : 0;
    const resultDiv = document.getElementById('quiz-result');

    let resultClass = 'quiz-result-good';
    let resultText = LANG.excellent;

    if (percentage < 50) {
        resultClass = 'quiz-result-poor';
        resultText = LANG.practice;
    } else if (percentage < 80) {
        resultClass = 'quiz-result-ok';
        resultText = LANG.good;
    }

    resultDiv.className = 'quiz-result ' + resultClass;

    // Build result content using DOM methods (safer than innerHTML)
    resultDiv.textContent = ''; // Clear existing content
    const contentDiv = document.createElement('div');
    contentDiv.className = 'quiz-result-content';

    const titleDiv = document.createElement('div');
    titleDiv.className = 'quiz-result-title';
    titleDiv.textContent = resultText;
    contentDiv.appendChild(titleDiv);

    const scoreDiv = document.createElement('div');
    scoreDiv.className = 'quiz-result-score';
    scoreDiv.textContent = correct + ' of ' + total + ' correct (' + percentage + '%)';
    contentDiv.appendChild(scoreDiv);

    if (unanswered > 0) {
        const hintDiv = document.createElement('div');
        hintDiv.className = 'quiz-result-hint';
        hintDiv.textContent = '(' + unanswered + ' ' + LANG.unanswered + ')';
        contentDiv.appendChild(hintDiv);
    }

    resultDiv.appendChild(contentDiv);
    resultDiv.style.display = 'block';

    // Scroll to result
    resultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function resetQuiz() {
    const inputs = document.querySelectorAll('input[type="radio"], input[type="checkbox"]');
    const options = document.querySelectorAll('.quiz-option');
    const resultDiv = document.getElementById('quiz-result');

    inputs.forEach(input => {
        input.checked = false;
    });

    options.forEach(opt => {
        opt.classList.remove('quiz-option-correct', 'quiz-option-wrong');
    });

    resultDiv.style.display = 'none';
}
</script>
JS;
    }

    private function getFormStyles(): string
    {
        return <<<'CSS'
<style>
    /* Form Wrapper - passt zu Flashcards Design */
    .quiz-form-wrapper {
        max-width: 900px;
        margin: 0 auto;
        padding: 32px 24px;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }
    
    .quiz-form-header {
        margin-bottom: 24px;
        padding-bottom: 20px;
        border-bottom: 1px solid #e5e9f0;
    }
    
    .quiz-form-header h2 {
        font-size: 28px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
    }
    
    .quiz-form-header p {
        font-size: 14px;
        color: #7f8ea3;
        margin: 8px 0 0 0;
    }
    
    /* ILIAS Form Overrides */
    .quiz-form-wrapper .il-standard-form {
        background: white;
        border: 1px solid #e5e9f0;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
    }
    
    .quiz-form-wrapper .il-standard-form-header {
        display: none;
    }
    
    .quiz-form-wrapper .form-group {
        margin-bottom: 20px;
        padding-bottom: 20px;
        border-bottom: 1px solid #f0f4f8;
    }
    
    .quiz-form-wrapper .form-group:last-of-type {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    
    .quiz-form-wrapper label,
    .quiz-form-wrapper .il-input-field > label {
        font-size: 14px !important;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 8px;
        display: block;
    }
    
    .quiz-form-wrapper .form-control,
    .quiz-form-wrapper input[type="text"],
    .quiz-form-wrapper textarea,
    .quiz-form-wrapper select {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e5e9f0;
        border-radius: 8px;
        font-size: 14px !important;
        color: #2c3e50;
        background: #f8fafb;
        transition: all 0.2s ease;
    }
    
    /* Force consistent font size on all ILIAS input elements */
    .quiz-form-wrapper input,
    .quiz-form-wrapper .il-input-field input,
    .quiz-form-wrapper .il-input-field textarea,
    .quiz-form-wrapper .il-input-field select,
    .quiz-form-wrapper .il-input-field,
    .quiz-form-wrapper .il-input-field * {
        font-size: 14px !important;
    }
    
    .quiz-form-wrapper .form-control:focus,
    .quiz-form-wrapper input[type="text"]:focus,
    .quiz-form-wrapper textarea:focus,
    .quiz-form-wrapper select:focus {
        outline: none;
        border-color: #5a7894;
        background: white;
        box-shadow: 0 0 0 3px rgba(90, 120, 148, 0.1);
    }
    
    .quiz-form-wrapper textarea {
        min-height: 200px;
        resize: vertical;
    }
    
    /* Extra large textarea for markdown content */
    .quiz-form-wrapper textarea[name*="md_content"] {
        min-height: 500px;
        font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
        font-size: 14px !important;
        line-height: 1.5;
    }
    
    .quiz-form-wrapper .help-block,
    .quiz-form-wrapper .il-input-field .help-block {
        font-size: 14px !important;
        color: #7f8ea3;
        margin-top: 6px;
    }
    
    /* Checkbox Styling */
    .quiz-form-wrapper input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin-right: 10px;
        accent-color: #5a9e6f;
    }
    
    /* Submit Button */
    .quiz-form-wrapper .btn-primary,
    .quiz-form-wrapper button[type="submit"],
    .quiz-form-wrapper input[type="submit"] {
        background: linear-gradient(135deg, #5a9e6f 0%, #7ab88a 100%);
        color: white;
        border: none;
        padding: 12px 28px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .quiz-form-wrapper .btn-primary:hover,
    .quiz-form-wrapper button[type="submit"]:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(90, 158, 111, 0.25);
    }
    
    /* Info Message */
    .quiz-form-wrapper .alert-info,
    .quiz-form-wrapper .il-message-box-info {
        background: linear-gradient(135deg, rgba(90, 120, 148, 0.08) 0%, rgba(123, 147, 176, 0.08) 100%);
        border: 1px solid rgba(90, 120, 148, 0.2);
        border-radius: 12px;
        padding: 16px 20px;
        color: #5a7894;
        margin-bottom: 20px;
    }
    
    /* Select Dropdown */
    .quiz-form-wrapper select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%235a7894' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
        background-position: right 12px center;
        background-repeat: no-repeat;
        background-size: 16px;
        padding-right: 40px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .quiz-form-wrapper {
            padding: 20px 16px;
        }
        
        .quiz-form-header h2 {
            font-size: 24px;
        }
        
        .quiz-form-wrapper .il-standard-form {
            padding: 16px;
        }
    }
</style>
CSS;
    }

    private function getLoadingOverlay(): string
    {
        $loading_text = ilMarkdownQuizXSSProtection::escapeHTML($this->plugin->txt('ai_loading'));
        $loading_hint = ilMarkdownQuizXSSProtection::escapeHTML($this->plugin->txt('ai_loading_hint'));

        return <<<HTML
<div id="mdquizLoadingOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.85);z-index:10000;justify-content:center;align-items:center;flex-direction:column">
    <div style="text-align:center">
        <div style="width:48px;height:48px;border:4px solid #e5e9f0;border-top-color:#5a9e6f;border-radius:50%;animation:mdquizSpin 0.8s linear infinite;margin:0 auto 20px"></div>
        <p style="font-size:16px;font-weight:600;color:#2c3e50;margin:0 0 8px 0">{$loading_text}</p>
        <p style="font-size:13px;color:#7f8ea3;margin:0">{$loading_hint}</p>
    </div>
</div>
<style>@keyframes mdquizSpin{to{transform:rotate(360deg)}}</style>
<script>
(function(){
    var form = document.querySelector('.quiz-form-wrapper form');
    if (!form) return;
    form.addEventListener('submit', function() {
        var overlay = document.getElementById('mdquizLoadingOverlay');
        if (overlay) overlay.style.display = 'flex';
        var btn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; btn.style.cursor = 'wait'; }
    });
})();
</script>
HTML;
    }

    private function getModernStyles(): string
    {
        return <<<'CSS'
<style>
    /* Quiz Wrapper */
    .quiz-wrapper {
        max-width: 900px;
        margin: 0 auto;
        padding: 32px 24px;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }
    
    /* Quiz Header */
    .quiz-header {
        margin-bottom: 32px;
        padding-bottom: 20px;
        border-bottom: 1px solid #e5e9f0;
    }
    
    .quiz-header h2 {
        font-size: 28px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
    }
    
    /* Question Cards */
    .quiz-question-card {
        background: white;
        border: 1px solid #e5e9f0;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        transition: box-shadow 0.2s ease;
    }
    
    .quiz-question-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }
    
    .quiz-question-number {
        display: inline-block;
        background: linear-gradient(135deg, #5a7894 0%, #7b93b0 100%);
        color: white;
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 12px;
    }
    
    .quiz-question-text {
        font-size: 18px;
        font-weight: 600;
        color: #2c3e50;
        margin: 12px 0 20px 0;
        line-height: 1.5;
    }
    
    /* Options */
    .quiz-options {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .quiz-option {
        display: flex;
        align-items: center;
        padding: 14px 18px;
        background: #f8fafb;
        border: 1px solid #e5e9f0;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
    }
    
    .quiz-option:hover {
        background: #f0f4f8;
        border-color: #c8d3de;
    }
    
    .quiz-option input[type="radio"] {
        margin: 0 12px 0 0;
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #5a7894;
    }

    .quiz-option input[type="checkbox"] {
        margin: 0 12px 0 0;
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #5a7894;
    }

    .quiz-option-text {
        flex: 1;
        color: #2c3e50;
        font-size: 15px;
        line-height: 1.5;
    }
    
    /* Correct/Wrong States */
    .quiz-option-correct {
        background: #eef8f0;
        border-color: #7ab88a;
        border-width: 2px;
    }
    
    .quiz-option-wrong {
        background: #fef3f2;
        border-color: #e89a94;
        border-width: 2px;
    }
    
    /* Action Buttons */
    .quiz-actions {
        display: flex;
        gap: 12px;
        margin-top: 32px;
        padding-top: 24px;
        border-top: 1px solid #e5e9f0;
    }
    
    .quiz-btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
    }
    
    .quiz-btn-primary {
        background: linear-gradient(135deg, #5a9e6f 0%, #7ab88a 100%);
        color: white;
    }
    
    .quiz-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(90, 158, 111, 0.25);
    }
    
    .quiz-btn-secondary {
        background: #f0f4f8;
        color: #5a7894;
    }
    
    .quiz-btn-secondary:hover {
        background: #e5ebf1;
    }
    
    /* Result Display */
    .quiz-result {
        margin-top: 24px;
        padding: 24px;
        border-radius: 12px;
        text-align: center;
        animation: slideIn 0.3s ease;
    }
    
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .quiz-result-good {
        background: linear-gradient(135deg, rgba(90, 158, 111, 0.1) 0%, rgba(122, 184, 138, 0.1) 100%);
        border: 1px solid rgba(90, 158, 111, 0.3);
    }
    
    .quiz-result-ok {
        background: linear-gradient(135deg, rgba(90, 120, 148, 0.1) 0%, rgba(123, 147, 176, 0.1) 100%);
        border: 1px solid rgba(90, 120, 148, 0.3);
    }
    
    .quiz-result-poor {
        background: linear-gradient(135deg, rgba(232, 154, 148, 0.1) 0%, rgba(245, 178, 173, 0.1) 100%);
        border: 1px solid rgba(232, 154, 148, 0.3);
    }
    
    .quiz-result-content {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .quiz-result-title {
        font-size: 20px;
        font-weight: 700;
        color: #2c3e50;
    }
    
    .quiz-result-score {
        font-size: 16px;
        font-weight: 600;
        color: #5a7894;
    }
    
    .quiz-result-hint {
        font-size: 14px;
        color: #7f8ea3;
        font-style: italic;
    }
    
    /* Empty State */
    .quiz-empty-state {
        text-align: center;
        padding: 60px 24px;
        background: #f8fafb;
        border: 1px solid #e5e9f0;
        border-radius: 12px;
    }
    
    .quiz-empty-state h3 {
        font-size: 22px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0 0 12px 0;
    }
    
    .quiz-empty-state p {
        font-size: 15px;
        color: #7f8ea3;
        margin: 0 0 24px 0;
    }
    
    .quiz-hint {
        display: inline-block;
        text-align: left;
        background: white;
        border: 1px solid #e5e9f0;
        border-radius: 8px;
        padding: 16px 20px;
        color: #5a7894;
        font-size: 14px;
        line-height: 1.6;
    }
    
    /* Error State */
    .quiz-error {
        background: #fef3f2;
        border: 1px solid #e89a94;
        border-radius: 12px;
        padding: 20px 24px;
        color: #d84a3f;
        margin: 24px 0;
    }
    
    .quiz-error strong {
        display: block;
        margin-bottom: 8px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .quiz-wrapper {
            padding: 20px 16px;
        }
        
        .quiz-question-card {
            padding: 20px;
        }
        
        .quiz-header h2 {
            font-size: 24px;
        }
        
        .quiz-question-text {
            font-size: 16px;
        }
        
        .quiz-actions {
            flex-direction: column;
        }
        
        .quiz-btn {
            width: 100%;
        }
    }
</style>
CSS;
    }
}
