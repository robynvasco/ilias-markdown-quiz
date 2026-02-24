<?php
declare(strict_types=1);

require_once __DIR__ . '/platform/class.ilMarkdownQuizConfig.php';

use platform\ilMarkdownQuizConfig;

/**
 * MarkdownQuiz Plugin Main Class
 * 
 * This is the central plugin class that defines the plugin's identity and
 * handles lifecycle events (installation, update, uninstall).
 * 
 * Features:
 * - Clean uninstall with complete data removal
 * - Support for object copying
 * 
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
     * Pre-uninstall hook: ensure type is registered before deleteObjectType() runs.
     *
     * If the plugin was installed but never activated, the type entry in
     * object_data does not exist. The core's deleteObjectType() expects it,
     * so we register it here to prevent a null access error.
     */
    protected function beforeUninstallCustom(): bool
    {
        try {
            $type = $this->getId();
            $set = $this->db->query(
                "SELECT obj_id FROM object_data WHERE type = " . $this->db->quote("typ", "text") .
                " AND title = " . $this->db->quote($type, "text")
            );
            if (!$this->db->fetchAssoc($set)) {
                parent::beforeActivation();
            }
        } catch (\Throwable $e) {
            // Continue with uninstall even if this fails
        }

        return true;
    }

    /**
     * Pre-activation hook: register RBAC operations and set default role template permissions.
     *
     * Sets permissions for both global role templates (new courses) and
     * existing local roles (existing courses/groups):
     * - Admin: all permissions
     * - Tutor: visible, read, write, delete, copy
     * - Member: visible, read
     */
    protected function beforeActivation(): bool
    {
        $result = parent::beforeActivation();
        if (!$result) {
            return false;
        }

        try {
            $this->setupDefaultPermissions();
        } catch (\Throwable $e) {
            error_log("MarkdownQuiz: Permission setup failed: " . $e->getMessage());
        }

        return true;
    }

    /**
     * Set up default RBAC permissions for the xmdq type.
     *
     * Updates both global role templates (parent=8) for new courses
     * AND all existing local course/group roles so that existing
     * courses also get proper permissions for quiz objects.
     */
    private function setupDefaultPermissions(): void
    {
        $ilDB = $this->db;
        $type = $this->getId();

        // Operation IDs: 1=edit_permissions, 2=visible, 3=read, 4=write, 6=delete
        $copy_ops_id = ilRbacReview::_getOperationIdByName("copy");

        // Look up create operation ID for create_xmdq
        $create_ops_id = null;
        $set = $ilDB->query(
            "SELECT ops_id FROM rbac_operations WHERE operation = " . $ilDB->quote("create_" . $type, "text")
        );
        if ($rec = $ilDB->fetchAssoc($set)) {
            $create_ops_id = (int) $rec["ops_id"];
        }

        // Define permissions per role pattern
        // Pattern => [object permissions, gets create permission]
        $role_config = [
            'il_crs_admin'  => ['ops' => [1, 2, 3, 4, 6, $copy_ops_id], 'create' => true,  'container' => 'crs'],
            'il_crs_tutor'  => ['ops' => [2, 3, 4, 6, $copy_ops_id],    'create' => true,  'container' => 'crs'],
            'il_crs_member' => ['ops' => [2, 3],                         'create' => false, 'container' => 'crs'],
            'il_grp_admin'  => ['ops' => [1, 2, 3, 4, 6, $copy_ops_id], 'create' => true,  'container' => 'grp'],
            'il_grp_member' => ['ops' => [2, 3],                         'create' => false, 'container' => 'grp'],
        ];

        foreach ($role_config as $role_pattern => $config) {
            $ops = array_filter($config['ops'], fn($op) => $op !== null && $op > 0);

            // 1) Update global role TEMPLATES (parent=8) for new courses
            $set = $ilDB->query(
                "SELECT obj_id FROM object_data WHERE type = " . $ilDB->quote("rolt", "text") .
                " AND title = " . $ilDB->quote($role_pattern, "text")
            );
            if ($rec = $ilDB->fetchAssoc($set)) {
                $tpl_id = (int) $rec["obj_id"];
                foreach ($ops as $op) {
                    $ilDB->replace('rbac_templates', [
                        'rol_id' => ['integer', $tpl_id],
                        'type'   => ['text', $type],
                        'ops_id' => ['integer', $op],
                        'parent' => ['integer', 8]
                    ], []);
                }
                if ($config['create'] && $create_ops_id !== null) {
                    $ilDB->replace('rbac_templates', [
                        'rol_id' => ['integer', $tpl_id],
                        'type'   => ['text', $config['container']],
                        'ops_id' => ['integer', $create_ops_id],
                        'parent' => ['integer', 8]
                    ], []);
                }
            }

            // 2) Update all EXISTING local roles (e.g. il_crs_admin_123)
            $local_set = $ilDB->query(
                "SELECT od.obj_id, rfa.parent FROM object_data od " .
                "JOIN rbac_fa rfa ON rfa.rol_id = od.obj_id " .
                "WHERE od.type = " . $ilDB->quote("role", "text") .
                " AND od.title LIKE " . $ilDB->quote($role_pattern . "_%", "text")
            );
            while ($local_rec = $ilDB->fetchAssoc($local_set)) {
                $local_role_id = (int) $local_rec["obj_id"];
                $local_parent = (int) $local_rec["parent"];

                // Check if this role already has any xmdq permissions
                $check = $ilDB->query(
                    "SELECT ops_id FROM rbac_templates WHERE rol_id = " . $ilDB->quote($local_role_id, "integer") .
                    " AND type = " . $ilDB->quote($type, "text") .
                    " AND parent = " . $ilDB->quote($local_parent, "integer")
                );
                if ($ilDB->fetchAssoc($check)) {
                    continue; // Already has permissions, skip
                }

                foreach ($ops as $op) {
                    $ilDB->replace('rbac_templates', [
                        'rol_id' => ['integer', $local_role_id],
                        'type'   => ['text', $type],
                        'ops_id' => ['integer', $op],
                        'parent' => ['integer', $local_parent]
                    ], []);
                }
                if ($config['create'] && $create_ops_id !== null) {
                    $ilDB->replace('rbac_templates', [
                        'rol_id' => ['integer', $local_role_id],
                        'type'   => ['text', $config['container']],
                        'ops_id' => ['integer', $create_ops_id],
                        'parent' => ['integer', $local_parent]
                    ], []);
                }
            }
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

    /**
     * Get the title icon
     * 
     * Used for object list, creation GUI, info screen, export and permission tabs.
     * Returns the SVG icon path for the plugin.
     * 
     * @param string $a_type The object type (should be "xmdq")
     * @return string Path to the icon file relative to ILIAS root
     */
    public static function _getIcon(string $a_type): string
    {
        return 'Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/templates/images/icon_xmdq.svg';
    }
}
