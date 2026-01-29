<?php
declare(strict_types=1);

class ilMarkdownQuizPlugin extends ilRepositoryObjectPlugin
{
    public const PLUGIN_ID = "xmdq";
    public const PLUGIN_NAME = "MarkdownQuiz";

    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    protected function uninstallCustom(): void
    {
        global $DIC;
        $db = $DIC->database();
        
        // Clean up configuration table
        if ($db->tableExists('xmdq_config')) {
            $db->manipulate("DELETE FROM xmdq_config");
        }
    }

    public function allowCopy(): bool
    {
        return true;
    }
}