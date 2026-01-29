<?php
declare(strict_types=1);

use ILIAS\UI\Factory;
use ILIAS\UI\Renderer;
use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizException;
use ai\ilMarkdownQuizGoogleAI;
use ai\ilMarkdownQuizGWDG;

require_once __DIR__ . '/platform/class.ilMarkdownQuizConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownQuizException.php';
require_once __DIR__ . '/ai/class.ilMarkdownQuizLLM.php';
require_once __DIR__ . '/ai/class.ilMarkdownQuizGWDG.php';
require_once __DIR__ . '/ai/class.ilMarkdownQuizGoogleAI.php';
require_once __DIR__ . '/class.ilObjMarkdownQuizUploadHandler.php';
require_once __DIR__ . '/class.ilObjMarkdownQuizStakeholder.php';

/**
 * @ilCtrl_isCalledBy ilObjMarkdownQuizGUI: ilRepositoryGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjMarkdownQuizGUI: ilPermissionGUI, ilInfoScreenGUI, ilCommonActionDispatcherGUI
 */
class ilObjMarkdownQuizGUI extends ilObjectPluginGUI
{
    private Factory $factory;
    private Renderer $renderer;
    protected \ILIAS\Refinery\Factory $refinery;
    protected ilLanguage $lng;

    public function getAfterCreationCmd(): string
    {
        return "settings";
    }

    protected function afterConstructor(): void
    {
        global $DIC;
        $this->factory = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->refinery = $DIC->refinery();
        $this->lng = $DIC->language();
    }

    public function getType(): string
    {
        return "xmdq";
    }

    public function getStandardCmd(): string
    {
        return "view";
    }

    public function performCommand(string $cmd): void
    {
        $this->checkPermission("read");
        $this->setTitleAndDescription();
        $this->{$cmd}();
    }

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
     * QUIZ ANZEIGEN (Magazin-Ansicht)
     */
    public function view(): void
    {
        $this->tabs->activateTab("view");
        $raw_content = $this->object->getMarkdownContent() ?: "No quiz content yet.";
        $html_output = $this->renderQuiz($raw_content);
        $panel = $this->factory->panel()->standard(
            $this->object->getTitle(),
            $this->factory->legacy($html_output)
        );

        $this->tpl->setContent($this->renderer->render($panel));
    }

    public function settings(): void
    {
        $this->checkPermission("write");
        $this->tabs->activateTab("settings");

        $form = $this->buildSettingsForm();

        if ($this->request->getMethod() === "POST") {
            $form = $form->withRequest($this->request);
            $data = $form->getData();
            if ($data !== null) {
                // Data already saved via transformations
                $this->tpl->setOnScreenMessage('success', 'Settings saved successfully');
            }
        }

        $this->tpl->setContent($this->renderer->render($form));
    }

