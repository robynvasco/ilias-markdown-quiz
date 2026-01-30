<?php
declare(strict_types=1);

require_once __DIR__ . '/platform/class.ilMarkdownQuizConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownQuizEncryption.php';

use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizEncryption;

/**
 * MarkdownQuiz Plugin Main Class
 * 
 * This is the central plugin class that defines the plugin's identity and
 * handles lifecycle events (installation, update, uninstall).
 * 
 * Features:
 * - Automatic API key encryption migration on update
 * - Clean uninstall with complete data removal
 * - Support for object copying
 * 
 * @author  Your Name
 * @version 1.0
 */
class ilMarkdownQuizPlugin extends ilRepositoryObjectPlugin
{
    /** @var string Plugin identifier (must start with 'x' for plugin types) */
    public const PLUGIN_ID = "xmdq";
    
    /** @var string Human-readable plugin name */
    public const PLUGIN_NAME = "MarkdownQuiz";

    /**
     * Get the plugin name
     * Required by ILIAS plugin interface
     * 
     * @return string The plugin name
     */
    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }
    
    /**
     * Post-update hook
     * 
     * Called automatically by ILIAS after plugin is updated to a new version.
     * Handles data migration tasks:
     * - Encrypts existing plaintext API keys with AES-256-CBC
     * - Errors are logged but don't fail the update
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

    /**
     * Custom uninstall cleanup
     * 
     * Removes all plugin data from the database:
     * - xmdq_config: Configuration including encrypted API keys
     * - rep_robj_xmdq_data: Quiz content and metadata
     * 
     * Note: ILIAS handles removing object_data entries automatically
     */
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

    /**
     * Allow copying of quiz objects
     * 
     * @return bool True to enable copy functionality in ILIAS
     */
    public function allowCopy(): bool
    {
        return true;
    }
}