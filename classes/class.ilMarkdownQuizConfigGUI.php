<?php
declare(strict_types=1);
/**
 *  This file is part of the Markdown Quiz Repository Object plugin for ILIAS
 *  
 */

use ILIAS\UI\Factory;
use ILIAS\UI\Renderer;
use platform\ilMarkdownQuizConfig;

require_once __DIR__ . '/platform/class.ilMarkdownQuizConfig.php';

/**
 * Configuration GUI for MarkdownQuiz plugin administration.
 * 
 * Provides tabbed interface for configuring:
 * - General settings (AI enable/disable, service selection, system prompt)
 * - GWDG Academic Cloud (API key, model selection)
 * - Google Gemini (API key, model selection)
 * - OpenAI (API key, model selection)
 * 
 * Uses ILIAS UI Factory for form generation and validation.
 * All API keys are automatically encrypted via ilMarkdownQuizConfig.
 * 
 * @ilCtrl_IsCalledBy ilMarkdownQuizConfigGUI: ilObjComponentSettingsGUI
 */
class ilMarkdownQuizConfigGUI extends ilPluginConfigGUI
{
    /** All known models with provider and label key */
    private const MODEL_REGISTRY = [
        'meta-llama/Llama-3.3-70B-Instruct' => ['provider' => 'gwdg', 'label_key' => 'config_model_gwdg_llama'],
        'Qwen/Qwen3-235B-A22B-Thinking-2507' => ['provider' => 'gwdg', 'label_key' => 'config_model_gwdg_qwen'],
        'mistralai/Mistral-Large-Instruct-2501' => ['provider' => 'gwdg', 'label_key' => 'config_model_gwdg_mistral'],
        'gemini-2.5-flash' => ['provider' => 'google', 'label_key' => 'config_model_google_gemini'],
        'gpt-5-nano' => ['provider' => 'openai', 'label_key' => 'config_model_gpt5_nano'],
        'gpt-5-mini' => ['provider' => 'openai', 'label_key' => 'config_model_gpt5_mini'],
        'gpt-5.2' => ['provider' => 'openai', 'label_key' => 'config_model_gpt52'],
        'o4-mini' => ['provider' => 'openai', 'label_key' => 'config_model_o4_mini'],
    ];

    protected Factory $factory;
    protected Renderer $renderer;
    protected \ILIAS\Refinery\Factory $refinery;
    protected ilCtrl $control;
    protected ilGlobalTemplateInterface $tpl;
    protected ilTabsGUI $tabs;
    protected $request;

    /**
     * Main controller - routes to appropriate configuration section.
     * 
     * Available commands:
     * - configure/configureGeneral: AI enable/disable, service selection, system prompt
     * - configureGWDG: GWDG Academic Cloud settings
     * - configureGoogle: Google Gemini settings
     * - configureOpenAI: OpenAI ChatGPT settings
     * 
     * Checks if xmdq_config table exists before proceeding.
     * Initializes tabs and renders appropriate form.
     * 
     * @param string $cmd Command to execute
     */
    public function performCommand($cmd): void
    {
        global $DIC;
        
        // Check if config table exists (plugin might not be activated yet)
        if (!$DIC->database()->tableExists('xmdq_config')) {
            // If table doesn't exist, show a message and return early
            $this->tpl = $DIC->ui()->mainTemplate();
            $this->tpl->setOnScreenMessage('info', $this->getPluginObject()->txt('config_not_available'));
            $this->tpl->setContent('');
            return;
        }
        
        $this->factory = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->refinery = $DIC->refinery();
        $this->control = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->request = $DIC->http()->request();
        $this->tabs = $DIC->tabs();

        switch ($cmd) {
            case "configure":
            case "configureGeneral":
            case "configureGWDG":
            case "configureGoogle":
            case "configureOpenAI":
                ilMarkdownQuizConfig::load();
                $this->initTabs();
                $this->control->setParameterByClass('ilMarkdownQuizConfigGUI', 'cmd', $cmd);
                $form_action = $this->control->getLinkTargetByClass("ilMarkdownQuizConfigGUI", $cmd);
                $rendered = $this->renderForm($form_action, $this->buildForm($cmd));
                break;
            default:
                throw new ilException("command not defined");
        }

     
   $this->tpl->setContent($rendered);
    }

