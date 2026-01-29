<?php
declare(strict_types=1);

namespace platform;

use Exception;
use ilDBInterface;

/**
 * Class ilMarkdownQuizDatabase
 */
class ilMarkdownQuizDatabase
{
    const ALLOWED_TABLES = [
        'xmdq_config',
        'rep_robj_xmdq_data'
    ];

    private ilDBInterface $db;

    public function __construct()
    {
        global $DIC;
        $this->db = $DIC->database();
    }

    /**
     * Inserts a new row in the database
     * @param string $table
     * @param array $data
     * @return void
     * @throws ilMarkdownQuizException
     */
    public function insert(string $table, array $data): void
    {
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownQuizException("Invalid table name: " . $table);
        }

        try {
            $this->db->query("INSERT INTO " . $table . " (" . implode(", ", array_keys($data)) . ") VALUES (" . implode(", ", array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($data))) . ")");
        } catch (Exception $e) {
            throw new ilMarkdownQuizException($e->getMessage());
        }
    }

    /**
     * Inserts a new row in the database, if the row already exists, updates it
     * @param string $table
     * @param array $data
     * @return void
     * @throws ilMarkdownQuizException
     */
    public function insertOnDuplicatedKey(string $table, array $data): void
    {
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownQuizException("Invalid table name: " . $table);
        }

        try {
            $this->db->query("INSERT INTO " . $table . " (" . implode(", ", array_keys($data)) . ") VALUES (" . implode(", ", array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($data))) . ") ON DUPLICATE KEY UPDATE " . implode(", ", array_map(function ($key, $value) {
                    return $key . " = " . $value;
                }, array_keys($data), array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($data)))));
        } catch (Exception $e) {
            throw new ilMarkdownQuizException($e->getMessage());
        }
    }

    /**
     * Updates a row/s in the database
     * @param string $table
     * @param array $data
     * @param array $where
     * @return void
     * @throws ilMarkdownQuizException
     */
    public function update(string $table, array $data, array $where): void
    {
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownQuizException("Invalid table name: " . $table);
        }

        try {
            $this->db->query("UPDATE " . $table . " SET " . implode(", ", array_map(function ($key, $value) {
                    return $key . " = " . $value;
                }, array_keys($data), array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($data)))) . " WHERE " . implode(" AND ", array_map(function ($key, $value) {
                    return $key . " = " . $value;
                }, array_keys($where), array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($where)))));
        } catch (Exception $e) {
            throw new ilMarkdownQuizException($e->getMessage());
        }
    }

    /**
     * Deletes a row/s in the database
     * @param string $table
     * @param array $where
     * @return void
     * @throws ilMarkdownQuizException
     */
    public function delete(string $table, array $where): void
    {
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownQuizException("Invalid table name: " . $table);
        }

        try {
            $this->db->query("DELETE FROM " . $table . " WHERE " . implode(" AND ", array_map(function ($key, $value) {
                    return $key . " = " . $value;
                }, array_keys($where), array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($where)))));
        } catch (Exception $e) {
            throw new ilMarkdownQuizException($e->getMessage());
        }
    }

    /**
     * Selects a row/s in the database
     * @param string $table
     * @param array|null $where
     * @param array|null $columns
     * @param string|null $extra
     * @return array
     * @throws ilMarkdownQuizException
     */
    public function select(string $table, ?array $where = null, ?array $columns = null, ?string $extra = ""): array
    {
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownQuizException("Invalid table name: " . $table);
        }

        try {
            $query = "SELECT " . (isset($columns) ? implode(", ", $columns) : "*") . " FROM " . $table;

            if (isset($where)) {
                $query .= " WHERE " . implode(" AND ", array_map(function ($key, $value) {
                        return $key . " = " . $value;
                    }, array_keys($where), array_map(function ($value) {
                        return $this->db->quote($value);
                    }, array_values($where))));
            }

            if (is_string($extra)) {
                $extra = strip_tags($extra);
                $query .= " " . $extra;
            }

            $result = $this->db->query($query);

            $rows = [];

            while ($row = $this->db->fetchAssoc($result)) {
                $rows[] = $row;
            }

            return $rows;
        } catch (Exception $e) {
            throw new ilMarkdownQuizException($e->getMessage());
        }
    }

    /**
     * Returns the next id for a table
     * @param string $table
     * @return int
     * @throws ilMarkdownQuizException
     */
    public function nextId(string $table): int
    {
        try {
            return (int) $this->db->nextId($table);
        } catch (Exception $e) {
            throw new ilMarkdownQuizException($e->getMessage());
        }
    }

    /**
     * Utility function to validate table names against a list of allowed names
     */
    private function validateTableName(string $identifier): bool
    {
        return in_array($identifier, self::ALLOWED_TABLES, true);
    }
}

