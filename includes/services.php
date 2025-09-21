<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('telex_read_json')) {
    function telex_read_json(string $path, $default = null)
    {
        if (!file_exists($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $default;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }
}

if (!function_exists('telex_write_json')) {
    function telex_write_json(string $path, $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

if (!function_exists('telex_generate_suggestion_id')) {
    function telex_generate_suggestion_id(): string
    {
        $milliseconds = (int)round(microtime(true) * 1000);
        try {
            $random = substr(bin2hex(random_bytes(8)), 0, 10);
        } catch (Throwable $e) {
            $random = substr(sha1((string)mt_rand()), 0, 10);
        }
        return sprintf('sug_%d_%s', $milliseconds, $random);
    }
}

if (!function_exists('telex_flag_enabled')) {
    function telex_flag_enabled($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string)$value));
        return !in_array($value, ['0', 'false', 'off', 'no', ''], true);
    }
}

if (!function_exists('telex_http_get')) {
    function telex_http_get(string $url, int $timeout = 20): ?string
    {
        $ch = curl_init();
        $headers = [
            'Accept: */*',
        ];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'TelexBot/1.0 (+https://ruralnext.org)',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) {
            return null;
        }
        if ($http >= 400 || $http === 0) {
            return null;
        }
        return is_string($resp) ? $resp : null;
    }
}

if (!function_exists('telex_fetch_feed_sources')) {
    function telex_fetch_feed_sources(string $sources_file): array
    {
        $sources = telex_read_json($sources_file, []);
        if (!is_array($sources)) {
            return [];
        }
        return array_values(array_filter(array_map(function ($entry) {
            if (!is_array($entry)) {
                return null;
            }
            $name = trim((string)($entry['name'] ?? ''));
            $url = trim((string)($entry['url'] ?? ''));
            if ($name === '' || $url === '') {
                return null;
            }
            return [ 'name' => $name, 'url' => $url ];
        }, $sources)));
    }
}

if (!function_exists('telex_extract_feed_items')) {
    function telex_extract_feed_items(string $xmlContent, string $sourceName, int $limit): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if (!$xml) {
            return [];
        }
        $items = [];
        $entries = [];
        if (isset($xml->channel->item)) {
            $entries = $xml->channel->item;
        } elseif (isset($xml->entry)) {
            $entries = $xml->entry;
        }
        $count = 0;
        foreach ($entries as $entry) {
            if ($count >= $limit) {
                break;
            }
            $title = trim((string)($entry->title ?? ''));
            $link  = '';
            if (isset($entry->link)) {
                if ($entry->link instanceof SimpleXMLElement && isset($entry->link['href'])) {
                    $link = trim((string)$entry->link['href']);
                } else {
                    $link = trim((string)$entry->link);
                }
            }
            if ($link === '' && isset($entry->guid)) {
                $link = trim((string)$entry->guid);
            }
            $description = '';
            if (isset($entry->description)) {
                $description = trim((string)$entry->description);
            } elseif (isset($entry->summary)) {
                $description = trim((string)$entry->summary);
            } elseif (isset($entry->children('http://purl.org/rss/1.0/modules/content/')->encoded)) {
                $description = trim((string)$entry->children('http://purl.org/rss/1.0/modules/content/')->encoded);
            }
            if ($title === '' || $link === '') {
                continue;
            }
            $pub = '';
            if (isset($entry->pubDate)) {
                $pub = trim((string)$entry->pubDate);
            } elseif (isset($entry->updated)) {
                $pub = trim((string)$entry->updated);
            }
            $items[] = [
                'title' => $title,
                'description' => $description,
                'link' => $link,
                'published' => $pub,
                'source' => $sourceName,
            ];
            $count++;
        }
        return $items;
    }
}

if (!function_exists('telex_collect_feed_items')) {
    function telex_collect_feed_items(array $sources, int $limit_per_source = 5): array
    {
        $items = [];
        foreach ($sources as $source) {
            $url = $source['url'];
            $content = telex_http_get($url);
            if ($content === null) {
                continue;
            }
            $items = array_merge($items, telex_extract_feed_items($content, $source['name'], $limit_per_source));
        }
        return $items;
    }
}