    /**
     * Initialize admin configuration tabs.
     * 
     * Creates 4 tabs: General, GWDG, Google Gemini, OpenAI ChatGPT.
     * Sets active tab based on current command from control flow.
     */
    protected function initTabs(): void
    {
        $this->tabs->addTab(
            "general",
            $this->getPluginObject()->txt("config_general"),
            $this->control->getLinkTargetByClass("ilMarkdownQuizConfigGUI", "configureGeneral")
        );

        $this->tabs->addTab(
            "gwdg",
            $this->getPluginObject()->txt("config_tab_gwdg"),
            $this->control->getLinkTargetByClass("ilMarkdownQuizConfigGUI", "configureGWDG")
        );

        $this->tabs->addTab(
            "google",
            $this->getPluginObject()->txt("config_tab_google"),
            $this->control->getLinkTargetByClass("ilMarkdownQuizConfigGUI", "configureGoogle")
        );

        $this->tabs->addTab(
            "openai",
            $this->getPluginObject()->txt("config_tab_openai"),
            $this->control->getLinkTargetByClass("ilMarkdownQuizConfigGUI", "configureOpenAI")
        );

        switch($this->control->getCmd()) {
            case "configureGeneral":
                $this->tabs->activateTab("general");
                break;
            case "configureGWDG":
                $this->tabs->activateTab("gwdg");
                break;
            case "configureGoogle":
                $this->tabs->activateTab("google");
                break;
            case "configureOpenAI":
                $this->tabs->activateTab("openai");
                break;
            default:
                $this->tabs->activateTab("general");
        }
    }

    /**
     * Build configuration form sections for specific command.
     * 
     * Routes to appropriate section builder:
     * - buildGeneralSection(): AI toggle, service selection, system prompt
     * - buildGWDGSection(): GWDG API key and models
     * - buildGoogleSection(): Google API key, model selection
     * - buildOpenAISection(): OpenAI API key, model selection
     * 
     * @param string $cmd Command name determining which section to build
     * @return array Form sections with input fields
     */
    private function buildForm(string $cmd): array
    {
        switch($cmd) {
            case "configureGWDG":
                return $this->buildGWDGSection();
            case "configureGoogle":
                return $this->buildGoogleSection();
            case "configureOpenAI":
                return $this->buildOpenAISection();
            default:
                return $this->buildGeneralSection();
        }
    }

