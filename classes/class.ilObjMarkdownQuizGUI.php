<?php
declare(strict_types=1);

use ILIAS\UI\Factory;
use ILIAS\UI\Renderer;

/**
 * Class ilObjMarkdownQuizGUI
 * @ilCtrl_isCalledBy ilObjMarkdownQuizGUI: ilRepositoryGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjMarkdownQuizGUI: ilPermissionGUI, ilInfoScreenGUI, ilCommonActionDispatcherGUI
 */
class ilObjMarkdownQuizGUI extends ilObjectPluginGUI
{
    private Factory $factory;
    private Renderer $renderer;
    protected \ILIAS\Refinery\Factory $refinery;

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
        $this->{$cmd}();
    }

    protected function setTabs(): void
    {
        global $DIC;
        $this->tabs->addTab("view", "Quiz anzeigen", $DIC->ctrl()->getLinkTarget($this, "view"));
        
        if ($this->checkPermissionBool("write")) {
            $this->tabs->addTab("settings", "Einstellungen", $DIC->ctrl()->getLinkTarget($this, "settings"));
        }

        if ($this->checkPermissionBool("edit_permission")) {
            $this->tabs->addTab("perm_settings", $this->lng->txt("perm_settings"), $DIC->ctrl()->getLinkTargetByClass([
                get_class($this),
                "ilPermissionGUI",
            ], "perm"));
        }
    }

    /**
     * VIEW mit Prüf-Logik
     */
    public function view(): void
    {
        $this->tabs->activateTab("view");
        $raw_content = $this->object->getMarkdownContent() ?: "Noch kein Inhalt vorhanden.";
        
        $lines = explode("\n", $raw_content);
        $html_output = "<div id='quiz-wrapper' style='padding: 20px;'>";
        
        $question_count = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (str_ends_with($line, '?')) {
                $question_count++;
                $html_output .= "<h3 style='margin-top: 25px; border-bottom: 1px solid #ccc;'>" . htmlspecialchars($line) . "</h3>";
            } 
            elseif (str_starts_with($line, '-')) {
                // Prüfe ob die Antwort als korrekt markiert ist: - [x]
                $is_correct = str_contains($line, '[x]');
                $answer_text = trim(str_replace(['- [x]', '- [ ]', '-'], '', $line));
                
                $correct_attr = $is_correct ? "data-correct='true'" : "data-correct='false'";

                $html_output .= "<div class='answer-row' style='margin: 10px 0; padding: 5px; border-radius: 4px;'>
                                    <label style='font-weight: normal; cursor: pointer; display: block;'>
                                        <input type='radio' name='q_{$question_count}' {$correct_attr}> " 
                                        . htmlspecialchars($answer_text) . 
                                    "</label>
                                 </div>";
            }
        }
        
        // Button zum Prüfen
        $html_output .= "<button type='button' class='btn btn-primary' style='margin-top: 20px;' onclick='checkQuiz()'>Antworten prüfen</button>";
        
        // Kleines JavaScript für die Auswertung
        $html_output .= "
        <script>
        function checkQuiz() {
            const rows = document.querySelectorAll('.answer-row');
            rows.forEach(row => {
                const input = row.querySelector('input');
                row.style.backgroundColor = 'transparent'; // Reset
                
                if (input.checked) {
                    if (input.getAttribute('data-correct') === 'true') {
                        row.style.backgroundColor = '#dff0d8'; // Grün für Richtig
                    } else {
                        row.style.backgroundColor = '#f2dede'; // Rot für Falsch
                    }
                } else if (input.getAttribute('data-correct') === 'true') {
                    row.style.border = '1px dashed #3c763d'; // Rahmen für die eigentlich richtige Antwort
                }
            });
        }
        </script>";
        
        $html_output .= "</div>";

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

        $form_action = $this->ctrl->getLinkTarget($this, "settings");
        
        $form = $this->factory->input()->container()->form()->standard(
            $form_action,
            $this->buildSettingsForm()
        );

        if ($this->request->getMethod() == "POST") {
            $form = $form->withRequest($this->request);
            $result = $form->getData();
            if ($result) {
                $this->object->update(); 
                $this->tpl->setOnScreenMessage("success", "Einstellungen gespeichert.", true);
            }
        }

        $this->tpl->setContent($this->renderer->render($form));
    }

    private function buildSettingsForm(): array
    {
        $title = $this->factory->input()->field()->text("Titel")
            ->withValue($this->object->getTitle())
            ->withAdditionalTransformation($this->refinery->custom()->transformation(
                function ($v) { $this->object->setTitle($v); return $v; }
            ));

        $markdown = $this->factory->input()->field()->textarea("Markdown Code")
            ->withValue($this->object->getMarkdownContent())
            ->withAdditionalTransformation($this->refinery->custom()->transformation(
                function ($v) { $this->object->setMarkdownContent($v); return $v; }
            ));

        return ["md_section" => $this->factory->input()->field()->section([$title, $markdown], "Allgemeine Einstellungen")];
    }
}