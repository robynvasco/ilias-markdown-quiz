<?php
declare(strict_types=1);
/**
 *  This file is part of the Markdown Quiz Repository Object plugin for ILIAS
 *  
 */

use ILIAS\UI\Factory;
use ILIAS\UI\Component\Input\Field\Group;
use ILIAS\UI\Renderer;
use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizException;

require_once __DIR__ . '/platform/class.ilMarkdownQuizConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownQuizException.php';

/**
 * Configuration GUI for MarkdownQuiz plugin administration.
 * 
 * Provides tabbed interface for configuring:
 * - General settings (AI enable/disable, service selection, system prompt)
 * - GWDG Academic Cloud (API key, model selection, streaming)
 * - Google Gemini (API key, model selection)
 * - OpenAI ChatGPT (API key, model selection)
 * 
 * Uses ILIAS UI Factory for form generation and validation.
 * All API keys are automatically encrypted via ilMarkdownQuizConfig.
 * 
 * @ilCtrl_IsCalledBy ilMarkdownQuizConfigGUI: ilObjComponentSettingsGUI
 */
class ilMarkdownQuizConfigGUI extends ilPluginConfigGUI
{
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
            $this->tpl->setOnScreenMessage('info', 'Plugin configuration is not available until the plugin is activated.');
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
            $this->plugin_object->txt("config_general"),
            $this->control->getLinkTargetByClass("ilMarkdownQuizConfigGUI", "configureGeneral")
        );

        $this->tabs->addTab(
            "gwdg",
            "GWDG",
            $this->control->getLinkTargetByClass("ilMarkdownQuizConfigGUI", "configureGWDG")
        );

        $this->tabs->addTab(
            "google",
            "Google Gemini",
            $this->control->getLinkTargetByClass("ilMarkdownQuizConfigGUI", "configureGoogle")
        );

        $this->tabs->addTab(
            "openai",
            "OpenAI ChatGPT",
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
    /**
     * @throws ilMarkdownQuizException
     */
    }

    /**
     * Build configuration form sections for specific command.
     * 
     * Routes to appropriate section builder:
     * - buildGeneralSection(): AI toggle, service selection, system prompt
     * - buildGWDGSection(): GWDG API key, models, streaming
     * - buildGoogleSection(): Google API key, model selection
     * - buildOpenAISection(): OpenAI API key, model selection
     * 
     * @param string $cmd Command name determining which section to build
     * @return array Form sections with input fields
     * @throws ilMarkdownQuizException
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
     * @throws ilMarkdownQuizException
     */
    private function buildGeneralSection(): array {
        // AI Enable/Disable Checkbox - convert to bool for checkbox
        $ai_enabled_value = ilMarkdownQuizConfig::get('ai_enabled', true);
        $ai_enabled_bool = filter_var($ai_enabled_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        
        $ai_enabled = $this->factory->input()->field()->checkbox(
            $this->plugin_object->txt("config_ai_enabled_label"),
            $this->plugin_object->txt("config_ai_enabled_info")
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
            "GWDG",
        )->withValue((isset($available_services["gwdg"]) && $available_services["gwdg"] == "1") ? true : false)->withAdditionalTransformation($this->refinery->custom()->transformation(
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
            "Google Gemini",
        )->withValue((isset($available_services["google"]) && $available_services["google"] == "1") ? true : false)->withAdditionalTransformation($this->refinery->custom()->transformation(
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
            "OpenAI ChatGPT",
        )->withValue((isset($available_services["openai"]) && $available_services["openai"] == "1") ? true : false)->withAdditionalTransformation($this->refinery->custom()->transformation(
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
            $this->plugin_object->txt("config_system_prompt_label"),
            $this->plugin_object->txt("config_system_prompt_info")
        )->withValue(ilMarkdownQuizConfig::get("system_prompt") ?: $this->getDefaultSystemPrompt())->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownQuizConfig::set('system_prompt', $v);
            }
        ))->withRequired(true);

        return [
            "ai_settings" => $this->factory->input()->field()->section([
                $ai_enabled
            ], $this->plugin_object->txt("config_ai_settings")),
            "available_services" => $this->factory->input()->field()->section([
                $gwdg_service,
                $google_service,
                $openai_service
            ], $this->plugin_object->txt("config_available_services")),
            "general" => $this->factory->input()->field()->section([
                $system_prompt
            ], $this->plugin_object->txt("config_general"))
        ];
    }

    /**
     * Build GWDG Academic Cloud settings section.
     * 
     * Contains:
     * - Model multiselect (fetched dynamically from GWDG API if key is set)
     * - API key password field (auto-encrypted on save)
     * - Streaming checkbox (enable/disable response streaming)
     * 
     * Models are loaded via getGWDGModels() when API key is already configured.
     * 
     * @return array Form section with GWDG configuration inputs
     * @throws ilMarkdownQuizException
     */
    private function buildGWDGSection(): array {
        $inputs = [];

        if (!empty(ilMarkdownQuizConfig::get("gwdg_api_key"))) {
            $models = $this->getGWDGModels(ilMarkdownQuizConfig::get("gwdg_api_key"));

            $values = ilMarkdownQuizConfig::get("gwdg_models");

            if (empty($values)) {
                $values = [];
            } else {
                $values = array_keys($values);
            }

            if (!empty($models)) {
                $inputs[] = $this->factory->input()->field()->multiSelect(
                    $this->plugin_object->txt("config_gwdg_models_label"),
                    $models
                )->withValue($values)->withAdditionalTransformation($this->refinery->custom()->transformation(
                    function ($v) use ($models) {
                        $models_to_save = [];

                        foreach ($v as $model) {
                            $models_to_save[$model] = $models[$model];
                        }

                        ilMarkdownQuizConfig::set('gwdg_models', $models_to_save);
                    }
                ))->withRequired(true);
            } else {
                $this->tpl->setOnScreenMessage("failure", $this->plugin_object->txt("config_gwdg_models_error"));
            }
        }

        $inputs[] = $this->factory->input()->field()->password(
            $this->plugin_object->txt("config_gwdg_key_label"),
            $this->plugin_object->txt("config_gwdg_key_info")
        )->withValue(ilMarkdownQuizConfig::get("gwdg_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Convert Password object to string
                $api_key = ($v instanceof \ILIAS\Data\Password) ? $v->toString() : $v;
                ilMarkdownQuizConfig::set('gwdg_api_key', $api_key);
            }
        ))->withRequired(true);

        $inputs[] = $this->factory->input()->field()->checkbox(
            $this->plugin_object->txt("config_gwdg_stream_label"),
            $this->plugin_object->txt("config_gwdg_stream_info")
        )->withValue(ilMarkdownQuizConfig::get("gwdg_streaming") == "1")->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownQuizConfig::set('gwdg_streaming', $v);
            }
        ));

        return [
            "gwdg" => $this->factory->input()->field()->section($inputs, "GWDG")
        ];
    }

    /**
     * Build Google Gemini settings section.
     * 
     * Contains:
     * - API key password field (auto-encrypted on save)
     * - Note: Model selection removed - using default gemini-2.0-flash-exp
     * 
     * @return array Form section with Google Gemini configuration inputs
     */
    private function buildGoogleSection(): array {
        $inputs = [];

        $inputs[] = $this->factory->input()->field()->password(
            $this->plugin_object->txt("config_google_key_label"),
            $this->plugin_object->txt("config_google_key_info")
        )->withValue(ilMarkdownQuizConfig::get("google_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Convert Password object to string
                $api_key = ($v instanceof \ILIAS\Data\Password) ? $v->toString() : $v;
                ilMarkdownQuizConfig::set('google_api_key', $api_key);
            }
        ))->withRequired(true);

        return [
            "google" => $this->factory->input()->field()->section($inputs, "Google Gemini")
        ];
    }

    /**
     * Build OpenAI ChatGPT settings section.
     * 
     * Contains:
     * - Model selection (gpt-4o, gpt-4o-mini, gpt-4-turbo, gpt-4, gpt-3.5-turbo)
     * - API key password field (auto-encrypted on save)
     * 
     * @return array Form section with OpenAI configuration inputs
     */
    private function buildOpenAISection(): array {
        $inputs = [];

        $models = [
            'gpt-4o' => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o Mini',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-4' => 'GPT-4',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo'
        ];

        $inputs[] = $this->factory->input()->field()->select(
            $this->plugin_object->txt("config_openai_model_label"),
            $models
        )->withValue(ilMarkdownQuizConfig::get("openai_model") ?: 'gpt-4o-mini')->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownQuizConfig::set('openai_model', $v);
            }
        ))->withRequired(true);

        $inputs[] = $this->factory->input()->field()->password(
            $this->plugin_object->txt("config_openai_key_label"),
            $this->plugin_object->txt("config_openai_key_info")
        )->withValue(ilMarkdownQuizConfig::get("openai_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Convert Password object to string
                $api_key = ($v instanceof \ILIAS\Data\Password) ? $v->toString() : $v;
                ilMarkdownQuizConfig::set('openai_api_key', $api_key);
            }
        ))->withRequired(true);

        return [
            "openai" => $this->factory->input()->field()->section($inputs, "OpenAI ChatGPT")
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
    public function save(): void
    {
        ilMarkdownQuizConfig::save();

        $this->tpl->setOnScreenMessage("success", $this->plugin_object->txt('config_msg_success'));
    }

    /**
     * Fetch available models from GWDG Academic Cloud API.
     * 
     * Makes GET request to https://chat-ai.academiccloud.de/v1/models
     * with Bearer token authentication. Respects ILIAS proxy settings.
     * 
     * Timeout: 10 seconds
     * 
     * @param string $api_key GWDG API key for authentication
     * @return array Associative array of model_id => model_name
     */
    private function getGWDGModels(string $api_key): array
    {
        $curlSession = curl_init();
        curl_setopt($curlSession, CURLOPT_URL, "https://chat-ai.academiccloud.de/v1/models");
        curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlSession, CURLOPT_TIMEOUT, 10);
        curl_setopt($curlSession, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ]);

        if (\ilProxySettings::_getInstance()->isActive()) {
            $proxyHost = \ilProxySettings::_getInstance()->getHost();
            $proxyPort = \ilProxySettings::_getInstance()->getPort();
            $proxyURL = $proxyHost . ":" . $proxyPort;
            curl_setopt($curlSession, CURLOPT_PROXY, $proxyURL);
        }

        $response = curl_exec($curlSession);

        $models = [];

        if (!curl_errno($curlSession)) {
            $response = json_decode($response, true);

            if (isset($response["data"])) {
                foreach ($response["data"] as $model) {
                    $models[$model['id']] = $model['name'];
                }
            }
        }

        curl_close($curlSession);

        return $models;
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
        return "You are a quiz generation expert. Generate EXACTLY [QUESTION_COUNT] single-choice quiz questions in strict markdown format.\n\n" .
            "CRITICAL RULES:\n" .
            "1. Generate EXACTLY [QUESTION_COUNT] questions - NO MORE, NO LESS\n" .
            "2. Each question MUST end with a question mark (?)\n" .
            "3. Each question MUST have EXACTLY 4 answer options\n" .
            "4. EXACTLY ONE answer must be marked as correct with [x]\n" .
            "5. All other answers must be marked with [ ]\n" .
            "6. Use this exact format for each question:\n\n" .
            "Question text here?\n" .
            "- [x] Correct answer\n" .
            "- [ ] Wrong answer 1\n" .
            "- [ ] Wrong answer 2\n" .
            "- [ ] Wrong answer 3\n\n" .
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

