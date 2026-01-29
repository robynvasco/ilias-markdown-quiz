<?php
declare(strict_types=1);
/**
 *  This file is part of the Markdown Quiz Repository Object plugin for ILIAS, which allows your platform's users
 *  To create interactive quizzes in Markdown format with AI assistance
 *  This plugin is adapted from the AI Chat plugin.
 *
 *  The Markdown Quiz Repository Object plugin for ILIAS is open-source and licensed under GPL-3.0.
 *
 */

namespace platform;


/**
 * Class ilMarkdownQuizConfig
 */
class ilMarkdownQuizConfig
{
    private static array $config = [];
    private static array $updated = [];

    /**
     * Load the plugin configuration
     * @return void
     * @throws ilMarkdownQuizException
     */
    public static function load(): void
    {
        try {
            $config = (new ilMarkdownQuizDatabase)->select('xmdq_config');

            foreach ($config as $row) {
                if (isset($row['value']) && $row['value'] !== '') {
                    $json_decoded = json_decode($row['value'], true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row['value'] = $json_decoded;
                    }
                }

                self::$config[$row['name']] = $row['value'];
            }
        } catch (ilMarkdownQuizException $e) {
            // Silently ignore if table doesn't exist yet during plugin activation
            if (strpos($e->getMessage(), 'xmdq_config') !== false) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Set the plugin configuration value for a given key to a given value
     * @param string $key
     * @param $value
     * @return void
     */
    public static function set(string $key, $value): void
    {
        if (is_bool($value)) {
            $value = (int)$value;
        }

        if (!isset(self::$config[$key]) || self::$config[$key] !== $value) {
            self::$config[$key] = $value;
            self::$updated[$key] = true;
        }
    }

    /**
     * Gets the plugin configuration value for a given key
     * @param string $key
     * @return mixed|string
     * @throws ilMarkdownQuizException
     */
    public static function get(string $key)
    {
        return self::$config[$key] ?? self::getFromDB($key);
    }

    /**
     * Gets the plugin configuration value for a given key from the database
     * @param string $key
     * @return mixed|string
     * @throws ilMarkdownQuizException
     */
    public static function getFromDB(string $key)
    {
        try {
            $config = (new ilMarkdownQuizDatabase)->select('xmdq_config', array(
                'name' => $key
            ));

            if (count($config) > 0) {
                $json_decoded = json_decode($config[0]['value'], true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $config[0]['value'] = $json_decoded;
                }

                self::$config[$key] = $config[0]['value'];

                return $config[0]['value'];
            } else {
                return "";
            }
        } catch (ilMarkdownQuizException $e) {
            // Silently ignore if table doesn't exist yet during plugin activation
            if (strpos($e->getMessage(), 'xmdq_config') !== false) {
                return "";
            }
            throw $e;
        }
    }

    /**
     * Gets all the plugin configuration values
     * @return array
     */
    public static function getAll(): array
    {
        return self::$config;
    }

    /**
     * Save the plugin configuration if the parameter is updated
     * @return bool|string
     */
    public static function save()
    {
        foreach (self::$updated as $key => $exist) {
            if ($exist) {
                if (isset(self::$config[$key])) {
                    $data = array(
                        'name' => $key
                    );

                    if (is_array(self::$config[$key])) {
                        $data['value'] = json_encode(self::$config[$key]);
                    } else {
                        $data['value'] = self::$config[$key];
                    }

                    try {
                        (new ilMarkdownQuizDatabase)->insertOnDuplicatedKey('xmdq_config', $data);

                        self::$updated[$key] = false;
                    } catch (ilMarkdownQuizException $e) {
                        // Silently ignore if table doesn't exist yet during plugin activation
                        if (strpos($e->getMessage(), 'xmdq_config') !== false) {
                            continue;
                        }
                        return $e->getMessage();
                    }
                }
            }
        }

        // In case there is nothing to update, return true to avoid error messages
        return true;
    }
}


