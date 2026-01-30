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
require_once __DIR__ . '/class.ilObjMarkdownQuizUploadHandler.php';
require_once __DIR__ . '/class.ilObjMarkdownQuizStakeholder.php';

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
            $this->tabs->addTab("generate", "AI Generate", $DIC->ctrl()->getLinkTarget($this, "generate"));
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
        $raw_content = $this->object->getMarkdownContent() ?: "No quiz content yet.";
        
        // SECURITY: Protect content before rendering
        try {
            $protected_content = ilMarkdownQuizXSSProtection::protectContent($raw_content);
            $html_output = $this->renderQuiz($protected_content);
        } catch (\Exception $e) {
            $html_output = "<div class='alert alert-danger'>Error: " . 
                          ilMarkdownQuizXSSProtection::escapeHTML($e->getMessage()) . 
                          "</div>";
        }
        
        $panel = $this->factory->panel()->standard(
            $this->object->getTitle(),
            $this->factory->legacy($html_output)
        );

        $this->tpl->setContent($this->renderer->render($panel));
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
        $html = "<div id='quiz-wrapper' style='padding: 20px;'>";

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
                $html .= "<div class='question' style='margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9f9;'>";
                // SECURITY: Escape question text
                $html .= "<h4 style='margin: 0 0 15px 0; color: #333;'>" . 
                         ilMarkdownQuizXSSProtection::escapeHTML($line) . "</h4>";
                $html .= "<div class='options' style='margin-left: 10px;'>";
                $in_question = true;
            } elseif (str_starts_with($line, '-')) {
                if ($in_question) {
                    $is_correct = str_contains($line, '[x]');
                    $answer_text = trim(str_replace(['- [x]', '- [ ]', '-'], '', $line));

                    // SECURITY: Create safe data attribute
                    $correct_attr = $is_correct ? "data-correct='true'" : "data-correct='false'";
                    $safe_name = ilMarkdownQuizXSSProtection::createSafeDataAttribute("q_{$question_num}");
                    
                    $html .= "<label style='display: block; margin: 8px 0; padding: 5px; cursor: pointer; border-radius: 3px;'>";
                    $html .= "<input type='radio' name='{$safe_name}' {$correct_attr} style='margin-right: 8px;'>";
                    // SECURITY: Escape answer text
                    $html .= ilMarkdownQuizXSSProtection::escapeHTML($answer_text);
                    $html .= "</label>";
                }
            }
        }

        // Close last question
        if ($in_question) {
            $html .= "</div></div>";
        }

        $html .= "<div style='margin-top: 20px;'>";
        $html .= "<button type='button' class='btn btn-primary' onclick='checkQuiz()' style='margin-right: 10px;'>Check Answers</button>";
        $html .= "<button type='button' class='btn btn-secondary' onclick='resetQuiz()'>Reset</button>";
        $html .= "</div>";
        $html .= $this->getCheckQuizScript();
        $html .= "</div>";

        return $html;
    }

    private function getCheckQuizScript(): string
    {
        return <<<'JS'
<script>
function checkQuiz() {
    const questions = document.querySelectorAll('.question');
    let correct = 0;
    let total = 0;

    questions.forEach(question => {
        const radios = question.querySelectorAll('input[type="radio"]');
        total++;

        let question_correct = false;
        radios.forEach(radio => {
            if (radio.checked && radio.getAttribute('data-correct') === 'true') {
                question_correct = true;
            }
            radio.parentElement.style.backgroundColor = 'transparent';
        });

        if (question_correct) {
            correct++;
            radios.forEach(radio => {
                if (radio.getAttribute('data-correct') === 'true') {
                    radio.parentElement.style.backgroundColor = '#dff0d8';
                }
            });
        } else {
            radios.forEach(radio => {
                if (radio.getAttribute('data-correct') === 'true') {
                    radio.parentElement.style.backgroundColor = '#dff0d8';
                } else if (radio.checked) {
                    radio.parentElement.style.backgroundColor = '#f2dede';
                }
            });
        }
    });

    const percentage = total > 0 ? Math.round(correct / total * 100) : 0;
    alert('Score: ' + correct + '/' + total + ' (' + percentage + '%)');
}

function resetQuiz() {
    const radios = document.querySelectorAll('input[type="radio"]');
    radios.forEach(radio => {
        radio.checked = false;
        radio.parentElement.style.backgroundColor = 'transparent';
    });
}
</script>
JS;
    }
}
