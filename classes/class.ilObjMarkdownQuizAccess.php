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
        return (bool) $DIC->access()->checkAccessOfUser($user_id, $permission, $cmd, $ref_id, "xmdq", $obj_id);
    }
}