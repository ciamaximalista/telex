<?php
declare(strict_types=1);

if (!function_exists('telex_data_dir')) {
    function telex_data_dir(): string
    {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return realpath($dir) ?: $dir;
    }
}

if (!function_exists('telex_config_path')) {
    function telex_config_path(): string
    {
        return telex_data_dir() . '/config.json';
    }
}

if (!function_exists('telex_legacy_pm2_path')) {
    function telex_legacy_pm2_path(): string
    {
        return telex_data_dir() . '/pm2_env.json';
    }
}

if (!function_exists('telex_config_defaults')) {
    function telex_config_defaults(): array
    {
        return [
            'apis' => [
                'gemini' => [
                    'api_key' => '',
                    'model' => 'gemini-1.5-flash-latest',
                ],
                'google_translate' => [
                    'api_key' => '',
                ],
            ],
            'feeds' => [
                'input' => 'rss.xml',
                'translations' => [
                    'en' => 'rss_en.xml',
                ],
                'customizations' => [],
            ],
            'translator' => [
                'target_language' => 'en',
                'interval_ms' => 60000,
            ],
            'telegram' => [
                'auto_send' => [
                    'es' => true,
                ],
            ],
            'suggestions' => [
                'last_run' => null,
            ],
        ];
    }
}

if (!function_exists('telex_normalize_feed_filename')) {
    function telex_normalize_feed_filename(string $path): string
    {
        $basename = basename($path);
        return $basename !== '' ? $basename : 'rss.xml';
    }
}

if (!function_exists('telex_merge_config')) {
    function telex_merge_config(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = telex_merge_config($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}

if (!function_exists('telex_migrate_legacy_env')) {
    function telex_migrate_legacy_env(array $config): array
    {
        $legacyPath = telex_legacy_pm2_path();
        if (!file_exists($legacyPath)) {
            return $config;
        }
        $legacyRaw = @file_get_contents($legacyPath);
        $legacy = json_decode($legacyRaw ?: '', true);
        if (!is_array($legacy)) {
            return $config;
        }

        if (!empty($legacy['GEMINI_API_KEY'])) {
            $config['apis']['gemini']['api_key'] = (string)$legacy['GEMINI_API_KEY'];
        }
        if (!empty($legacy['GEMINI_MODEL'])) {
            $config['apis']['gemini']['model'] = (string)$legacy['GEMINI_MODEL'];
        }
        if (!empty($legacy['GOOGLE_TRANSLATE_API_KEY'])) {
            $config['apis']['google_translate']['api_key'] = (string)$legacy['GOOGLE_TRANSLATE_API_KEY'];
        }

        if (!empty($legacy['TRANSLATOR_TARGET_LANG'])) {
            $target = strtolower((string)$legacy['TRANSLATOR_TARGET_LANG']);
            $config['translator']['target_language'] = $target;
            $output = isset($legacy['OUTPUT_RSS']) ? telex_normalize_feed_filename((string)$legacy['OUTPUT_RSS']) : ('rss_' . $target . '.xml');
            $config['feeds']['translations'][$target] = $output;
        }

        if (!empty($legacy['TRANSLATOR_INTERVAL_MS'])) {
            $config['translator']['interval_ms'] = (int)$legacy['TRANSLATOR_INTERVAL_MS'];
        }

        if (isset($legacy['INPUT_RSS'])) {
            $config['feeds']['input'] = telex_normalize_feed_filename((string)$legacy['INPUT_RSS']);
        }

        if (isset($legacy['TELEGRAM_AUTO_SEND_ES'])) {
            $flag = strtolower((string)$legacy['TELEGRAM_AUTO_SEND_ES']);
            $config['telegram']['auto_send']['es'] = !in_array($flag, ['0', 'false', 'off', 'no'], true);
        }

        // Preserve additional output mapping if present
        if (isset($legacy['OUTPUT_RSS'])) {
            $filename = telex_normalize_feed_filename((string)$legacy['OUTPUT_RSS']);
            $lang = $config['translator']['target_language'] ?? 'en';
            $config['feeds']['translations'][$lang] = $filename;
        }

        // Archive legacy file
        $archive = $legacyPath . '.legacy';
        @rename($legacyPath, $archive);

        return $config;
    }
}

if (!function_exists('telex_load_config')) {
    function telex_load_config(bool $with_migration = true): array
    {
        $defaults = telex_config_defaults();
        $path = telex_config_path();
        if (!file_exists($path)) {
            $encoded = json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                @file_put_contents($path, $encoded . "\n");
            }
            $config = $defaults;
        } else {
            $raw = @file_get_contents($path);
            $data = json_decode($raw ?: '', true);
            if (!is_array($data)) {
                $config = $defaults;
            } else {
                $config = telex_merge_config($defaults, $data);
            }
        }

        if ($with_migration) {
            $config = telex_migrate_legacy_env($config);
            telex_save_config($config);
        }

        return $config;
    }
}

if (!function_exists('telex_save_config')) {
    function telex_save_config(array $config): bool
    {
        $path = telex_config_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }
        $encoded .= "\n";
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $encoded) === false) {
            return false;
        }
        $ok = @rename($tmp, $path);
        if (!$ok) {
            $ok = @file_put_contents($path, $encoded) !== false;
            @unlink($tmp);
        }
        if ($ok) {
            @chmod($path, 0664);
        }
        return $ok;
    }
}

if (!function_exists('telex_config_get')) {
    function telex_config_get(array $config, array $path, $default = null)
    {
        $node = $config;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }
}

if (!function_exists('telex_config_set')) {
    function telex_config_set(array &$config, array $path, $value): void
    {
        $node =& $config;
        foreach ($path as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node =& $node[$segment];
        }
        $node = $value;
    }
}

if (!function_exists('telex_feed_output_path')) {
    function telex_feed_output_path(array $config, string $lang): string
    {
        $filename = telex_feed_output_filename($config, $lang);
        return __DIR__ . '/../' . $filename;
    }
}

if (!function_exists('telex_feed_input_path')) {
    function telex_feed_input_path(array $config): string
    {
        $filename = $config['feeds']['input'] ?? 'rss.xml';
        return __DIR__ . '/../' . $filename;
    }
}

if (!function_exists('telex_feed_output_filename')) {
    function telex_feed_output_filename(array $config, string $lang): string
    {
        $translations = $config['feeds']['translations'] ?? [];
        return $translations[$lang] ?? ('rss_' . $lang . '.xml');
    }
}