    private function buildSettingsForm(): \ILIAS\UI\Component\Input\Container\Form\Form
    {
        $form_action = $this->ctrl->getFormAction($this);

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
            ['title' => $title_field, 'md_content' => $markdown_field]
        );
    }

    /**
     * GENERATE QUIZ WITH AI
     */
    public function generate(): void
    {
        global $DIC;
        
        $this->checkPermission("write");
        $this->tabs->activateTab("generate");

        file_put_contents('/tmp/mdquiz_debug.log', date('Y-m-d H:i:s') . " - generate() called\n", FILE_APPEND);
        file_put_contents('/tmp/mdquiz_debug.log', "Request method: " . $this->request->getMethod() . "\n", FILE_APPEND);

        ilMarkdownQuizConfig::load();
        
        // Determine active provider from available_services
        $available_services = ilMarkdownQuizConfig::get('available_services');
        if (!is_array($available_services)) {
            $available_services = [];
        }
        
        $provider = null;
        $api_key = null;
        
        if (isset($available_services['google']) && $available_services['google']) {
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
        
        // Validate saved file ref_id - reset if deleted or inaccessible
        $saved_ref_id = $this->object->getLastFileRefId();
        if ($saved_ref_id > 0 && !isset($available_files[$saved_ref_id])) {
            // File was deleted or is no longer accessible, reset to 0
            $saved_ref_id = 0;
            $this->object->setLastFileRefId(0);
            $this->object->update();
        }
        
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
            
            file_put_contents('/tmp/mdquiz_debug.log', "POST detected, form data: " . print_r($data, true) . "\n", FILE_APPEND);
            
            if ($data) {
                try {
                    file_put_contents('/tmp/mdquiz_debug.log', "Calling generateMarkdownQuiz\n", FILE_APPEND);
                    
                    // Get context from textarea or file
                    $context = $data['context'] ?? '';
                    
                    // If file ref_id provided, fetch file content
                    if (!empty($data['file_ref_id']) && $data['file_ref_id'] > 0) {
                        file_put_contents('/tmp/mdquiz_debug.log', "Fetching file content for ref_id: " . $data['file_ref_id'] . "\n", FILE_APPEND);
                        $file_context = $this->getFileContent((int)$data['file_ref_id']);
                        file_put_contents('/tmp/mdquiz_debug.log', "File context length: " . strlen($file_context) . " chars\n", FILE_APPEND);
                        file_put_contents('/tmp/mdquiz_debug.log', "File context preview: " . substr($file_context, 0, 200) . "\n", FILE_APPEND);
                        if (!empty($file_context)) {
                            $context .= ($context ? "\n\n" : "") . $file_context;
                        }
                    }
                    
                    file_put_contents('/tmp/mdquiz_debug.log', "Final context length: " . strlen($context) . " chars\n", FILE_APPEND);
                    
                    $markdown = $this->generateMarkdownQuiz(
                        $data['prompt'], 
                        $data['difficulty'],
                        $data['question_count'],
                        $context
                    );
                    
                    file_put_contents('/tmp/mdquiz_debug.log', "Generated markdown length: " . strlen($markdown) . "\n", FILE_APPEND);
                    
                    if (empty($markdown)) {
                        $this->tpl->setOnScreenMessage('failure', 'AI returned empty content');
                    } else {
                        $this->object->setMarkdownContent($markdown);
                        $this->object->setLastPrompt($data['prompt']);
                        $this->object->setLastDifficulty($data['difficulty']);
                        $this->object->setLastQuestionCount((int)$data['question_count']);
                        $this->object->setLastContext($data['context'] ?? '');
                        $this->object->setLastFileRefId((int)($data['file_ref_id'] ?? 0));
                        $this->object->update();

                        $this->tpl->setOnScreenMessage('success', 'Quiz generated successfully!');
                        $this->ctrl->redirect($this, 'settings');
                        return;
                    }
                } catch (\Exception $e) {
                    file_put_contents('/tmp/mdquiz_debug.log', "Exception: " . $e->getMessage() . "\n", FILE_APPEND);
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
        
        $files = [0 => "-- None --"];
        
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
                            $ext = $file_obj->getFileExtension();
                            
                            $files[$ref_id] = "$title ($ext, $size_kb KB)";
                        } catch (\Exception $e) {
                            $files[$ref_id] = $title;
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
            file_put_contents('/tmp/mdquiz_debug.log', "Error getting files: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        
        return $files;
    }

    /**
     * Get content from an ILIAS File object
     */
    private function getFileContent(int $ref_id): string
    {
        try {
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
            
            // Try to extract text based on file type
            $suffix = $file_obj->getFileExtension();
            
            if (strtolower($suffix) === 'txt') {
                return $content;
            } elseif (strtolower($suffix) === 'pdf') {
                return $this->extractTextFromPDF($content);
            } elseif (in_array(strtolower($suffix), ['ppt', 'pptx'])) {
                return $this->extractTextFromPowerPoint($content, strtolower($suffix));
            } elseif (in_array(strtolower($suffix), ['doc', 'docx'])) {
                return $this->extractTextFromWord($content, strtolower($suffix));
            } else {
                // For other types, try to use as plain text
                return $content;
            }
            
        } catch (\Exception $e) {
            file_put_contents('/tmp/mdquiz_debug.log', "File content error: " . $e->getMessage() . "\n", FILE_APPEND);
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
            
            file_put_contents('/tmp/mdquiz_debug.log', "Learning Module has " . count($pages) . " pages\n", FILE_APPEND);
            
            foreach ($pages as $page) {
                $page_obj = new ilLMPageObject($lm_obj, $page['obj_id']);
                $page_xml = $page_obj->getPageObject()->getXMLContent();
                
                // Extract text from XML/HTML content
                $page_text = $this->extractTextFromHTML($page_xml);
                
                if (!empty($page_text)) {
                    $text .= $page['title'] . ": " . $page_text . "\n\n";
                }
            }
            
            file_put_contents('/tmp/mdquiz_debug.log', "Extracted LM text length: " . strlen($text) . "\n", FILE_APPEND);
            
            // Limit length
            if (strlen($text) > 8000) {
                $text = substr($text, 0, 8000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            file_put_contents('/tmp/mdquiz_debug.log', "LM extraction error: " . $e->getMessage() . "\n", FILE_APPEND);
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
            file_put_contents('/tmp/mdquiz_debug.log', "PDF extraction started, content length: " . strlen($content) . "\n", FILE_APPEND);
            
            $text = '';
            
            // Extract text from Tj and TJ operators (text showing operators in PDF)
            // Look for patterns like: (text string) Tj or [(text) (string)] TJ
            if (preg_match_all('/\(([^)]*)\)\s*T[jJ*\']/', $content, $matches)) {
                file_put_contents('/tmp/mdquiz_debug.log', "Found " . count($matches[1]) . " text strings in Tj operators\n", FILE_APPEND);
                foreach ($matches[1] as $match) {
                    $decoded = $this->decodePDFString($match);
                    if (!empty(trim($decoded))) {
                        $text .= $decoded . ' ';
                    }
                }
            }
            
            // Also look for text in array format: [(text1) (text2)] TJ
            if (preg_match_all('/\[\s*\((.*?)\)\s*\]\s*TJ/', $content, $matches)) {
                file_put_contents('/tmp/mdquiz_debug.log', "Found " . count($matches[1]) . " text arrays\n", FILE_APPEND);
                foreach ($matches[1] as $match) {
                    $decoded = $this->decodePDFString($match);
                    if (!empty(trim($decoded))) {
                        $text .= $decoded . ' ';
                    }
                }
            }
            
            // Fallback: If still empty, try to extract readable text from anywhere
            if (empty($text)) {
                file_put_contents('/tmp/mdquiz_debug.log', "Using fallback extraction\n", FILE_APPEND);
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
            
            file_put_contents('/tmp/mdquiz_debug.log', "Extracted text length: " . strlen($text) . "\n", FILE_APPEND);
            file_put_contents('/tmp/mdquiz_debug.log', "Text preview: " . substr($text, 0, 300) . "\n", FILE_APPEND);
            
            // Limit length
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            file_put_contents('/tmp/mdquiz_debug.log', "PDF extraction exception: " . $e->getMessage() . "\n", FILE_APPEND);
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
            file_put_contents('/tmp/mdquiz_debug.log', "PowerPoint extraction started, format: $format, content length: " . strlen($content) . "\n", FILE_APPEND);
            
            if ($format !== 'pptx') {
                file_put_contents('/tmp/mdquiz_debug.log', "Unsupported PowerPoint format: $format (only .pptx supported)\n", FILE_APPEND);
                return '';
            }
            
            // Save content to temporary file (PPTX is a ZIP archive)
            $temp_file = tempnam(sys_get_temp_dir(), 'pptx_');
            file_put_contents($temp_file, $content);
            
            $text = '';
            
            // Open PPTX as ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($temp_file) === true) {
                file_put_contents('/tmp/mdquiz_debug.log', "Successfully opened PPTX archive\n", FILE_APPEND);
                
                // Extract text from all slides
                for ($i = 1; $i <= 100; $i++) { // Try up to 100 slides
                    $slide_path = "ppt/slides/slide{$i}.xml";
                    $slide_content = $zip->getFromName($slide_path);
                    
                    if ($slide_content === false) {
                        break; // No more slides
                    }
                    
                    file_put_contents('/tmp/mdquiz_debug.log', "Processing slide $i\n", FILE_APPEND);
                    
                    // Parse XML and extract text
                    $slide_text = $this->extractTextFromPowerPointXML($slide_content);
                    if (!empty($slide_text)) {
                        $text .= "Slide $i: " . $slide_text . "\n\n";
                    }
                }
                
                $zip->close();
            } else {
                file_put_contents('/tmp/mdquiz_debug.log', "Failed to open PPTX as ZIP\n", FILE_APPEND);
            }
            
            // Clean up temporary file
            unlink($temp_file);
            
            // Clean up whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            
            file_put_contents('/tmp/mdquiz_debug.log', "Extracted PowerPoint text length: " . strlen($text) . "\n", FILE_APPEND);
            file_put_contents('/tmp/mdquiz_debug.log', "Text preview: " . substr($text, 0, 300) . "\n", FILE_APPEND);
            
            // Limit length
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            file_put_contents('/tmp/mdquiz_debug.log', "PowerPoint extraction exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return '';
        }
    }
    
    /**
     * Extract text from PowerPoint slide XML
     */
    private function extractTextFromPowerPointXML(string $xml): string
    {
        try {
            // Parse XML
            $dom = new DOMDocument();
            @$dom->loadXML($xml);
            
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
            file_put_contents('/tmp/mdquiz_debug.log', "PowerPoint XML parsing error: " . $e->getMessage() . "\n", FILE_APPEND);
            return '';
        }
    }
    
    /**
     * Extract text from Word content (.docx)
     */
    private function extractTextFromWord(string $content, string $format): string
    {
        try {
            file_put_contents('/tmp/mdquiz_debug.log', "Word extraction started, format: $format, content length: " . strlen($content) . "\n", FILE_APPEND);
            
            if ($format !== 'docx') {
                file_put_contents('/tmp/mdquiz_debug.log', "Unsupported Word format: $format (only .docx supported)\n", FILE_APPEND);
                return '';
            }
            
            // Save content to temporary file (DOCX is a ZIP archive)
            $temp_file = tempnam(sys_get_temp_dir(), 'docx_');
            file_put_contents($temp_file, $content);
            
            $text = '';
            
            // Open DOCX as ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($temp_file) === true) {
                file_put_contents('/tmp/mdquiz_debug.log', "Successfully opened DOCX archive\n", FILE_APPEND);
                
                // Extract text from main document
                $doc_content = $zip->getFromName('word/document.xml');
                
                if ($doc_content !== false) {
                    file_put_contents('/tmp/mdquiz_debug.log', "Processing document.xml\n", FILE_APPEND);
                    $text = $this->extractTextFromWordXML($doc_content);
                } else {
                    file_put_contents('/tmp/mdquiz_debug.log', "Could not find word/document.xml\n", FILE_APPEND);
                }
                
                $zip->close();
            } else {
                file_put_contents('/tmp/mdquiz_debug.log', "Failed to open DOCX as ZIP\n", FILE_APPEND);
            }
            
            // Clean up temporary file
            unlink($temp_file);
            
            // Clean up whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            
            file_put_contents('/tmp/mdquiz_debug.log', "Extracted Word text length: " . strlen($text) . "\n", FILE_APPEND);
            file_put_contents('/tmp/mdquiz_debug.log', "Text preview: " . substr($text, 0, 300) . "\n", FILE_APPEND);
            
            // Limit length
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            file_put_contents('/tmp/mdquiz_debug.log', "Word extraction exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return '';
        }
    }
    
    /**
     * Extract text from Word document XML
     */
    private function extractTextFromWordXML(string $xml): string
    {
        try {
            // Parse XML
            $dom = new DOMDocument();
            @$dom->loadXML($xml);
            
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
            file_put_contents('/tmp/mdquiz_debug.log', "Word XML parsing error: " . $e->getMessage() . "\n", FILE_APPEND);
            return '';
        }
    }

    /**
     * @throws ilMarkdownQuizAIException
     */
    private function generateMarkdownQuiz(string $user_prompt, string $difficulty, int $question_count, string $context = ''): string
    {
        ilMarkdownQuizConfig::load();

        $available_services = ilMarkdownQuizConfig::get('available_services');
        if (empty($available_services) || !is_array($available_services)) {
            $available_services = [];
        }
        
        $ai = null;

        // Try Google first if available
        if (isset($available_services['google']) && $available_services['google']) {
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
                $html .= "<h4 style='margin: 0 0 15px 0; color: #333;'>" . htmlspecialchars($line) . "</h4>";
                $html .= "<div class='options' style='margin-left: 10px;'>";
                $in_question = true;
            } elseif (str_starts_with($line, '-')) {
                if ($in_question) {
                    $is_correct = str_contains($line, '[x]');
                    $answer_text = trim(str_replace(['- [x]', '- [ ]', '-'], '', $line));

                    $correct_attr = $is_correct ? "data-correct='true'" : "data-correct='false'";
                    $html .= "<label style='display: block; margin: 8px 0; padding: 5px; cursor: pointer; border-radius: 3px;'>";
                    $html .= "<input type='radio' name='q_{$question_num}' {$correct_attr} style='margin-right: 8px;'>";
                    $html .= htmlspecialchars($answer_text);
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
