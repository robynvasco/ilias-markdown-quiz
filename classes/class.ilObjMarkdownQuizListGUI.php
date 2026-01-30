<?php
declare(strict_types=1);

/**
 * Class ilObjMarkdownQuizListGUI
 * Bestimmt, wie das Quiz in der Magazin-Liste erscheint.
 */
class ilObjMarkdownQuizListGUI extends ilObjectPluginListGUI
{
    /**
     * Gibt an, welche GUI-Klasse aufgerufen wird, wenn man auf das Objekt klickt.
     */
    public function getGuiClass(): string
    {
        return ilObjMarkdownQuizGUI::class;
    }

    /**
     * Hier definieren wir die Buttons/Befehle, die direkt in der Liste erscheinen.
     */
    public function initCommands(): array
    {
        return [
            [
                "permission" => "read",
                "cmd" => "view",      // Der Standard-Befehl beim Klick auf den Titel
                "default" => true,
            ],
            [
                "permission" => "write",
                "cmd" => "settings",  // Schnellzugriff auf die Einstellungen
                "txt" => "Einstellungen"
            ]
        ];
    }

    /**
     * Setzt den Typ (ID) des Plugins.
     */
    public function initType(): void
    {
        $this->setType("xmdq");
    }

    /**
     * Optional: Zeigt Status-Informationen direkt in der Liste an (z.B. "Offline").
     */
    public function getCustomProperties($a_prop): array
    {
        $props = parent::getCustomProperties($a_prop);
        
        // Show offline status
        if (ilObjMarkdownQuizAccess::_isOffline($this->obj_id)) {
            $props[] = [
                "alert" => true,
                "property" => "Status",
                "value" => "Offline"
            ];
        }
        
        return $props;
    }
}