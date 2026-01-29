<?php
declare(strict_types=1);

class ilMarkdownQuizPlugin extends ilRepositoryObjectPlugin
{
    // Die ID muss mit der in plugin.php/module.xml übereinstimmen
    protected const ID = "xmdq";

    public function getPluginName(): string
    {
        return "MarkdownQuiz";
    }

    protected function uninstallCustom(): void
    {
        // Hier könnten wir die DB-Tabelle löschen, falls gewünscht
    }
}