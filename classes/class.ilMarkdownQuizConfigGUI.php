<?php
declare(strict_types=1);
/**
 *  This file is part of the Markdown Quiz Repository Object plugin for ILIAS
 *  This plugin is adapted from the AI Chat plugin.
 */

use ILIAS\UI\Factory;
use ILIAS\UI\Component\Input\Field\Group;
use ILIAS\UI\Renderer;
use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizException;

require_once __DIR__ . '/platform/class.ilMarkdownQuizConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownQuizException.php';

/**
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

    public function performCommand($cmd): void
    {
        global $DIC;
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
            default:
                $this->tabs->activateTab("general");
        }
    /**
     * @throws ilMarkdownQuizException
     */
    }

    /**
     * @throws ilMarkdownQuizException
     */
    private function buildForm(string $cmd): array
    {
        switch($cmd) {
            case "configureGWDG":
                return $this->buildGWDGSection();
            case "configureGoogle":
                return $this->buildGoogleSection();
            default:
                return $this->buildGeneralSection();
        }
    }

    /**
     * @throws ilMarkdownQuizException
     */
    private function buildGeneralSection(): array {
        $available_services = ilMarkdownQuizConfig::get("available_services");
        if (!is_array($available_services)) {
            $available_services = [];
        }

        $gwdg_service = $this->factory->input()->field()->checkbox(
            "GWDG",
        )->withValue(isset($available_services["gwdg"]) && $available_services["gwdg"] == "1")->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) use (&$available_services) {
                $available_services["gwdg"] = $v;
                ilMarkdownQuizConfig::set('available_services', $available_services);
            }
        ));

        $google_service = $this->factory->input()->field()->checkbox(
            "Google Gemini",
        )->withValue(isset($available_services["google"]) && $available_services["google"] == "1")->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) use (&$available_services) {
                $available_services["google"] = $v;
                ilMarkdownQuizConfig::set('available_services', $available_services);
            }
        ));

        $system_prompt = $this->factory->input()->field()->textarea(
            $this->plugin_object->txt("config_system_prompt_label"),
            $this->plugin_object->txt("config_system_prompt_info")
        )->withValue(ilMarkdownQuizConfig::get("system_prompt"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownQuizConfig::set('system_prompt', $v);
            }
        ))->withRequired(true);

        return [
            "available_services" => $this->factory->input()->field()->section([
                $gwdg_service,
                $google_service
            ], $this->plugin_object->txt("config_available_services")),
            "general" => $this->factory->input()->field()->section([
                $system_prompt
            ], $this->plugin_object->txt("config_general"))
        ];
    }

    /**
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

        $inputs[] = $this->factory->input()->field()->text(
            $this->plugin_object->txt("config_gwdg_key_label"),
            $this->plugin_object->txt("config_gwdg_key_info")
        )->withValue(ilMarkdownQuizConfig::get("gwdg_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownQuizConfig::set('gwdg_api_key', $v);
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

    private function buildGoogleSection(): array {
        $inputs = [];

        $inputs[] = $this->factory->input()->field()->text(
            $this->plugin_object->txt("config_google_key_label"),
            $this->plugin_object->txt("config_google_key_info")
        )->withValue(ilMarkdownQuizConfig::get("google_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownQuizConfig::set('google_api_key', $v);
            }
        ))->withRequired(true);

        return [
            "google" => $this->factory->input()->field()->section($inputs, "Google Gemini")
        ];
    }

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

    public function save(): void
    {
        ilMarkdownQuizConfig::save();

        $this->tpl->setOnScreenMessage("success", $this->plugin_object->txt('config_msg_success'));
    }

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
}