if (!function_exists('telex_format_examples_for_prompt')) {
    function telex_format_examples_for_prompt(array $examples, int $limit = 5): string
    {
        if (!$examples) {
            return '';
        }
        $examples = array_slice(array_reverse($examples), 0, $limit);
        $lines = [];
        foreach ($examples as $example) {
            if (!is_array($example)) {
                continue;
            }
            $line = trim((string)($example['resumen_final'] ?? $example['resumen_original'] ?? ''));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('telex_gemini_request')) {
    function telex_gemini_request(string $apiKey, string $model, string $prompt, array $context, string $logFile): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $payload = [
            'contents' => [
                [
                    'parts' => [ [ 'text' => $prompt ] ],
                ],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [ 'Content-Type: application/json' ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $logEntry = [
            'timestamp' => date('c'),
            'model' => $model,
            'http_status' => $http,
            'context' => $context,
        ];
        if ($err) {
            $logEntry['error'] = $err;
            @file_put_contents($logFile, json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            return [ 'ok' => false, 'error' => $err ];
        }
        $data = json_decode($resp ?: '', true);
        $logEntry['response'] = $data;
        @file_put_contents($logFile, json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

        if ($http < 200 || $http >= 300) {
            $msg = $data['error']['message'] ?? 'Gemini HTTP ' . $http;
            return [ 'ok' => false, 'error' => $msg ];
        }
        $text = '';
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $text = (string)$data['candidates'][0]['content']['parts'][0]['text'];
        }
        return [ 'ok' => true, 'text' => $text, 'raw' => $data ];
    }
}

if (!function_exists('telex_normalize_link')) {
    function telex_normalize_link(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!$parts) {
            return $url;
        }
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . $path . $query;
    }
}

if (!function_exists('telex_generate_suggestions')) {
    function telex_generate_suggestions(array $config, string $prompt_file, string $sources_file, string $suggestions_file, string $examples_file, string $log_file, string $published_file, string $titlekeys_file, int $limit_per_source = 4): array
    {
        $apiKey = telex_config_get($config, ['apis', 'gemini', 'api_key'], '');
        if ($apiKey === '') {
            return [ 'ok' => false, 'message' => 'Falta la clave de Gemini.' ];
        }
        $model = telex_config_get($config, ['apis', 'gemini', 'model'], 'gemini-1.5-flash-latest');
        $promptTemplate = @file_get_contents($prompt_file);
        if (trim((string)$promptTemplate) === '') {
            return [ 'ok' => false, 'message' => 'No se encontró prompt de Gemini.' ];
        }

        $sources = telex_fetch_feed_sources($sources_file);
        if (!$sources) {
            return [ 'ok' => false, 'message' => 'No hay fuentes configuradas.' ];
        }

        $items = telex_collect_feed_items($sources, $limit_per_source);
        if (!$items) {
            return [ 'ok' => false, 'message' => 'Ninguna fuente devolvió elementos nuevos.' ];
        }

        $examples = telex_format_examples_for_prompt(telex_read_json($examples_file, []));
        $existing = telex_read_json($suggestions_file, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        $existingLinks = [];
        foreach ($existing as $sug) {
            if (is_array($sug) && !empty($sug['link'])) {
                $existingLinks[telex_normalize_link($sug['link'])] = true;
            }
        }

        $published = telex_read_json($published_file, []);
        if (is_array($published)) {
            foreach ($published as $pub) {
                if (is_array($pub) && !empty($pub['link'])) {
                    $existingLinks[telex_normalize_link($pub['link'])] = true;
                }
            }
        }
        $titlekeys = telex_read_json($titlekeys_file, []);
        if (!is_array($titlekeys)) {
            $titlekeys = [];
        }

        $created = 0; $skipped = 0; $errors = 0;
        foreach ($items as $article) {
            $linkKey = telex_normalize_link($article['link']);
            if ($linkKey !== '' && isset($existingLinks[$linkKey])) {
                $skipped++;
                continue;
            }
            $titleKey = function_exists('title_key') ? title_key($article['title']) : '';
            if ($titleKey !== '' && in_array($titleKey, $titlekeys, true)) {
                $skipped++;
                continue;
            }

            $prompt = strtr($promptTemplate, [
                '{{examples}}' => $examples,
                '{{title}}' => $article['title'],
                '{{description}}' => $article['description'],
                '{{link}}' => $article['link'],
            ]);
            $response = telex_gemini_request($apiKey, $model, $prompt, [
                'title' => $article['title'],
                'link' => $article['link'],
                'source' => $article['source'],
            ], $log_file);
            if (!$response['ok']) {
                $errors++;
                continue;
            }
            $text = trim((string)($response['text'] ?? ''));
            if ($text === '' || strtoupper($text) === 'IGNORAR') {
                $skipped++;
                continue;
            }
            $id = telex_generate_suggestion_id();
            $existing[] = [
                'id' => $id,
                'title' => $article['title'],
                'link' => $article['link'],
                'summary' => $text,
                'source' => $article['source'],
                'published' => $article['published'],
            ];
            if ($linkKey !== '') {
                $existingLinks[$linkKey] = true;
            }
            $created++;
        }

        telex_write_json($suggestions_file, $existing);

        if ($created === 0 && $errors > 0) {
            return [ 'ok' => false, 'message' => 'No se generaron sugerencias (verifica el log de Gemini).' ];
        }

        return [
            'ok' => true,
            'created' => $created,
            'skipped' => $skipped,
            'message' => $created > 0 ? "Se generaron {$created} sugerencias." : 'No se generaron sugerencias nuevas.',
            'errors' => $errors,
        ];
    }
}

if (!function_exists('telex_google_translate')) {
    function telex_google_translate(array $texts, string $apiKey, string $targetLang, string $sourceLang = 'es', string $format = 'html'): array
    {
        $url = 'https://translation.googleapis.com/language/translate/v2';
        $payload = [
            'target' => $targetLang,
            'format' => $format,
        ];
        if ($sourceLang !== '') {
            $payload['source'] = $sourceLang;
        }
        $fields = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        foreach ($texts as $text) {
            $fields .= '&' . http_build_query(['q' => $text], '', '&', PHP_QUERY_RFC3986);
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url . '?key=' . rawurlencode($apiKey),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => [ 'Content-Type: application/x-www-form-urlencoded' ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err || $http < 200 || $http >= 300) {
            return [];
        }
        $data = json_decode($resp ?: '', true);
        if (!is_array($data) || !isset($data['data']['translations'])) {
            return [];
        }
        return array_map(function ($entry) {
            return (string)($entry['translatedText'] ?? '');
        }, $data['data']['translations']);
    }
}

if (!function_exists('telex_translate_feed')) {
    function telex_translate_feed(array $config, string $input_path, string $output_path, string $target_lang, string $translation_cache_file, string $change_cache_file, bool $force = false): array
    {
        $apiKey = telex_config_get($config, ['apis', 'google_translate', 'api_key'], '');
        if ($apiKey === '') {
            return [ 'ok' => false, 'message' => 'Falta la clave de Google Translate.' ];
        }
        if (!file_exists($input_path)) {
            return [ 'ok' => false, 'message' => 'No se encontró el feed base para traducir.' ];
        }

        $currentHash = hash_file('sha256', $input_path);
        $changeCache = telex_read_json($change_cache_file, []);
        $lastHash = $changeCache[$target_lang]['hash'] ?? null;
        if (!$force && $lastHash === $currentHash && file_exists($output_path)) {
            return [ 'ok' => true, 'message' => 'Sin cambios en el feed base. Traducción reutilizada.', 'skipped' => true ];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($input_path);
        if (!$xml || !isset($xml->channel)) {
            return [ 'ok' => false, 'message' => 'El feed base está mal formado.' ];
        }

        $translationCache = telex_read_json($translation_cache_file, []);
        if (!is_array($translationCache)) {
            $translationCache = [];
        }

        $items = [];
        foreach ($xml->channel->item as $item) {
            $title = (string)$item->title;
            $description = (string)$item->description;
            $guid = (string)($item->guid ?? $item->link ?? md5($title . $description));
            $contentNode = $item->children('http://purl.org/rss/1.0/modules/content/');
            $content = isset($contentNode->encoded) ? (string)$contentNode->encoded : '';
            $items[] = [
                'guid' => $guid,
                'title' => $title,
                'description' => $description,
                'content' => $content,
                'link' => (string)($item->link ?? ''),
                'pubDate' => (string)($item->pubDate ?? ''),
            ];
        }

        $translatedItems = [];
        foreach ($items as $item) {
            $cacheKey = $target_lang . '|' . $item['guid'];
            $sourceHash = hash('sha256', $item['title'] . "\n" . $item['description'] . "\n" . $item['content']);
            if (isset($translationCache[$cacheKey]) && ($translationCache[$cacheKey]['hash'] ?? '') === $sourceHash) {
                $translatedItems[] = $translationCache[$cacheKey]['data'];
                continue;
            }
            $texts = [$item['title'], $item['description']];
            if ($item['content'] !== '') {
                $texts[] = $item['content'];
            }
            $translated = telex_google_translate($texts, $apiKey, $target_lang, 'es', 'html');
            if (!$translated) {
                return [ 'ok' => false, 'message' => 'Error traduciendo uno de los elementos.'];
            }
            $titleTranslated = $translated[0] ?? $item['title'];
            $descTranslated = $translated[1] ?? $item['description'];
            $contentTranslated = $item['content'] !== '' ? ($translated[2] ?? $item['content']) : '';
            $data = [
                'guid' => $item['guid'],
                'title' => $titleTranslated,
                'description' => $descTranslated,
                'content' => $contentTranslated,
                'link' => $item['link'],
                'pubDate' => $item['pubDate'],
            ];
            $translationCache[$cacheKey] = [
                'hash' => $sourceHash,
                'updated_at' => date('c'),
                'data' => $data,
            ];
            $translatedItems[] = $data;
        }

        // Construir nuevo feed
        $new = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
        foreach ($xml->channel->children() as $child) {
            if ($child->getName() !== 'item') {
                $new->channel->addChild($child->getName(), (string)$child);
            }
        }
        foreach ($translatedItems as $entry) {
            $item = $new->channel->addChild('item');
            $item->addChild('title', $entry['title']);
            if ($entry['link'] !== '') {
                $item->addChild('link', $entry['link']);
            }
            if ($entry['guid'] !== '') {
                $item->addChild('guid', $entry['guid']);
            }
            if ($entry['pubDate'] !== '') {
                $item->addChild('pubDate', $entry['pubDate']);
            }
            $descNode = $item->addChild('description');
            $descNode[0] = null;
            $descDom = dom_import_simplexml($descNode);
            if ($descDom) {
                $descDom->appendChild($descDom->ownerDocument->createCDATASection($entry['description']));
            }
            if ($entry['content'] !== '') {
                $c = $item->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/');
                $c[0] = null;
                $contentDom = dom_import_simplexml($c);
                if ($contentDom) {
                    $contentDom->appendChild($contentDom->ownerDocument->createCDATASection($entry['content']));
                }
            }
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($new->asXML());
        if (!safe_dom_save($dom, $output_path)) {
            return [ 'ok' => false, 'message' => 'No se pudo guardar el feed traducido.' ];
        }

        telex_write_json($translation_cache_file, $translationCache);
        $changeCache[$target_lang] = [ 'hash' => $currentHash, 'updated_at' => date('c') ];
        telex_write_json($change_cache_file, $changeCache);

        return [ 'ok' => true, 'message' => 'Feed traducido correctamente.' ];
    }
}
