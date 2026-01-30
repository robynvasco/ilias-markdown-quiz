<?php
declare(strict_types=1);

/**
 * Access class for MarkdownQuiz plugin
 */
class ilObjMarkdownQuizAccess extends ilObjectPluginAccess
{
    /**
     * Prüft den Zugriff für das Magazin (Repository)
     */
    public static function _checkGoto(string $target): bool
    {
        global $DIC;
        
        $params = explode("_", $target);
        $ref_id = (int) end($params);

        if ($ref_id <= 0) {
            return false;
        }

        return $DIC->access()->checkAccess("read", "", $ref_id);
    }

    /**
     * Grundlegende Berechtigungsprüfung
     */
    public function _checkAccess(string $cmd, string $permission, int $ref_id, int $obj_id, ?int $user_id = null): bool
    {
        global $DIC;
        $user_id = $user_id ?? $DIC->user()->getId();
        
        // Check if user has write permission - admins can always access
        if ($DIC->access()->checkAccessOfUser($user_id, 'write', '', $ref_id)) {
            return true;
        }
        
        // For read/visible permissions, check if object is online
        if (in_array($permission, ['read', 'visible'])) {
            if (self::_isOffline($obj_id)) {
                // Object is offline, deny access to non-admins
                return false;
            }
        }
        
        return (bool) $DIC->access()->checkAccessOfUser($user_id, $permission, $cmd, $ref_id, "xmdq", $obj_id);
    }

    /**
     * Check if object is offline
     * This determines if the object is visible to regular users
     */
    public static function _isOffline(int $obj_id): bool
    {
        global $DIC;
        $db = $DIC->database();
        
        $query = "SELECT is_online FROM rep_robj_xmdq_data WHERE id = " . $db->quote($obj_id, "integer");
        $result = $db->query($query);
        
        if ($row = $db->fetchAssoc($result)) {
            // is_online = 1 means online, so offline = !is_online
            return !(bool)$row['is_online'];
        }
        
        // Default to offline if not found
        return true;
    }
}