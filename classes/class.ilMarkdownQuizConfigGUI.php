<?php
declare(strict_types=1);

/**
 * Global Admin Config für MarkdownQuiz
 */
class ilMarkdownQuizConfigGUI extends ilPluginConfigGUI
{
    public function performCommand(string $cmd): void
    {
        switch ($cmd) {
            case "configure":
            case "save":
                $this->$cmd();
                break;
        }
    }

    public function configure(): void
    {
        // Hier baust du ein Formular mit:
        // 1. API Endpoint (Standard: https://chat-ai.academiccloud.de/v1/)
        // 2. API Key (GWDG Key)
        // 3. Standard Modell (z.B. llama3)
    }
}
