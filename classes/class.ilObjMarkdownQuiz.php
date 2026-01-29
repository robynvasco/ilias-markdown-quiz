<?php
declare(strict_types=1);

class ilObjMarkdownQuiz extends ilObjectPlugin
{
    protected string $md_content = "";

    protected function initType(): void
    {
        $this->setType("xmdq");
    }

    public function setMarkdownContent(string $content): void
    {
        $this->md_content = $content;
    }

    public function getMarkdownContent(): string
    {
        return $this->md_content;
    }

    // Lädt Daten aus der DB
    public function doRead(): void
    {
        global $DIC;
        $res = $DIC->database()->query("SELECT md_content FROM rep_robyn_md_quiz WHERE id = " . 
            $DIC->database()->quote($this->getId(), "integer"));
        while ($row = $DIC->database()->fetchAssoc($res)) {
            $this->md_content = (string) $row["md_content"];
        }
    }

    // Schreibt Daten in die DB
    public function doUpdate(): void
    {
        global $DIC;
        $DIC->database()->replace(
            "rep_robyn_md_quiz",
            ["id" => ["integer", $this->getId()]],
            ["md_content" => ["clob", $this->md_content]]
        );
    }

    public function doDelete(): void
    {
        global $DIC;
        $DIC->database()->manipulate("DELETE FROM rep_robyn_md_quiz WHERE id = " . 
            $DIC->database()->quote($this->getId(), "integer"));
    }
}