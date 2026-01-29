<?php
declare(strict_types=1);

require_once __DIR__ . '/platform/class.ilMarkdownQuizConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownQuizEncryption.php';

use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizEncryption;

class ilMarkdownQuizPlugin extends ilRepositoryObjectPlugin
{
    public const PLUGIN_ID = "xmdq";
    public const PLUGIN_NAME = "MarkdownQuiz";

    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }
    
    /**
     * Called after plugin update
     * Handles data migration and encryption of existing API keys
     */
    protected function afterUpdate(): void
    {
        try {
            // Migrate existing API keys to encrypted format
            ilMarkdownQuizEncryption::migrateApiKeys();
        } catch (\Exception $e) {
            // Log error but don't fail the update
            error_log("MarkdownQuiz: API key migration failed: " . $e->getMessage());
        }
    }

    protected function uninstallCustom(): void
    {
        global $DIC;
        $db = $DIC->database();
        
        // Drop plugin tables to clean up all data including API keys
        if ($db->tableExists('xmdq_config')) {
            $db->dropTable('xmdq_config');
        }
        
        if ($db->tableExists('rep_robj_xmdq_data')) {
            $db->dropTable('rep_robj_xmdq_data');
        }
    }

    public function allowCopy(): bool
    {
        return true;
    }
}