<?php
declare(strict_types=1);

use ILIAS\UI\Factory;
use ILIAS\UI\Renderer;
use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizException;
use platform\ilMarkdownQuizFileSecurity;
use platform\ilMarkdownQuizXSSProtection;
use platform\ilMarkdownQuizRateLimiter;
use ai\ilMarkdownQuizGoogleAI;
use ai\ilMarkdownQuizGWDG;
use ai\ilMarkdownQuizOpenAI;

require_once __DIR__ . '/platform/class.ilMarkdownQuizConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownQuizException.php';
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
 * - Rate limiting (20 API calls/hour, 20 files/hour, 5s cooldown)
 * - File type whitelist (txt, pdf, doc, docx, ppt, pptx)
 * - SQL injection prevention via type casting
 * 
 * @ilCtrl_isCalledBy ilObjMarkdownQuizGUI: ilRepositoryGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjMarkdownQuizGUI: ilPermissionGUI, ilInfoScreenGUI, ilCommonActionDispatcherGUI
 * 
 * @author  Your Name
 * @version 1.0
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

        $this->tabs->addTab("view", "Quiz View", $DIC->ctrl()->getLinkTarget($this, "view"));

        if ($this->checkPermissionBool("write")) {
            $this->tabs->addTab("settings", "Settings", $DIC->ctrl()->getLinkTarget($this, "settings"));
            
            // Only show AI Generate tab if AI is enabled in admin config
            ilMarkdownQuizConfig::load();
            if (ilMarkdownQuizConfig::get('ai_enabled', true)) {
                $this->tabs->addTab("generate", "AI Generate", $DIC->ctrl()->getLinkTarget($this, "generate"));
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
        // SECURITY: Set CSP headers
        ilMarkdownQuizXSSProtection::setCSPHeaders();
        
        $this->tabs->activateTab("view");
        $raw_content = $this->object->getMarkdownContent();
        
        // Check if quiz is empty
        if (empty($raw_content) || trim($raw_content) === '') {
            // Show friendly message for empty quiz
            $html_output = $this->getModernStyles() . "
                <div class='quiz-empty-state'>
                    <h3>Kein Quiz-Inhalt vorhanden</h3>
                    <p>Dieses Quiz hat noch keinen Inhalt.</p>";
            
            if ($this->checkPermissionBool("write")) {
                $html_output .= "
                    <div class='quiz-hint'>
                        <strong>Erste Schritte:</strong><br>
                        • Nutze <strong>KI-Generator</strong> für automatische Fragenerstellung<br>
                        • Oder erstelle Fragen manuell in den <strong>Einstellungen</strong>
                    </div>";
            }
            
            $html_output .= "</div>";
        } else {
            // SECURITY: Protect content before rendering
            try {
                $protected_content = ilMarkdownQuizXSSProtection::protectContent($raw_content);
                $html_output = $this->getModernStyles() . $this->renderQuiz($protected_content);
            } catch (\Exception $e) {
                // Show validation error (e.g., malformed markdown)
                $html_output = $this->getModernStyles() . 
                              "<div class='quiz-error'>" . 
                              "<strong>Ungültiges Quiz-Format:</strong> " .
                              ilMarkdownQuizXSSProtection::escapeHTML($e->getMessage()) . 
                              "</div>";
                
                if ($this->checkPermissionBool("write")) {
                    $html_output .= "<p>Bitte gehe zu <strong>Einstellungen</strong>, um den Quiz-Inhalt zu korrigieren.</p>";
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
                $this->tpl->setOnScreenMessage('success', 'Settings saved successfully');
                
                // Rebuild form with fresh data
                $form = $this->buildSettingsForm();
            }
        }

        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Build the settings form
     * 
     * Creates a form with inline save functionality using transformations:
     * - Each field saves immediately when form is submitted
     * - Uses ILIAS UI components for modern interface
     * - Handles title, online status, and markdown content
     * 
     * @return \ILIAS\UI\Component\Input\Container\Form\Form The configured form
     */
    private function buildSettingsForm(): \ILIAS\UI\Component\Input\Container\Form\Form
    {
        // Set form action to explicitly point back to settings command
        $form_action = $this->ctrl->getFormAction($this, 'settings');

        $title_field = $this->factory->input()->field()->text("Quiz Title")
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

        $online_field = $this->factory->input()->field()->checkbox("Online", "Make this quiz available to users")
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

        $markdown_field = $this->factory->input()->field()->textarea("Markdown Content")
            ->withValue((string)$this->object->getMarkdownContent())
            ->withAdditionalTransformation(
                $this->refinery->custom()->transformation(
                    function ($v) {
                        $this->object->setMarkdownContent($v);
                        $this->object->update();
                        return $v;
                    }
                )
            )->withRequired(true);
        
        // Increase textarea height for better markdown editing
        $this->tpl->addOnLoadCode("
            document.querySelectorAll('textarea[name*=\"md_content\"]').forEach(function(textarea) {
                textarea.rows = 25;
            });
        ");

        return $this->factory->input()->container()->form()->standard(
            $form_action,
            ['title' => $title_field, 'online' => $online_field, 'md_content' => $markdown_field]
        );
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
     * - Question count (1-20)
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
        
        // Determine active provider from available_services
        $available_services = ilMarkdownQuizConfig::get('available_services');
        if (!is_array($available_services)) {
            $available_services = [];
        }
        
        $provider = null;
        $api_key = null;
        
        if (isset($available_services['openai']) && $available_services['openai']) {
            $provider = 'openai';
            $api_key = ilMarkdownQuizConfig::get('openai_api_key');
        } elseif (isset($available_services['google']) && $available_services['google']) {
            $provider = 'google';
            $api_key = ilMarkdownQuizConfig::get('google_api_key');
        } elseif (isset($available_services['gwdg']) && $available_services['gwdg']) {
            $provider = 'gwdg';
            $api_key = ilMarkdownQuizConfig::get('gwdg_api_key');
        }
        
        $config_complete = !empty($provider) && !empty($api_key);

        if (!$config_complete) {
            $info = $this->factory->messageBox()->info(
                "AI configuration is incomplete. Please contact an administrator to configure the API settings."
            );
            $this->tpl->setContent($this->renderer->render($info));
            return;
        }

        $form_action = $this->ctrl->getLinkTargetByClass("ilObjMarkdownQuizGUI", "generate");
        
        // Load last used prompt from this quiz object
        $last_prompt = $this->object->getLastPrompt() ?: 'Generate a quiz about ';
        
        $prompt_field = $this->factory->input()->field()->textarea("Prompt", "Write your custom prompt for the AI. Use placeholders: {difficulty} and {question_count}")
            ->withValue($last_prompt)
            ->withRequired(true);
        
        $difficulty_field = $this->factory->input()->field()->select("Difficulty Level", [
            "easy" => "Easy - Basic knowledge questions",
            "medium" => "Medium - Intermediate understanding",
            "hard" => "Hard - Advanced concepts",
            "mixed" => "Mixed - Variety of difficulty levels"
        ])->withValue($this->object->getLastDifficulty())->withRequired(true);
        
        $question_count_field = $this->factory->input()->field()->numeric("Number of Questions", "How many questions to generate")
            ->withValue($this->object->getLastQuestionCount())
            ->withAdditionalTransformation(
                $this->refinery->custom()->transformation(fn ($v) => max(1, min(20, (int)$v)))
            )
            ->withRequired(true);
        
        $context_field = $this->factory->input()->field()->textarea(
            "Additional Context (Optional)",
            "Paste content from a PDF or any additional text to provide context for the quiz generation."
        )->withValue($this->object->getLastContext());
        
        // Get available files from parent container
        $available_files = $this->getAvailableFiles();
        
        // DEBUG: Log what's in the array
        error_log("Available files array: " . print_r(array_keys($available_files), true));
        
        // Validate saved file ref_id - reset if deleted or inaccessible
        $saved_ref_id = $this->object->getLastFileRefId();
        if ($saved_ref_id > 0 && !isset($available_files[(string)$saved_ref_id])) {
            // File was deleted or is no longer accessible, reset to 0
            $saved_ref_id = 0;
            $this->object->setLastFileRefId(0);
            $this->object->update();
        }
        
        error_log("Saved ref_id: " . $saved_ref_id);
        
        if (!empty($available_files)) {
            $file_ref_field = $this->factory->input()->field()->select(
                "ILIAS File (Optional)",
                $available_files,
                "Select a file from this course/folder to use its content as context."
            )->withValue((string)$saved_ref_id);
        } else {
            $file_ref_field = $this->factory->input()->field()->numeric(
                "ILIAS File Reference (Optional)",
                "Enter the ref_id of an ILIAS File object to use its content as context."
            )->withValue($saved_ref_id);
        }

        $form = $this->factory->input()->container()->form()->standard(
            $form_action,
            [
                'prompt' => $prompt_field,
                'difficulty' => $difficulty_field,
                'question_count' => $question_count_field,
                'context' => $context_field,
                'file_ref_id' => $file_ref_field
            ]
        );

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
                    $difficulty = $data['difficulty'];
                    $question_count = (int)$data['question_count'];
                    
                    // Validate difficulty and question count
                    if (!ilMarkdownQuizXSSProtection::validateDifficulty($difficulty)) {
                        throw new \Exception("Invalid difficulty level");
                    }
                    if (!ilMarkdownQuizXSSProtection::validateQuestionCount($question_count)) {
                        throw new \Exception("Question count must be between 1 and 20");
                    }
                    
                    // Get context from textarea or file
                    $context = ilMarkdownQuizXSSProtection::sanitizeUserInput($data['context'] ?? '', 10000);
                    
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
                        $context
                    );
                    
                    // SECURITY: Protect generated content before storing
                    $markdown = ilMarkdownQuizXSSProtection::protectContent($markdown);
                    
                    if (empty($markdown)) {
                        ilMarkdownQuizRateLimiter::decrementConcurrent();
                        $this->tpl->setOnScreenMessage('failure', 'AI returned empty content');
                    } else {
                        $this->object->setMarkdownContent($markdown);
                        $this->object->setLastPrompt($prompt);
                        $this->object->setLastDifficulty($difficulty);
                        $this->object->setLastQuestionCount($question_count);
                        $this->object->setLastContext($data['context'] ?? '');
                        $this->object->setLastFileRefId((int)($data['file_ref_id'] ?? 0));
                        $this->object->update();

                        ilMarkdownQuizRateLimiter::decrementConcurrent();
                        $this->tpl->setOnScreenMessage('success', 'Quiz generated successfully!');
                        $this->ctrl->redirect($this, 'settings');
                        return;
                    }
                } catch (\Exception $e) {
                    ilMarkdownQuizRateLimiter::decrementConcurrent();
                    $this->tpl->setOnScreenMessage('failure', 'Error: ' . $e->getMessage());
                }
            }
        }

        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Get available files from parent container and nearby objects
     */
    private function getAvailableFiles(): array
    {
        global $DIC;
        
        $files = [];
        
        // Supported file extensions
        $supported_extensions = ['txt', 'pdf', 'doc', 'docx', 'ppt', 'pptx'];
        
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
                            // Skip files with errors
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
        }
        
        // Filter out any empty keys/values that might cause UI issues
        $files = array_filter($files, function($key, $value) {
            return !empty($key) && $key !== '' && $key !== '-' && !empty($value);
        }, ARRAY_FILTER_USE_BOTH);
        
        // Always add "-- None --" as first option with key "0" (string to avoid ILIAS UI issues)
        return ["0" => "-- None --"] + $files;
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
            
            if ($suffix === 'txt') {
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
                    "Unsupported file type: {$suffix}. Supported types: txt, pdf, doc, docx, ppt, pptx"
                );
            }
            
        } catch (\Exception $e) {
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
            // SECURITY: Validate ZIP file (magic bytes, compression ratio, virus scan)
            ilMarkdownQuizFileSecurity::validateFile($content, 'pptx', $temp_file);
            // Save content to temporary file (PPTX is a ZIP archive)
            $temp_file = tempnam(sys_get_temp_dir(), 'pptx_');
            file_put_contents($temp_file, $content);
            
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
            } else {
            }
            
            // Clean up temporary file
            unlink($temp_file);
            
            // Clean up whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            
            
            // Limit length
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Extract text from PowerPoint slide XML
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
            
            // Get all text elements (a:t tags in PowerPoint XML)
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $text_nodes = $xpath->query('//a:t');
            
            $text = '';
            foreach ($text_nodes as $node) {
                $text .= $node->textContent . ' ';
            }
            
            return trim($text);
        } catch (\Exception $e) {
            return '';
        }
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
                } else {
                }
                
                $zip->close();
            } else {
            }
            
            // Clean up temporary file
            unlink($temp_file);
            
            // Clean up whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            
            
            // Limit length
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Extract text from Word document XML
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
            
            // Get all text elements (w:t tags in Word XML)
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $text_nodes = $xpath->query('//w:t');
            
            $text = '';
            $last_was_paragraph = false;
            
            foreach ($text_nodes as $node) {
                $text .= $node->textContent . ' ';
            }
            
            // Get paragraph breaks for better formatting
            $paragraph_nodes = $xpath->query('//w:p');
            if ($paragraph_nodes->length > 0) {
                // If we have paragraph structure, extract with paragraph breaks
                $text = '';
                foreach ($paragraph_nodes as $p_node) {
                    $p_xpath = new DOMXPath($dom);
                    $p_xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                    
                    // Get text nodes within this paragraph
                    $p_text_nodes = $p_xpath->query('.//w:t', $p_node);
                    $paragraph_text = '';
                    foreach ($p_text_nodes as $t_node) {
                        $paragraph_text .= $t_node->textContent;
                    }
                    
                    if (!empty(trim($paragraph_text))) {
                        $text .= trim($paragraph_text) . "\n";
                    }
                }
            }
            
            return trim($text);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @throws ilMarkdownQuizAIException
     */
    private function generateMarkdownQuiz(string $user_prompt, string $difficulty, int $question_count, string $context = ''): string
    {
        // RATE LIMIT: Check API call limit
        ilMarkdownQuizRateLimiter::recordApiCall();
        
        ilMarkdownQuizConfig::load();

        $available_services = ilMarkdownQuizConfig::get('available_services');
        if (empty($available_services) || !is_array($available_services)) {
            $available_services = [];
        }
        
        $ai = null;

        // Try OpenAI first if available
        if (isset($available_services['openai']) && $available_services['openai']) {
            $api_key = ilMarkdownQuizConfig::get('openai_api_key');
            $model = ilMarkdownQuizConfig::get('openai_model') ?: 'gpt-4o-mini';

            if (!empty($api_key)) {
                $ai = new ilMarkdownQuizOpenAI($api_key, $model);
            }
        }

        // Try Google if OpenAI not available
        if ($ai === null && isset($available_services['google']) && $available_services['google']) {
            $api_key = ilMarkdownQuizConfig::get('google_api_key');

            if (!empty($api_key)) {
                $ai = new ilMarkdownQuizGoogleAI($api_key, 'gemini-2.5-flash');
            }
        }

        // Fall back to GWDG if available
        if ($ai === null && isset($available_services['gwdg']) && $available_services['gwdg']) {
            $api_key = ilMarkdownQuizConfig::get('gwdg_api_key');
            $models = ilMarkdownQuizConfig::get('gwdg_models');

            if (!empty($api_key) && !empty($models) && is_array($models)) {
                // Get the first available model
                $model_id = array_key_first($models);
                $ai = new ilMarkdownQuizGWDG($api_key, $model_id);
            }
        }

        if ($ai === null) {
            throw new ilMarkdownQuizAIException("No AI provider is properly configured");
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
        $lines = explode("\n", $markdown_content);
        $html = "<div class='quiz-wrapper'>";
        $html .= "<div class='quiz-header'>";
        $html .= "<h2>" . ilMarkdownQuizXSSProtection::escapeHTML($this->object->getTitle()) . "</h2>";
        $html .= "</div>";

        $question_num = 0;
        $in_question = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (str_ends_with($line, '?')) {
                // Close previous question if exists
                if ($in_question) {
                    $html .= "</div></div>";
                }
                
                $question_num++;
                $html .= "<div class='quiz-question-card'>";
                // SECURITY: Escape question text
                $html .= "<div class='quiz-question-number'>Frage " . $question_num . "</div>";
                $html .= "<h3 class='quiz-question-text'>" . 
                         ilMarkdownQuizXSSProtection::escapeHTML($line) . "</h3>";
                $html .= "<div class='quiz-options'>";
                $in_question = true;
            } elseif (str_starts_with($line, '-')) {
                if ($in_question) {
                    $is_correct = str_contains($line, '[x]');
                    $answer_text = trim(str_replace(['- [x]', '- [ ]', '-'], '', $line));

                    // SECURITY: Create safe data attribute
                    $correct_attr = $is_correct ? "data-correct='true'" : "data-correct='false'";
                    $safe_name = ilMarkdownQuizXSSProtection::createSafeDataAttribute("q_{$question_num}");
                    
                    $html .= "<label class='quiz-option'>";
                    $html .= "<input type='radio' name='{$safe_name}' {$correct_attr}>";
                    $html .= "<span class='quiz-option-text'>" . ilMarkdownQuizXSSProtection::escapeHTML($answer_text) . "</span>";
                    $html .= "</label>";
                }
            }
        }

        // Close last question
        if ($in_question) {
            $html .= "</div></div>";
        }

        $html .= "<div class='quiz-actions'>";
        $html .= "<button type='button' class='quiz-btn quiz-btn-primary' onclick='checkQuiz()'>Antworten prüfen</button>";
        $html .= "<button type='button' class='quiz-btn quiz-btn-secondary' onclick='resetQuiz()'>Zurücksetzen</button>";
        $html .= "</div>";
        $html .= "<div id='quiz-result' class='quiz-result' style='display:none;'></div>";
        $html .= $this->getCheckQuizScript();
        $html .= "</div>";

        return $html;
    }

    private function getCheckQuizScript(): string
    {
        return <<<'JS'
<script>
function checkQuiz() {
    const questions = document.querySelectorAll('.quiz-question-card');
    let correct = 0;
    let total = 0;
    let unanswered = 0;

    questions.forEach(question => {
        const radios = question.querySelectorAll('input[type="radio"]');
        const options = question.querySelectorAll('.quiz-option');
        total++;

        let hasAnswer = false;
        let question_correct = false;
        
        radios.forEach(radio => {
            if (radio.checked) {
                hasAnswer = true;
                if (radio.getAttribute('data-correct') === 'true') {
                    question_correct = true;
                }
            }
        });

        if (!hasAnswer) {
            unanswered++;
        }

        // Reset all options
        options.forEach(opt => {
            opt.classList.remove('quiz-option-correct', 'quiz-option-wrong');
        });

        if (question_correct) {
            correct++;
            radios.forEach((radio, idx) => {
                if (radio.getAttribute('data-correct') === 'true') {
                    options[idx].classList.add('quiz-option-correct');
                }
            });
        } else if (hasAnswer) {
            radios.forEach((radio, idx) => {
                if (radio.getAttribute('data-correct') === 'true') {
                    options[idx].classList.add('quiz-option-correct');
                } else if (radio.checked) {
                    options[idx].classList.add('quiz-option-wrong');
                }
            });
        }
    });

    // Show result
    const percentage = total > 0 ? Math.round(correct / total * 100) : 0;
    const resultDiv = document.getElementById('quiz-result');
    
    let resultClass = 'quiz-result-good';
    let resultText = 'Ausgezeichnet!';
    
    if (percentage < 50) {
        resultClass = 'quiz-result-poor';
        resultText = 'Weiter üben!';
    } else if (percentage < 80) {
        resultClass = 'quiz-result-ok';
        resultText = 'Gut gemacht!';
    }
    
    resultDiv.className = 'quiz-result ' + resultClass;
    resultDiv.innerHTML = '<div class="quiz-result-content">' +
        '<div class="quiz-result-title">' + resultText + '</div>' +
        '<div class="quiz-result-score">' + correct + ' von ' + total + ' richtig (' + percentage + '%)</div>' +
        (unanswered > 0 ? '<div class="quiz-result-hint">(' + unanswered + ' Frage(n) nicht beantwortet)</div>' : '') +
        '</div>';
    resultDiv.style.display = 'block';
    
    // Scroll to result
    resultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function resetQuiz() {
    const radios = document.querySelectorAll('input[type="radio"]');
    const options = document.querySelectorAll('.quiz-option');
    const resultDiv = document.getElementById('quiz-result');
    
    radios.forEach(radio => {
        radio.checked = false;
    });
    
    options.forEach(opt => {
        opt.classList.remove('quiz-option-correct', 'quiz-option-wrong');
    });
    
    resultDiv.style.display = 'none';
}
</script>
JS;
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