    /**
     * Build General settings section.
     * 
     * Contains:
     * - AI enabled checkbox (enable/disable AI features globally)
     * - Service checkboxes (GWDG, Google Gemini, OpenAI)
     * - System prompt textarea (AI quiz generation instructions)
     * 
     * Service selections are stored as JSON in available_services config.
     * System prompt uses default template if not customized.
     * 
     * @return array Form section with general configuration inputs
     */
    private function buildGeneralSection(): array {
        // AI Enable/Disable Checkbox - convert to bool for checkbox
        $ai_enabled_value = ilMarkdownQuizConfig::get('ai_enabled', true);
        $ai_enabled_bool = filter_var($ai_enabled_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        
        $ai_enabled = $this->factory->input()->field()->checkbox(
            $this->getPluginObject()->txt("config_ai_enabled_label"),
            $this->getPluginObject()->txt("config_ai_enabled_info")
        )->withValue($ai_enabled_bool)->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownQuizConfig::set('ai_enabled', $v);
            }
        ));

        $available_services = ilMarkdownQuizConfig::get("available_services");
        if (!is_array($available_services) || $available_services === null) {
            $available_services = [
                'gwdg' => false,
                'google' => false,
                'openai' => false
            ];
        }

        $gwdg_service = $this->factory->input()->field()->checkbox(
            $this->getPluginObject()->txt('config_gwdg_label'),
        )->withValue((isset($available_services["gwdg"]) && $available_services["gwdg"] === true) ? true : false)->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Reload config to avoid stale/null reference
                $services = ilMarkdownQuizConfig::get('available_services');
                if (!is_array($services)) {
                    $services = [];
                }
                $services["gwdg"] = $v;
                ilMarkdownQuizConfig::set('available_services', $services);
            }
        ));

        $google_service = $this->factory->input()->field()->checkbox(
            $this->getPluginObject()->txt('config_google_label'),
        )->withValue((isset($available_services["google"]) && $available_services["google"] === true) ? true : false)->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Reload config to avoid stale/null reference
                $services = ilMarkdownQuizConfig::get('available_services');
                if (!is_array($services)) {
                    $services = [];
                }
                $services["google"] = $v;
                ilMarkdownQuizConfig::set('available_services', $services);
            }
        ));

        $openai_service = $this->factory->input()->field()->checkbox(
            $this->getPluginObject()->txt('config_openai_label'),
        )->withValue((isset($available_services["openai"]) && $available_services["openai"] === true) ? true : false)->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Reload config to avoid stale/null reference
                $services = ilMarkdownQuizConfig::get('available_services');
                if (!is_array($services)) {
                    $services = [];
                }
                $services["openai"] = $v;
                ilMarkdownQuizConfig::set('available_services', $services);
            }
        ));

        $system_prompt = $this->factory->input()->field()->textarea(
            $this->getPluginObject()->txt("config_system_prompt_label"),
            $this->getPluginObject()->txt("config_system_prompt_info")
        )->withValue(ilMarkdownQuizConfig::get("system_prompt") ?: $this->getDefaultSystemPrompt())->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Empty field resets to default prompt
                if (empty(trim($v))) {
                    $v = $this->getDefaultSystemPrompt();
                }
                ilMarkdownQuizConfig::set('system_prompt', $v);
            }
        ));

        return [
            "ai_settings" => $this->factory->input()->field()->section([
                $ai_enabled
            ], $this->getPluginObject()->txt("config_ai_settings")),
            "available_services" => $this->factory->input()->field()->section([
                $gwdg_service,
                $google_service,
                $openai_service
            ], $this->getPluginObject()->txt("config_available_services")),
            "general" => $this->factory->input()->field()->section([
                $system_prompt
            ], $this->getPluginObject()->txt("config_general"))
        ];
    }

    /**
     * Build GWDG Academic Cloud settings section.
     *
     * Contains:
     * - Model select (top 3 open-source models)
     * - API key password field (auto-encrypted on save)
     *
     * @return array Form section with GWDG configuration inputs
     */
    private function buildGWDGSection(): array {
        $inputs = [];

        // Model checkboxes
        foreach (self::MODEL_REGISTRY as $model_id => $info) {
            if ($info['provider'] === 'gwdg') {
                $inputs[] = $this->buildModelCheckbox($model_id, $info['label_key']);
            }
        }

        $inputs[] = $this->factory->input()->field()->password(
            $this->getPluginObject()->txt("config_gwdg_key_label"),
            $this->getPluginObject()->txt("config_gwdg_key_info")
        )->withValue(ilMarkdownQuizConfig::get("gwdg_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                $api_key = ($v instanceof \ILIAS\Data\Password) ? $v->toString() : $v;
                if (!empty($api_key)) {
                    ilMarkdownQuizConfig::set('gwdg_api_key', $api_key);
                }
            }
        ))->withRequired(true);

        return [
            "gwdg" => $this->factory->input()->field()->section($inputs, $this->getPluginObject()->txt('config_gwdg_label'))
        ];
    }

    /**
     * Build Google Gemini settings section.
     * 
     * Contains:
     * - API key password field (auto-encrypted on save)
     * - Gemini model checkbox
     * 
     * @return array Form section with Google Gemini configuration inputs
     */
    private function buildGoogleSection(): array {
        $inputs = [];

        // Model checkbox
        $inputs[] = $this->buildModelCheckbox('gemini-2.5-flash', 'config_model_google_gemini');

        $inputs[] = $this->factory->input()->field()->password(
            $this->getPluginObject()->txt("config_google_key_label"),
            $this->getPluginObject()->txt("config_google_key_info")
        )->withValue(ilMarkdownQuizConfig::get("google_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                $api_key = ($v instanceof \ILIAS\Data\Password) ? $v->toString() : $v;
                if (!empty($api_key)) {
                    ilMarkdownQuizConfig::set('google_api_key', $api_key);
                }
            }
        ))->withRequired(true);

        return [
            "google" => $this->factory->input()->field()->section($inputs, $this->getPluginObject()->txt('config_google_label'))
        ];
    }

    /**
     * Build OpenAI ChatGPT settings section.
     * 
     * Contains:
     * - OpenAI model checkboxes
     * - API key password field (auto-encrypted on save)
     * 
     * @return array Form section with OpenAI configuration inputs
     */
    private function buildOpenAISection(): array {
        $inputs = [];

        // Model checkboxes
        foreach (self::MODEL_REGISTRY as $model_id => $info) {
            if ($info['provider'] === 'openai') {
                $inputs[] = $this->buildModelCheckbox($model_id, $info['label_key']);
            }
        }

        $inputs[] = $this->factory->input()->field()->password(
            $this->getPluginObject()->txt("config_openai_key_label"),
            $this->getPluginObject()->txt("config_openai_key_info")
        )->withValue(ilMarkdownQuizConfig::get("openai_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                $api_key = ($v instanceof \ILIAS\Data\Password) ? $v->toString() : $v;
                if (!empty($api_key)) {
                    ilMarkdownQuizConfig::set('openai_api_key', $api_key);
                }
            }
        ))->withRequired(true);

        return [
            "openai" => $this->factory->input()->field()->section($inputs, $this->getPluginObject()->txt('config_openai_label'))
        ];
    }

    /**
     * Render form with given sections.
     * 
     * Creates ILIAS standard form, handles POST submission,
     * triggers save() on successful validation.
     * 
     * @param string $form_action Form submit URL
     * @param array $sections Form sections from build methods
     * @return string Rendered HTML form
     */
    private function renderForm(string $form_action, array $sections): string
    {
        $form = $this->factory->input()->container()->form()->standard(
            $form_action,
            $sections
        );

        if ($this->request->getMethod() == "POST") {
            $form = $form->withRequest($this->request);
            $result = $form->getData();
            if ($result) {
                $this->save();
            }
        }

        return $this->renderer->render($form);
    }

    /**
     * Save configuration to database.
     * 
     * Triggers ilMarkdownQuizConfig::save() which persists all
     * pending changes to xmdq_config table.
     * API keys are automatically encrypted before storage.
     * 
     * Displays success message after save.
     */
    /**
     * Build a checkbox for a single model that writes to enabled_models config.
     */
    private function buildModelCheckbox(string $model_id, string $label_key): \ILIAS\UI\Component\Input\Field\Checkbox
    {
        $enabled_models = ilMarkdownQuizConfig::get('enabled_models');
        if (!is_array($enabled_models)) {
            $enabled_models = [];
        }
        $provider = self::MODEL_REGISTRY[$model_id]['provider'] ?? '';

        return $this->factory->input()->field()->checkbox(
            $this->getPluginObject()->txt($label_key),
            $this->getPluginObject()->txt('config_enabled_models_info')
        )->withValue(isset($enabled_models[$model_id]))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) use ($model_id, $provider) {
                $models = ilMarkdownQuizConfig::get('enabled_models');
                if (!is_array($models)) {
                    $models = [];
                }
                if ($v) {
                    $models[$model_id] = $provider;
                } else {
                    unset($models[$model_id]);
                }
                ilMarkdownQuizConfig::set('enabled_models', $models);
            }
        ));
    }

    /**
     * Get the model registry (used by other classes to look up model info).
     */
    public static function getModelRegistry(): array
    {
        return self::MODEL_REGISTRY;
    }

    public function save(): void
    {
        try {
            ilMarkdownQuizConfig::save();
            $this->tpl->setOnScreenMessage("success", $this->getPluginObject()->txt('config_msg_success'));
        } catch (\Exception $e) {
            $this->tpl->setOnScreenMessage("failure", $e->getMessage());
        }
    }

    /**
     * Get default system prompt for AI quiz generation.
     * 
     * Comprehensive prompt template with placeholders:
     * - [QUESTION_COUNT]: Number of questions to generate
     * - [DIFFICULTY]: Difficulty level (Easy/Medium/Hard/Mixed)
     * 
     * Enforces strict markdown format:
     * - Question text ending with "?"
     * - Exactly 4 answer options per question
     * - One correct answer marked with [x]
     * - Three wrong answers marked with [ ]
     * 
     * Includes quality guidelines and difficulty definitions.
     * 
     * @return string Default system prompt template
     */
    private function getDefaultSystemPrompt(): string
    {
        // Use [PLACEHOLDER] format to avoid ILIAS template processing
        return "You are a quiz generation expert. Generate EXACTLY [QUESTION_COUNT] quiz questions in strict markdown format.\n\n" .
            "CRITICAL RULES:\n" .
            "1. Generate EXACTLY [QUESTION_COUNT] questions - NO MORE, NO LESS\n" .
            "2. Each question MUST have EXACTLY 4 answer options\n" .
            "3. For SINGLE-CHOICE questions: EXACTLY ONE answer marked with [x], all others with [ ]\n" .
            "4. For MULTIPLE-CHOICE questions: TWO or MORE answers marked with [x], the rest with [ ]\n\n" .
            "FORMAT - Single-choice example:\n" .
            "What is the capital of France?\n" .
            "- [x] Paris\n" .
            "- [ ] London\n" .
            "- [ ] Berlin\n" .
            "- [ ] Madrid\n\n" .
            "FORMAT - Multiple-choice example:\n" .
            "Which are programming languages?\n" .
            "- [x] Python\n" .
            "- [x] Java\n" .
            "- [ ] HTML\n" .
            "- [ ] Photoshop\n\n" .
            "QUALITY GUIDELINES:\n" .
            "- Make wrong answers plausible but clearly incorrect\n" .
            "- Avoid \"all of the above\" or \"none of the above\" options\n" .
            "- Keep questions clear and unambiguous\n" .
            "- Ensure correct answers are factually accurate\n" .
            "- Match difficulty level to [DIFFICULTY]\n" .
            "- Base questions on provided context if available\n\n" .
            "DIFFICULTY LEVELS:\n" .
            "- Easy: Basic recall and comprehension\n" .
            "- Medium: Application and analysis\n" .
            "- Hard: Complex reasoning and synthesis\n" .
            "- Mixed: Variety of difficulty levels\n\n" .
            "OUTPUT FORMAT:\n" .
            "Return ONLY the quiz questions in markdown format.\n" .
            "Do NOT include explanations, comments, or additional text.\n" .
            "Separate each question block with a blank line.\n" .
            "Generate EXACTLY [QUESTION_COUNT] questions as requested.";
    }
}
