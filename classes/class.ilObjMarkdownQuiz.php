<?php
declare(strict_types=1);

class ilObjMarkdownQuiz extends ilObjectPlugin
{
    protected bool $online = false;
    protected string $md_content = "";
    protected string $last_prompt = "";
    protected string $last_difficulty = "medium";
    protected int $last_question_count = 5;
    protected string $last_context = "";
    protected int $last_file_ref_id = 0;

    protected function initType(): void
    {
        $this->setType("xmdq");
    }

    public function setOnline(bool $online): void
    {
        $this->online = $online;
    }

    public function getOnline(): bool
    {
        return $this->online;
    }

    public function setMarkdownContent(string $content): void
    {
        $this->md_content = $content;
    }

    public function getMarkdownContent(): string
    {
        return $this->md_content;
    }

    public function setLastPrompt(string $prompt): void
    {
        $this->last_prompt = $prompt;
    }

    public function getLastPrompt(): string
    {
        return $this->last_prompt;
    }

    public function setLastDifficulty(string $difficulty): void
    {
        $this->last_difficulty = $difficulty;
    }

    public function getLastDifficulty(): string
    {
        return $this->last_difficulty;
    }

    public function setLastQuestionCount(int $count): void
    {
        $this->last_question_count = $count;
    }

    public function getLastQuestionCount(): int
    {
        return $this->last_question_count;
    }

    public function setLastContext(string $context): void
    {
        $this->last_context = $context;
    }

    public function getLastContext(): string
    {
        return $this->last_context;
    }

    public function setLastFileRefId(int $ref_id): void
    {
        $this->last_file_ref_id = $ref_id;
    }

    public function getLastFileRefId(): int
    {
        return $this->last_file_ref_id;
    }

    // Lädt Daten aus der DB
    public function doRead(): void
    {
        global $DIC;
        
        // Check if columns exist (backwards compatibility during migration)
        $has_last_prompt = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_prompt');
        $has_difficulty = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_difficulty');
        $has_question_count = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_question_count');
        $has_context = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_context');
        $has_file_ref_id = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_file_ref_id');
        
        $select_fields = "md_content, is_online";
        if ($has_last_prompt) $select_fields .= ", last_prompt";
        if ($has_difficulty) $select_fields .= ", last_difficulty";
        if ($has_question_count) $select_fields .= ", last_question_count";
        if ($has_context) $select_fields .= ", last_context";
        if ($has_file_ref_id) $select_fields .= ", last_file_ref_id";
        
        // SECURITY: Explicit integer type casting for ID parameter
        $res = $DIC->database()->query("SELECT {$select_fields} FROM rep_robj_xmdq_data WHERE id = " . 
            $DIC->database()->quote((int)$this->getId(), "integer"));
        while ($row = $DIC->database()->fetchAssoc($res)) {
            $this->md_content = (string) $row["md_content"];
            $this->online = (bool) ($row["is_online"] ?? 0);
            $this->last_prompt = $has_last_prompt ? (string) ($row["last_prompt"] ?? '') : '';
            $this->last_difficulty = $has_difficulty ? (string) ($row["last_difficulty"] ?? 'medium') : 'medium';
            $this->last_question_count = $has_question_count ? (int) ($row["last_question_count"] ?? 5) : 5;
            $this->last_context = $has_context ? (string) ($row["last_context"] ?? '') : '';
            $this->last_file_ref_id = $has_file_ref_id ? (int) ($row["last_file_ref_id"] ?? 0) : 0;
        }
    }

    // Schreibt Daten in die DB
    public function doUpdate(): void
    {
        global $DIC;
        
        // Check if columns exist (backwards compatibility during migration)
        $has_last_prompt = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_prompt');
        $has_difficulty = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_difficulty');
        $has_question_count = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_question_count');
        $has_context = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_context');
        $has_file_ref_id = $DIC->database()->tableColumnExists('rep_robj_xmdq_data', 'last_file_ref_id');
        
        $fields = ["md_content" => ["clob", $this->md_content], "is_online" => ["integer", (int)$this->online]];
        if ($has_last_prompt) $fields["last_prompt"] = ["text", $this->last_prompt];
        if ($has_difficulty) $fields["last_difficulty"] = ["text", $this->last_difficulty];
        if ($has_question_count) $fields["last_question_count"] = ["integer", $this->last_question_count];
        if ($has_context) $fields["last_context"] = ["text", $this->last_context];
        if ($has_file_ref_id) $fields["last_file_ref_id"] = ["integer", $this->last_file_ref_id];
        
        // SECURITY: Explicit integer type casting for ID parameter
        $DIC->database()->replace(
            "rep_robj_xmdq_data",
            ["id" => ["integer", (int)$this->getId()]],
            $fields
        );
    }

    public function doDelete(): void
    {
        global $DIC;
        
        // SECURITY: Explicit integer type casting for ID parameter
        $DIC->database()->manipulate("DELETE FROM rep_robj_xmdq_data WHERE id = " . 
            $DIC->database()->quote((int)$this->getId(), "integer"));
    }
}