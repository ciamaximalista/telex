<?php
umask(0002);
// Telex Beta

// --- 1. LÓGICA DE AUTENTICACIÓN / REGISTRO ---
session_start();

$data_dir = __DIR__ . '/data';
if (!is_dir($data_dir)) { @mkdir($data_dir, 0775, true); }
$credentials_file = $data_dir . '/auth.json';

// Si no hay credenciales creadas, redirige a la página de registro
if (!file_exists($credentials_file)) {
    header('Location: register.php');
    exit;
}

// Intento de login
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $creds = json_decode(@file_get_contents($credentials_file), true) ?: [];
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if (!empty($creds['username']) && !empty($creds['password_hash']) && $u === $creds['username'] && password_verify($p, $creds['password_hash'])) {
        $_SESSION['loggedin'] = true;
        header('Location: telex.php');
        exit;
    } else {
        $login_error = 'Usuario o contraseña incorrectos.';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: telex.php');
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ?>
    <!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Telex — Acceso</title><meta name="viewport" content="width=device-width, initial-scale=1.0"><link href="telex.png" rel="icon" type="image/png"/>
    <link href="telex.png" rel="icon" type="image/png"/><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Special+Elite&display=swap" rel="stylesheet">
    <style>
      body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background-color:#f8f9fa;}
      .login-container{background:white;padding:2rem 3rem;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);width:320px;}
      h1{text-align:center;margin-bottom:1.5rem;font-weight:500;color:#333;}
      .form-group{margin-bottom:1rem;}
      input{width:100%;padding:.75rem;box-sizing:border-box;border:1px solid #ccc;border-radius:4px;font-size:1rem;}
      .button{width:100%;padding:.75rem;background-color:#0d6efd;color:white;border:none;border-radius:4px;cursor:pointer;font-size:1rem;}
      .error{color:#dc3545;text-align:center;margin-top:1rem;font-size:.9rem;}
      .special-elite-regular {font-family: "Special Elite", system-ui; font-weight: 400;font-style: normal; font-size:2em; color:#0d6efd; padding:16px; padding-top:0px;}
    </style></head>
    <body>
        <div class="login-container">
            <form method="post">
                <img src="telex.png" alt="Telex" style="display:block;margin:auto;width:92px;height:auto;" />
                <h1 class="special-elite-regular">Acceso al Telex</h1>
                <div class="form-group"><input type="text" name="username" placeholder="Usuario" required autofocus></div>
                <div class="form-group"><input type="password" name="password" placeholder="Contraseña" required></div>
                <button type="submit" class="button">Entrar</button>
                <?php if($login_error) echo "<p class='error'>".htmlspecialchars($login_error)."</p>"; ?>
            </form>
        </div>
    </body></html>
    <?php
    exit;
}
// --- FIN DE LA LÓGICA DE AUTENTICACIÓN / REGISTRO ---


// --- 2. LÓGICA DE LA APLICACIÓN ---
ini_set('display_errors', 1); error_reporting(E_ALL);
set_time_limit(300);

// Nota: $data_dir ya fue creado arriba
// Carpeta de imágenes para entradas manuales
$img_dir = __DIR__ . '/img';
if (!is_dir($img_dir)) { @mkdir($img_dir, 0775, true); }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/services.php';

$sugerencias_file     = $data_dir . '/sugerencias_pendientes.json';
$prompt_file          = $data_dir . '/prompt.txt';
$sources_file         = $data_dir . '/sources.json';

// --- OPML EXPORT ---
if (isset($_POST['export_opml'])) {
    $sources = telex_fetch_feed_sources($sources_file);
    
    $opml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><opml version="2.0"></opml>');
    $head = $opml->addChild('head');
    $head->addChild('title', 'Telex Feeds');
    $body = $opml->addChild('body');

    if (!empty($sources)) {
        foreach ($sources as $source) {
            $outline = $body->addChild('outline');
            $outline->addAttribute('text', $source['name']);
            $outline->addAttribute('type', 'rss');
            $outline->addAttribute('xmlUrl', $source['url']);
        }
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="telex_feeds.opml"');
    echo $opml->asXML();
    exit;
}
// --- END OPML EXPORT ---
$prompt_file          = $data_dir . '/prompt.txt';
$sources_file         = $data_dir . '/sources.json';

$config = telex_load_config();
$target_lang = strtolower($config['translator']['target_language'] ?? 'en');
$rss_file = telex_feed_input_path($config);
// Personalizaciones de feeds por idioma (debe declararse antes de usarla en $rss_en_file)
$feed_custom_file     = $data_dir . '/feed_customizations.json';
$feed_custom          = file_exists($feed_custom_file) ? (json_decode(@file_get_contents($feed_custom_file), true) ?: []) : [];
// Resolver nombre de fichero para un idioma (usa personalización si existe)
function feed_filename_for_lang($lang, $feed_custom) {
    $lang = strtolower((string)$lang);
    // La base en español siempre es rss.xml
    if ($lang === 'es') { return 'rss.xml'; }
    if (isset($feed_custom[$lang]['filename']) && $feed_custom[$lang]['filename']) {
        return $feed_custom[$lang]['filename'];
    }
    return 'rss_' . $lang . '.xml';
}
$target_feed_filename = telex_feed_output_filename($config, $target_lang);
$rss_en_file          = __DIR__ . '/' . $target_feed_filename;
$examples_file        = $data_dir . '/examples.json';
$published_file       = $data_dir . '/published_messages.json';
$cache_titles_file    = $data_dir . '/.sent_titles_cache.json';
$titlekeys_file       = $data_dir . '/.sent_titlekeys_cache.json';
$gemini_log_file      = $data_dir . '/gemini_log.jsonl'; // <-- 1. RUTA DEL NUEVO LOG
$analysis_prompt_file = $data_dir . '/analysis_prompt.txt';
// Caches del traductor
$rss_change_cache     = $data_dir . '/rss_change_cache.json';
$translation_cache    = $data_dir . '/translation_cache.json';
// Telegram bots tokens por idioma
$telegram_tokens_file = $data_dir . '/telegram_tokens.json';
// Registro de items enviados a Telegram por idioma
$telegram_sent_file   = $data_dir . '/telegram_sent.json';

// Guardado robusto: escribe a un temporal y renombra sobre el destino
function safe_dom_save(\DOMDocument $dom, string $path) {
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    // Crear temporal en el mismo directorio para permitir rename atómico
    $tmp = @tempnam($dir, 'tmp_rss_');
    if ($tmp === false) {
        return $dom->save($path);
    }
    $ok = $dom->save($tmp);
    if ($ok === false) {
        @unlink($tmp);
        return false;
    }
    // Intentar reemplazo atómico
    if (@rename($tmp, $path)) {
        @chmod($path, 0664);
        return true;
    }
    // Fallback: copiar sobre el destino
    $ok2 = @copy($tmp, $path);
    @unlink($tmp);
    if ($ok2) {
        @chmod($path, 0664);
        return true;
    }
    return false;
}

function dom_find_child(DOMElement $parent, string $localName, ?string $namespace = null): ?DOMElement {
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement) {
            if ($child->localName === $localName && ($namespace === null || $child->namespaceURI === $namespace)) {
                return $child;
            }
        }
    }
    return null;
}

function dom_ensure_child(DOMDocument $doc, DOMElement $parent, string $localName, ?string $namespace = null, ?string $qualifiedName = null): DOMElement {
    $existing = dom_find_child($parent, $localName, $namespace);
    if ($existing instanceof DOMElement) {
        return $existing;
    }
    if ($namespace !== null) {
        $node = $doc->createElementNS($namespace, $qualifiedName ?? $localName);
    } else {
        $node = $doc->createElement($localName);
    }
    return $parent->appendChild($node);
}

function dom_set_content(DOMDocument $doc, DOMElement $element, string $content, bool $asCdata = false): void {
    while ($element->firstChild) {
        $element->removeChild($element->firstChild);
    }
    if ($asCdata) {
        $element->appendChild($doc->createCDATASection($content));
    } else {
        $element->appendChild($doc->createTextNode($content));
    }
}

// Crear archivos de datos si no existen
function ensure_file($path, $content) {
    if (!file_exists($path)) { @file_put_contents($path, $content); }
}
// Proteger directorio data con .htaccess (denegar acceso directo)
ensure_file($data_dir . '/.htaccess', "Require all denied\n");
ensure_file($sugerencias_file, "[]\n");
ensure_file($examples_file, "[]\n");
ensure_file($published_file, "[]\n");
ensure_file($cache_titles_file, "[]\n");
ensure_file($titlekeys_file, "[]\n");
ensure_file($sources_file, "[]\n");
ensure_file($gemini_log_file, "");
ensure_file($rss_change_cache, "{}");
ensure_file($translation_cache, "{}");
ensure_file($telegram_tokens_file, "{}");
ensure_file($telegram_sent_file, "{}");
ensure_file($data_dir . '/telegram_forgotten.json', "{}");
if (!file_exists($prompt_file)) {
    $default_prompt = "CRITERIOS...\n- Usa {{title}}, {{description}}, {{link}} y {{examples}}.\nSalida: una línea, ≤40 palabras, o IGNORAR.";
    @file_put_contents($prompt_file, $default_prompt);
}

// --- Utilidades para Communalia (definidas antes de usarse) ---
if (!function_exists('title_key')) { function title_key($s) { $s = html_entity_decode((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); $s = mb_strtolower($s, 'UTF-8'); if (class_exists('Normalizer')) { $s = Normalizer::normalize($s, Normalizer::FORM_D); $s = preg_replace('/\p{Mn}+/u', '', $s); } $s = preg_replace('/(^|\s)#(\p{L}[\p{L}\p{M}\p{N}_-]+)/u', '$1$2', $s); $s = preg_replace('/[“”‘’«»"\'\.\!\?¡¿:;\(\)\{\}\[\],–—\-_\/\\\\]+/u', ' ', $s); $s = preg_replace('/\b(el|la|los|las|un|una|unos|unas|de|del|al|y|o|u|en|por|para|con|sin|sobre|entre|ante|bajo|tras|desde|hasta|que|se|lo|su|sus|a|e)\b/u', ' ', $s); $s = preg_replace('/\s+/u', ' ', trim($s)); $parts = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY); $seen = []; $uniq = []; foreach ($parts as $w) { if (mb_strlen($w,'UTF-8') <= 2) continue; if (isset($seen[$w])) continue; $seen[$w] = true; $uniq[] = $w; if (count($uniq) >= 12) break; } return implode(' ', $uniq); } }
$DATA_DIR = __DIR__ . '/data';
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0775, true); }
$TITLEKEYS_FILE = $DATA_DIR . '/.sent_titlekeys_cache.json';
function read_titlekeys($file) { if (!file_exists($file)) return []; $json = file_get_contents($file); $arr = json_decode($json, true); return is_array($arr) ? $arr : []; }
function write_titlekeys($file, $arr) { $arr = array_values(array_unique(array_filter(array_map('strval', $arr)))); $tmp = $file . '.tmp'; file_put_contents($tmp, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); rename($tmp, $file); }
if (!function_exists('normalize_key')) { function normalize_key($k) { $k = html_entity_decode((string)$k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); $k = trim($k); if (!preg_match('#^https?://[^/]+/$#i', $k)) { $k = rtrim($k, '/'); } return $k; } }
if (!function_exists('item_pub_ts')) { function item_pub_ts($item) { $ts = 0; if (isset($item->pubDate)) { $t = strtotime((string)$item->pubDate); if ($t !== false) $ts = $t; } return $ts; } }
if (!function_exists('normalize_sent_cache')) { function normalize_sent_cache($sent) { if (!is_array($sent)) return []; $out = []; foreach ($sent as $k => $v) { $nk = normalize_key($k); if ($nk === '') continue; if (!isset($out[$nk])) { $out[$nk] = $v; } } return $out; } }

// Telegram helper
if (!function_exists('escape_md')) {
    function escape_md($text) {
        $map = [ '\\' => '\\\\', '_' => '\\_', '*' => '\\*', '`' => '\\`', '[' => '\\[' ];
        return strtr((string)$text, $map);
    }
}

if (!function_exists('normalize_plain_text')) {
    function normalize_plain_text($text) {
        $decoded = html_entity_decode((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $decoded = preg_replace('/[\s\x{00A0}]+/u', ' ', $decoded);
        return trim(mb_strtolower($decoded, 'UTF-8'));
    }
}

if (!function_exists('sanitize_feed_html')) {
    function sanitize_feed_html($title, $html) {
        $out = trim((string)$html);
        if ($out === '') {
            return '';
        }

        $normTitle = normalize_plain_text($title);

        $fallback = function ($html) {
            return trim(preg_replace('~^(?:\s*<br\s*/?>\s*)+~i', '', (string)$html));
        };

        libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapper = '<div>' . $out . '</div>';
        if (@$doc->loadHTML('<?xml encoding="utf-8" ?>' . $wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD) === false) {
            libxml_clear_errors();
            return $fallback($out);
        }
        libxml_clear_errors();

        $containers = $doc->getElementsByTagName('div');
        if ($containers->length === 0) {
            return $fallback($out);
        }
        $container = $containers->item(0);

        $shouldDropTitle = function (DOMNode $node) use ($normTitle) {
            if ($normTitle === '') {
                return false;
            }
            $text = normalize_plain_text($node->textContent);
            if ($text === '') {
                return true;
            }
            $trimmed = rtrim($text, " .,:;–—-!?…");
            return ($text === $normTitle) || ($trimmed === $normTitle);
        };

        $removeNode = function (DOMNode $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        };

        $cleanElementHead = function (DOMNode $element) use (&$cleanElementHead, $shouldDropTitle, $removeNode, $normTitle) {
            $changed = false;
            while ($element->firstChild) {
                $child = $element->firstChild;
                if ($child->nodeType === XML_TEXT_NODE) {
                    $textRaw = (string)$child->textContent;
                    $textNorm = normalize_plain_text($textRaw);
                    if ($textNorm === '' || ($normTitle !== '' && ($textNorm === $normTitle || rtrim($textNorm, " .,:;–—-!?…") === $normTitle))) {
                        $removeNode($child);
                        $changed = true;
                        continue;
                    }
                    // Eliminar puntuación sobrante tras quitar título
                    if ($normTitle !== '' && str_starts_with($textNorm, $normTitle)) {
                        $updated = ltrim(preg_replace('~^\s*' . preg_quote($normTitle, '~') . '\s*[\.:,;–—\-!?…]*\s*~iu', '', $textRaw));
                        if ($updated === '') {
                            $removeNode($child);
                            $changed = true;
                            continue;
                        }
                        if ($updated !== $textRaw) {
                            $child->nodeValue = $updated;
                            $changed = true;
                        }
                    }
                    break;
                }
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $name = strtolower($child->nodeName);
                    if (in_array($name, ['br', 'hr'], true)) {
                        $removeNode($child);
                        $changed = true;
                        continue;
                    }
                    if ($shouldDropTitle($child)) {
                        $removeNode($child);
                        $changed = true;
                        continue;
                    }
                    if ($name === 'p') {
                        $cleanElementHead($child);
                        if (normalize_plain_text($child->textContent) === '') {
                            $removeNode($child);
                            $changed = true;
                            continue;
                        }
                    }
                }
                break;
            }
            return $changed;
        };

        $iterations = 0;
        while ($container->firstChild && $iterations < 20) {
            $iterations++;
            $child = $container->firstChild;
            if ($child->nodeType === XML_TEXT_NODE) {
                $textRaw = (string)$child->textContent;
                $textNorm = normalize_plain_text($textRaw);
                if ($textNorm === '' || ($normTitle !== '' && ($textNorm === $normTitle || rtrim($textNorm, " .,:;–—-!?…") === $normTitle))) {
                    $removeNode($child);
                    continue;
                }
                if ($normTitle !== '' && str_starts_with($textNorm, $normTitle)) {
                    $updated = ltrim(preg_replace('~^\s*' . preg_quote($normTitle, '~') . '\s*[\.:,;–—\-!?…]*\s*~iu', '', $textRaw));
                    if ($updated === '') {
                        $removeNode($child);
                        continue;
                    }
                    if ($updated !== $textRaw) {
                        $child->nodeValue = $updated;
                        continue;
                    }
                }
                break;
            }
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $name = strtolower($child->nodeName);
                if (in_array($name, ['br', 'hr'], true)) {
                    $removeNode($child);
                    continue;
                }
                if ($shouldDropTitle($child)) {
                    $removeNode($child);
                    continue;
                }
                if ($cleanElementHead($child)) {
                    if (normalize_plain_text($child->textContent) === '') {
                        $removeNode($child);
                        continue;
                    }
                    continue;
                }
            }
            break;
        }

        // Eliminar <br> o texto vacío restante al inicio
        while ($container->firstChild) {
            $child = $container->firstChild;
            if ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) === '') {
                $removeNode($child);
                continue;
            }
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'br') {
                $removeNode($child);
                continue;
            }
            break;
        }

        $result = '';
        foreach ($container->childNodes as $node) {
            $result .= $doc->saveHTML($node);
        }

        return $fallback($result);
    }
}

if (!function_exists('telex_strip_leading_title')) {
    function telex_strip_leading_title(string $text, string $title): string
    {
        $text = trim($text);
        $titleNorm = normalize_plain_text($title);
        if ($text === '' || $titleNorm === '') {
            return $text;
        }

        $textNorm = normalize_plain_text($text);
        if ($textNorm === '') {
            return $text;
        }

        if (str_starts_with($textNorm, $titleNorm)) {
            $pattern = '~^\s*' . preg_quote($title, '~') . '\s*[\.:,;–—\-!?…«»"\']*\s*~u';
            $updated = preg_replace($pattern, '', $text, 1, $count);
            if ($count > 0) {
                return trim($updated);
            }

            if (preg_match('~^([^.!?]{0,320}[.!?])\s*(.*)$~us', $text, $m)) {
                $leadNorm = normalize_plain_text($m[1]);
                if ($leadNorm !== '' && str_starts_with($leadNorm, $titleNorm)) {
                    return trim($m[2]);
                }
            }

            $wordsTitle = preg_split('~\s+~u', $titleNorm, -1, PREG_SPLIT_NO_EMPTY);
            $wordsText = preg_split('~\s+~u', $textNorm, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($wordsTitle) && !empty($wordsText)) {
                $match = true;
                foreach ($wordsTitle as $idx => $word) {
                    if (!isset($wordsText[$idx]) || $wordsText[$idx] !== $word) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    $textWordsOriginal = preg_split('~\s+~u', $text, -1, PREG_SPLIT_NO_EMPTY);
                    $remaining = array_slice($textWordsOriginal, count($wordsTitle));
                    return trim(implode(' ', $remaining));
                }
            }
        }

        return $text;
    }
}

if (!function_exists('telex_description_plain')) {
    function telex_description_plain(string $value, string $title = ''): string
    {
        $content = trim($value);
        if ($content === '') {
            return '';
        }

        $clean = sanitize_feed_html($title, $content);
        if ($clean !== '') {
            $content = $clean;
        }

        $content = preg_replace('~</p>\s*<p[^>]*>~i', "\n", $content);
        $content = preg_replace('~<br\s*/?>~i', "\n", $content);
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $content = preg_replace("/[\r\n]+/u", "\n", $content);
        $content = preg_replace("/\s+/u", ' ', $content);
        return trim($content);
    }
}

if (!function_exists('telex_build_html_from_text')) {
    function telex_build_html_from_text(string $text, ?string $imageUrl = null): string
    {
        $text = trim($text);
        $html = '';
        if ($imageUrl !== null && $imageUrl !== '') {
            $safeImg = htmlspecialchars($imageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p><img src="' . $safeImg . '" alt="" style="max-width:100%; height:auto;" /></p>';
        }

        if ($text !== '') {
            $paragraphs = preg_split('~\n+~u', $text);
            $paragraphs = array_filter(array_map('trim', $paragraphs), static function ($p) {
                return $p !== '';
            });
            if (!empty($paragraphs)) {
                foreach ($paragraphs as $p) {
                    $html .= '<p>' . htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                }
            } else {
                $html .= htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return $html;
    }
}

if (!function_exists('telex_save_rss_document')) {
    function telex_save_rss_document(SimpleXMLElement $rss, string $path): bool
    {
        $xmlRaw = $rss->asXML();
        if ($xmlRaw === false) {
            return false;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if (@$dom->loadXML($xmlRaw) === false) {
            return false;
        }

        $xpath = new DOMXPath($dom);
        $items = $xpath->query('/rss/channel/item');
        if (!$items) {
            return safe_dom_save($dom, $path);
        }

        $records = [];
        $idx = 0;
        foreach ($items as $itemNode) {
            /** @var DOMElement $itemNode */
            $linkNode = $itemNode->getElementsByTagName('link')->item(0);
            $guidNode = $itemNode->getElementsByTagName('guid')->item(0);
            $pubNode  = $itemNode->getElementsByTagName('pubDate')->item(0);

            $linkVal = $linkNode ? trim($linkNode->textContent) : '';
            $guidVal = $guidNode ? trim($guidNode->textContent) : '';
            $key = $linkVal !== '' ? $linkVal : $guidVal;
            if ($key === '') {
                $key = 'auto:' . md5($dom->saveXML($itemNode));
            }

            $timestamp = 0;
            if ($pubNode) {
                $ts = strtotime($pubNode->textContent);
                if ($ts !== false) {
                    $timestamp = $ts;
                }
            }

            $records[] = [
                'node' => $itemNode->cloneNode(true),
                'key'  => $key,
                'ts'   => $timestamp,
                'idx'  => $idx++,
            ];
        }

        foreach ($items as $itemNode) {
            $itemNode->parentNode->removeChild($itemNode);
        }

        usort($records, static function ($a, $b) {
            if ($a['ts'] === $b['ts']) {
                return $b['idx'] <=> $a['idx'];
            }
            return $b['ts'] <=> $a['ts'];
        });

        // Limitar a 200 ítems
        $maxItems = 200;
        if (count($records) > $maxItems) {
            $records = array_slice($records, 0, $maxItems);
        }

        $seen = [];
        $channel = $xpath->query('/rss/channel')->item(0);
        foreach ($records as $rec) {
            if (isset($seen[$rec['key']])) {
                continue;
            }
            $seen[$rec['key']] = true;
            $channel->appendChild($rec['node']);
        }

        return safe_dom_save($dom, $path);
    }
}

if (!function_exists('archive_post')) {
    function archive_post($item_data) {
        $archive_dir = __DIR__ . '/archive';
        if (!is_dir($archive_dir)) {
            @mkdir($archive_dir, 0755, true);
        }
        $archive_file = $archive_dir . '/' . date('Y-m') . '.xml';

        libxml_use_internal_errors(true);
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;

        if (file_exists($archive_file)) {
            $xml->load($archive_file, LIBXML_NSCLEAN);
        } else {
            // Crear estructura base si no existe
            $rss = $xml->createElement('rss');
            $rss->setAttribute('version', '2.0');
            $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
            $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', 'http://purl.org/dc/elements/1.1/');
            $xml->appendChild($rss);
            $channel = $xml->createElement('channel');
            $rss->appendChild($channel);
            $title = $xml->createElement('title', 'Telex Archive - ' . date('Y-m'));
            $channel->appendChild($title);
            $link = $xml->createElement('link', (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
            $channel->appendChild($link);
            $description = $xml->createElement('description', 'Archivo mensual de noticias para ' . date('F Y'));
            $channel->appendChild($description);
        }

        $channel = $xml->getElementsByTagName('channel')->item(0);
        if (!$channel) { return false; }

        $item = $xml->createElement('item');

        // Título
        $item->appendChild($xml->createElement('title'))->appendChild($xml->createCDATASection($item_data['title']));

        // Link
        if (!empty($item_data['link'])) {
            $item->appendChild($xml->createElement('link', htmlspecialchars($item_data['link'])));
        }

        // Descripción
        if (!empty($item_data['description'])) {
            $item->appendChild($xml->createElement('description'))->appendChild($xml->createCDATASection($item_data['description']));
        }

        // Guid
        $guid_val = !empty($item_data['link']) ? $item_data['link'] : uniqid('urn:uuid:');
        $guid = $xml->createElement('guid', htmlspecialchars($guid_val));
        $guid->setAttribute('isPermaLink', !empty($item_data['link']) ? 'true' : 'false');
        $item->appendChild($guid);

        // Fecha de publicación
        $pubDate = $xml->createElement('pubDate', date('r'));
        $item->appendChild($pubDate);

        // Imagen (enclosure)
        if (!empty($item_data['image'])) {
            $enclosure = $xml->createElement('enclosure');
            $enclosure->setAttribute('url', $item_data['image']);
            $enclosure->setAttribute('type', 'image/jpeg');
            $item->appendChild($enclosure);
        }

        // Insertar al principio del canal
        $first_item = $channel->getElementsByTagName('item')->item(0);
        if ($first_item) {
            $channel->insertBefore($item, $first_item);
        } else {
            $channel->appendChild($item);
        }

        return $xml->save($archive_file) !== false;
    }
}

if (!function_exists('tg_send')) {
    function tg_send($token, $chat_id, $title, $desc, $url, $photo_url = '') {
        $t = trim((string)$title);
        $d = trim((string)$desc);
        $u = trim((string)$url);

        if ($t !== '' && $d !== '') {
            $pattern = '~^\s*' . preg_quote($t, '~') . '\b[\s:–—-]*~ui';
            $d = preg_replace($pattern, '', $d, 1, $removed);
            if ($removed > 0) {
                $d = ltrim($d);
            }
        }

        if ($u !== '' && $d !== '' && mb_stripos($d, $u) !== false) {
            $u = '';
        }

        $title_md = $t !== '' ? ('*' . escape_md($t) . '*') : '';
        $desc_md  = $d !== '' ? escape_md($d) : '';

        $url_md = '';
        if ($u !== '' && !preg_match('~https?://~i', $d)) {
            $url_md = escape_md($u);
        }

        $compose = function($max_len = null) use ($title_md, $desc_md, $url_md) {
            $parts = [];
            if ($title_md !== '') $parts[] = $title_md;
            if ($desc_md !== '')  $parts[] = $desc_md;
            if ($url_md !== '')   $parts[] = $url_md;
            $text = implode("\n\n", $parts);
            if ($max_len !== null && mb_strlen($text, 'UTF-8') > $max_len) {
                $tlen = mb_strlen($title_md, 'UTF-8');
                $ulen = ($url_md !== '') ? (2 + mb_strlen($url_md, 'UTF-8')) : 0;
                $allow_desc = max(0, $max_len - $tlen - $ulen - ($desc_md !== '' ? 2 : 0));
                $desc_trim = $desc_md;
                if ($allow_desc < mb_strlen($desc_md, 'UTF-8')) {
                    $desc_trim = mb_substr($desc_md, 0, max(0, $allow_desc - 1), 'UTF-8') . '…';
                }
                $parts2 = [];
                if ($title_md !== '') $parts2[] = $title_md;
                if ($desc_trim !== '') $parts2[] = $desc_trim;
                if ($url_md !== '')    $parts2[] = $url_md;
                $text = implode("\n\n", $parts2);
            }
            return $text;
        };

        if ($photo_url) {
            $url_api = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendPhoto';
            $fields = [ 'chat_id' => $chat_id, 'photo' => $photo_url, 'caption' => $compose(1000), 'parse_mode' => 'Markdown' ];
        } else {
            $url_api = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
            $fields = [ 'chat_id' => $chat_id, 'text' => $compose(), 'parse_mode' => 'Markdown', 'disable_web_page_preview' => false ];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [ CURLOPT_URL => $url_api, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20 ]);
        $resp = curl_exec($ch); $err = curl_error($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($err) return ['ok' => false, 'error' => $err, 'http' => $http];
        $j = json_decode((string)$resp, true);
        if (is_array($j) && !empty($j['ok'])) return ['ok' => true];
        return ['ok' => false, 'error' => is_array($j) ? ($j['description'] ?? 'Error desconocido') : 'HTTP ' . $http, 'http' => $http];
    }
}

if (!function_exists('rss_item_parts')) {
    function rss_item_parts($item) {
        $title = (string)($item->title ?? '');
        $link  = (string)($item->link ?? '');
        $guid  = (string)($item->guid ?? '');
        $desc_raw = (string)($item->description ?? '');
        // Limpiar descripción eliminando cabecera duplicada y saltos innecesarios
        $desc_clean_html = sanitize_feed_html($title, $desc_raw);
        $desc_text = trim(html_entity_decode(strip_tags($desc_clean_html !== '' ? $desc_clean_html : $desc_raw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        // Imagen: enclosure o primera <img src>
        $img = '';
        if (isset($item->enclosure)) {
            foreach ($item->enclosure->attributes() as $k => $v) { if ((string)$k === 'url') { $img = (string)$v; break; } }
        }
        if ($img === '') {
            if (preg_match('/<img\s+[^>]*src=[\"\']([^\"\']+)[\"\']/i', $desc_raw, $m)) { $img = $m[1]; }
        }
        return [ 'title' => $title, 'desc' => $desc_text, 'url' => ($link ?: $guid), 'image' => $img ];
    }
}

if (!function_exists('derive_title_from_summary')) {
    function derive_title_from_summary($summary_html) {
        $text = trim(html_entity_decode(strip_tags((string)$summary_html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if ($text === '') return '';
        if (preg_match('/^(.*?[\.\!\?])(\s|$)/u', $text, $m)) {
            return trim($m[1]);
        }
        return mb_substr($text, 0, 140, 'UTF-8');
    }
}

// Eliminado: Integración con Telegram/Communalia

$message = ''; $message_type = '';
function lang_name_es($code) {
    static $map = [
        'af'=>'afrikáans','sq'=>'albanés','am'=>'amárico','ar'=>'árabe','hy'=>'armenio','as'=>'asamés','ay'=>'aimara','az'=>'azerbaiyano',
        'bm'=>'bambara','eu'=>'euskera','be'=>'bielorruso','bn'=>'bengalí','bho'=>'bhojpurí','bs'=>'bosnio','bg'=>'búlgaro','ca'=>'catalán',
        'ceb'=>'cebuano','zh'=>'chino','zh-cn'=>'chino (simplificado)','zh-tw'=>'chino (tradicional)','co'=>'corso','hr'=>'croata','cs'=>'checo',
        'da'=>'danés','dv'=>'divehi','doi'=>'dogri','nl'=>'neerlandés','en'=>'inglés','eo'=>'esperanto','et'=>'estonio','ee'=>'ewé','fil'=>'filipino',
        'fi'=>'finés','fr'=>'francés','fy'=>'frisón','gl'=>'gallego','ka'=>'georgiano','de'=>'alemán','el'=>'griego','gn'=>'guaraní','gu'=>'guyaratí',
        'ht'=>'criollo haitiano','ha'=>'hausa','haw'=>'hawaiano','he'=>'hebreo','iw'=>'hebreo','hi'=>'hindi','hmn'=>'hmong','hu'=>'húngaro',
        'is'=>'islandés','ig'=>'igbo','ilo'=>'ilocano','id'=>'indonesio','ga'=>'irlandés','it'=>'italiano','ja'=>'japonés','jv'=>'javanés',
        'kn'=>'canarés','kk'=>'kazajo','km'=>'jemer','rw'=>'kinyarwanda','gom'=>'konkani','ko'=>'coreano','kri'=>'krio','ku'=>'kurdo (kurmanji)',
        'ckb'=>'kurdo (sorani)','ky'=>'kirguís','lo'=>'lao','la'=>'latín','lv'=>'letón','ln'=>'lingala','lt'=>'lituano','lg'=>'luganda','lb'=>'luxemburgués',
        'mk'=>'macedonio','mai'=>'maithili','mg'=>'malgache','ms'=>'malayo','ml'=>'malayalam','mt'=>'maltés','mi'=>'maorí','mr'=>'maratí',
        'mni'=>'meitei (manipuri)','lus'=>'mizo','mn'=>'mongol','my'=>'birmano','ne'=>'nepalí','no'=>'noruego','or'=>'odia (oriya)','om'=>'oromo','ps'=>'pastún',
        'fa'=>'persa','pl'=>'polaco','pt'=>'portugués','pa'=>'panyabí','qu'=>'quechua','ro'=>'rumano','ru'=>'ruso','sm'=>'samoano','sa'=>'sánscrito',
        'gd'=>'gaélico escocés','nso'=>'sesotho del norte','st'=>'sesotho del sur','sn'=>'shona','sd'=>'sindhi','si'=>'cingalés','sk'=>'eslovaco',
        'sl'=>'esloveno','so'=>'somalí','es'=>'español','su'=>'sundanés','sw'=>'suajili','sv'=>'sueco','tl'=>'tagalo','tg'=>'tayiko','ta'=>'tamil',
        'tt'=>'tártaro','te'=>'telugu','th'=>'tailandés','ti'=>'tigriña','ts'=>'tsonga','tr'=>'turco','tk'=>'turcomano','uk'=>'ucraniano','ur'=>'urdu',
        'ug'=>'uigur','uz'=>'uzbeko','vi'=>'vietnamita','cy'=>'galés','xh'=>'xhosa','yi'=>'yidis','yo'=>'yoruba','zu'=>'zulú'
    ];
    $k = strtolower($code);
    return $map[$k] ?? strtoupper($code);
}
$translated_lang_name = lang_name_es($target_lang);
$active_tab = $_GET['tab'] ?? 'gemini';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active_tab = $_POST['active_tab'] ?? 'gemini';
    
    // 2.1 Recibir Telex (nuevo generador de sugerencias)
    if (isset($_POST['fetch_suggestions'])) {

        $result = telex_generate_suggestions(
            $config,
            $prompt_file,
            $sources_file,
            $sugerencias_file,
            $examples_file,
            $gemini_log_file,
            $published_file,
            $titlekeys_file
        );

        if (!empty($result['ok'])) {
            telex_config_set($config, ['suggestions', 'last_run'], date('c'));
            telex_save_config($config);
            $message = $result['message'] ?? 'Búsqueda completada.';
            $message_type = $result['created'] ?? 0 ? 'success' : 'info';
        } else {
            $message = $result['message'] ?? 'No se pudieron generar sugerencias.';
            $message_type = 'error';
        }
    }
    
    // Ejecutar traducción ahora (nuevo traductor PHP)
    if (isset($_POST['run_translator_now'])) {
        $active_tab = 'traduccion';
        $force = isset($_POST['force_translate']);
        $result = telex_translate_feed(
            $config,
            $rss_file,
            $rss_en_file,
            $target_lang,
            $translation_cache,
            $rss_change_cache,
            $force
        );
        if (!empty($result['ok'])) {
            $message = $result['message'] ?? 'Traducción completada.';
            $message_type = !empty($result['skipped']) ? 'info' : 'success';
        } else {
            $message = $result['message'] ?? 'No se pudo ejecutar la traducción.';
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['save_rss_item'])) {
        $active_tab = 'rss';
        $idx = intval($_POST['save_rss_item']);
        $titles = $_POST['rss_title'] ?? [];
        $descs  = $_POST['rss_description'] ?? [];
        $urls   = $_POST['rss_url'] ?? [];
        if (!file_exists($rss_file) || !is_writable($rss_file)) {
            $message = 'No se puede escribir en rss.xml.'; $message_type = 'error';
        } elseif (!isset($titles[$idx]) || !isset($descs[$idx]) || !isset($urls[$idx])) {
            $message = 'Datos incompletos para actualizar la entrada seleccionada.'; $message_type = 'error';
        } else {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            if (@$dom->load($rss_file) === false) {
                $message = 'rss.xml mal formado.'; $message_type = 'error';
            } else {
                $items = $dom->getElementsByTagName('item');
                if ($idx < 0 || $idx >= $items->length) {
                    $message = 'No se encontró la entrada solicitada en rss.xml.'; $message_type = 'error';
                } else {
                    $item = $items->item($idx);
                    $titleNode = dom_ensure_child($dom, $item, 'title');
                    dom_set_content($dom, $titleNode, (string)$titles[$idx], false);
                    $linkNode = dom_ensure_child($dom, $item, 'link');
                    dom_set_content($dom, $linkNode, (string)$urls[$idx], false);
                    $desc_html = telex_format_description_to_html((string)$descs[$idx]);
                    $descNode = dom_ensure_child($dom, $item, 'description');
                    dom_set_content($dom, $descNode, $desc_html, true);
                    $contentNode = dom_ensure_child($dom, $item, 'encoded', 'http://purl.org/rss/1.0/modules/content/', 'content:encoded');
                    dom_set_content($dom, $contentNode, $desc_html, true);
                    if (safe_dom_save($dom, $rss_file)) {
                        $message = 'Entrada actualizada en rss.xml.'; $message_type = 'success';
                    } else {
                        $message = 'No se pudo guardar rss.xml.'; $message_type = 'error';
                    }
                }
            }
        }
    }

    if (isset($_POST['delete_rss_item'])) {
        $active_tab = 'rss';
        $idx = intval($_POST['delete_rss_item']);
        if (!file_exists($rss_file) || !is_writable($rss_file)) {
            $message = 'No se puede escribir en rss.xml.'; $message_type = 'error';
        } else {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            if (@$dom->load($rss_file) === false) {
                $message = 'rss.xml mal formado.'; $message_type = 'error';
            } else {
                $items = $dom->getElementsByTagName('item');
                if ($idx < 0 || $idx >= $items->length) {
                    $message = 'No se encontró la entrada a borrar.'; $message_type = 'error';
                } else {
                    $item = $items->item($idx);
                    $item->parentNode->removeChild($item);
                    if (safe_dom_save($dom, $rss_file)) {
                        $message = 'Entrada eliminada de rss.xml.'; $message_type = 'success';
                    } else {
                        $message = 'No se pudo guardar rss.xml tras eliminar.'; $message_type = 'error';
                    }
                }
            }
        }
    }

    if (isset($_POST['save_rss_en_item'])) {
        $active_tab = 'traduccion';
        $idx = intval($_POST['save_rss_en_item']);
        $titles = $_POST['rss_en_title'] ?? [];
        $descs  = $_POST['rss_en_description'] ?? [];
        $urls   = $_POST['rss_en_url'] ?? [];
        if (!file_exists($rss_en_file) || !is_writable($rss_en_file)) {
            $message = 'No se puede escribir en ' . htmlspecialchars($target_feed_filename) . '.'; $message_type = 'error';
        } elseif (!isset($titles[$idx]) || !isset($descs[$idx]) || !isset($urls[$idx])) {
            $message = 'Datos incompletos para actualizar la entrada traducida.'; $message_type = 'error';
        } else {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            if (@$dom->load($rss_en_file) === false) {
                $message = $target_feed_filename . ' está mal formado.'; $message_type = 'error';
            } else {
                $items = $dom->getElementsByTagName('item');
                if ($idx < 0 || $idx >= $items->length) {
                    $message = 'No se encontró la entrada traducida a actualizar.'; $message_type = 'error';
                } else {
                    $item = $items->item($idx);
                    $titleNode = dom_ensure_child($dom, $item, 'title');
                    dom_set_content($dom, $titleNode, (string)$titles[$idx], false);
                    $linkNode = dom_ensure_child($dom, $item, 'link');
                    dom_set_content($dom, $linkNode, (string)$urls[$idx], false);
                    $desc_html = telex_format_description_to_html((string)$descs[$idx]);
                    $descNode = dom_ensure_child($dom, $item, 'description');
                    dom_set_content($dom, $descNode, $desc_html, true);
                    $contentNode = dom_ensure_child($dom, $item, 'encoded', 'http://purl.org/rss/1.0/modules/content/', 'content:encoded');
                    dom_set_content($dom, $contentNode, $desc_html, true);
                    if (safe_dom_save($dom, $rss_en_file)) {
                        $message = 'Entrada actualizada en ' . $target_feed_filename . '.'; $message_type = 'success';
                    } else {
                        $message = 'No se pudo guardar ' . $target_feed_filename . '.'; $message_type = 'error';
                    }
                }
            }
        }
    }

    if (isset($_POST['delete_rss_en_item'])) {
        $active_tab = 'traduccion';
        $idx = intval($_POST['delete_rss_en_item']);
        if (!file_exists($rss_en_file) || !is_writable($rss_en_file)) {
            $message = 'No se puede escribir en ' . htmlspecialchars($target_feed_filename) . '.'; $message_type = 'error';
        } else {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            if (@$dom->load($rss_en_file) === false) {
                $message = $target_feed_filename . ' está mal formado.'; $message_type = 'error';
            } else {
                $items = $dom->getElementsByTagName('item');
                if ($idx < 0 || $idx >= $items->length) {
                    $message = 'No se encontró la entrada traducida a borrar.'; $message_type = 'error';
                } else {
                    $item = $items->item($idx);
                    $item->parentNode->removeChild($item);
                    if (safe_dom_save($dom, $rss_en_file)) {
                        $message = 'Entrada eliminada de ' . $target_feed_filename . '.'; $message_type = 'success';
                    } else {
                        $message = 'No se pudo guardar ' . $target_feed_filename . ' tras eliminar.'; $message_type = 'error';
                    }
                }
            }
        }
    }

if (isset($_POST['action'])) {
    $sugerencias  = json_decode(@file_get_contents($sugerencias_file), true) ?: [];
    $examples     = json_decode(@file_get_contents($examples_file), true) ?: [];
    $published    = json_decode(@file_get_contents($published_file), true) ?: [];
    $sent_titles  = json_decode(@file_get_contents($cache_titles_file), true) ?: [];
    $sent_titlekeys = read_titlekeys($titlekeys_file);
    $titlekeys_file  = __DIR__ . '/data/.sent_titlekeys_cache.json';
    $sent_titlekeys  = json_decode(@file_get_contents($titlekeys_file), true) ?: [];
    $suggestion_id    = $_POST['suggestion_id'];
    $suggestion_index = array_search($suggestion_id, array_column($sugerencias, 'id'));

    if ($suggestion_index !== false) {
        $suggestion = $sugerencias[$suggestion_index];
        $finalTitle = '';
        $finalDescription = '';
        $finalLink = '';

        switch ($_POST['action']) {
            case 'approve':
                $finalTitle = $suggestion['title'] ?? '';
                $finalDescription = $suggestion['summary'] ?? '';
                $finalLink = $suggestion['link'] ?? '';
                $decision = 'enviada';
                break;
            case 'edit':
                $finalTitle = $_POST['title'] ?? '';
                $finalDescription = $_POST['description'] ?? '';
                $finalLink = $_POST['link'] ?? '';
                $decision = 'editada';
                break;
            case 'reject':
                $decision = 'descartada';
                break;
        }

        if (!empty($finalTitle)) { // Allow empty description
            $descPlain = telex_description_plain($finalDescription, $finalTitle);
            $descHtml = telex_format_description_to_html($finalDescription);

            // Cargar o crear rss.xml
            libxml_use_internal_errors(true);
            if (file_exists($rss_file)) {
                $rss = simplexml_load_file($rss_file);
            } else {
                $rss = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
                $rss->channel->addChild('title', 'Maximalismo — Noticias (ES)');
                $rss->channel->addChild('link', 'https://maximalismo.org/feed/');
                $rss->channel->addChild('description', 'Noticias seleccionadas y editadas desde Telex.');
                $rss->channel->addChild('language', 'es');
                $rss->channel->addChild('pubDate', date(DATE_RSS));
            }

            // Evitar duplicados por link/guid
            $existingKeys = [];
            if (isset($rss->channel->item)) {
                foreach ($rss->channel->item as $it) {
                    $ek = trim((string)($it->link ?? '')) ?: trim((string)($it->guid ?? ''));
                    if ($ek !== '') $existingKeys[$ek] = true;
                }
            }

            $link = $finalLink;
            $guid = $link !== '' ? $link : ('telex:' . md5($finalTitle . '|' . $descPlain . '|' . microtime(true)));
            if (!isset($existingKeys[$guid])) {
                $item = $rss->channel->addChild('item');
                $item->addChild('title', htmlspecialchars($finalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                if ($link) { $item->addChild('link', htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); }
                $item->addChild('guid', htmlspecialchars($guid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                
                $descNode = $item->addChild('description');
                $descNode[0] = null;
                $descDom = dom_import_simplexml($descNode);
                if ($descDom) {
                    $ownerDoc = $descDom->ownerDocument;
                    $descDom->appendChild($ownerDoc->createCDATASection($descHtml));
                }

                $contentNode = $item->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/');
                if ($contentNode) {
                    $contentDom = dom_import_simplexml($contentNode);
                    if ($contentDom) {
                        $ownerDoc = $contentDom->ownerDocument;
                        $contentDom->appendChild($ownerDoc->createCDATASection($descHtml));
                    }
                }
                $item->addChild('pubDate', date(DATE_RSS));

                telex_save_rss_document($rss, $rss_file);

                // Envío automático a Telegram (ES) si está activado
                if ($_POST['action'] === 'approve' || $_POST['action'] === 'edit') {
                    $auto_enabled = telex_flag_enabled(telex_config_get($config, ['telegram', 'auto_send', 'es'], true));
                    if ($auto_enabled) {
                        $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
                        $bot  = $bots['es'] ?? null;
                        if ($bot && (!is_array($bot) || (!empty($bot['token']) && !empty($bot['chat_id'])))) {
                            $token = is_array($bot) ? $bot['token'] : (string)$bot;
                            $chat  = is_array($bot) ? ($bot['chat_id'] ?? '') : '';
                            if ($token && $chat) {
                                $resp = tg_send($token, $chat, $finalTitle, $descPlain, $link, '');
                                $sent = file_exists($telegram_sent_file) ? (json_decode(@file_get_contents($telegram_sent_file), true) ?: []) : [];
                                if (!isset($sent['es'])) { $sent['es'] = []; }
                                $key = $link ?: $guid;
                                if ($key !== '') { $sent['es'][$key] = true; }
                                @file_put_contents($telegram_sent_file, json_encode($sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                if (!$resp['ok'] && empty($message)) {
                                    $message = 'Aprobado, pero error al enviar a Telegram: ' . htmlspecialchars($resp['error'] ?? '');
                                    $message_type = 'error';
                                }
                            }
                        }
                    }
                }

                // Archive post
                $item_data = [
                    'title' => $finalTitle,
                    'link' => $finalLink,
                    'description' => $descPlain,
                    'image' => ''
                ];
                archive_post($item_data);
            }

            $published[] = ['title' => $finalTitle, 'text' => $descPlain, 'timestamp' => date('c'), 'link' => $finalLink];
        }
        $examples[] = [ 'title' => $finalTitle, 'link'  => $finalLink, 'decision' => $decision, 'resumen_original' => $suggestion['summary'], 'resumen_final'    => $descPlain ];
        $sent_titles[] = $finalTitle;
        $sent_titlekeys[] = title_key($finalTitle);
        array_splice($sugerencias, $suggestion_index, 1);
        file_put_contents($sugerencias_file, json_encode($sugerencias, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($examples_file,     json_encode($examples,     JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($published_file,    json_encode($published,    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($cache_titles_file, json_encode(array_values(array_unique($sent_titles)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        write_titlekeys($titlekeys_file, $sent_titlekeys);
        $message = "Acción '" . htmlspecialchars($_POST['action']) . "' realizada con éxito.";
        $message_type = 'success';
    }
}
    if (isset($_POST['save_prompt'])) { file_put_contents($prompt_file, $_POST['prompt_text']); $message = "Prompt guardado con éxito."; $message_type = 'success'; }
    if (isset($_POST['save_sources'])) { $sources = []; if (isset($_POST['source_name'])) { for ($i = 0; $i < count($_POST['source_name']); $i++) { if (!empty($_POST['source_name'][$i]) && !empty($_POST['source_url'][$i])) { $sources[] = ['name' => $_POST['source_name'][$i], 'url' => $_POST['source_url'][$i]]; } } } file_put_contents($sources_file, json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); $message = "Fuentes guardadas con éxito."; $message_type = 'success'; }

    // --- OPML IMPORT ---
    if (isset($_POST['import_opml'])) {
        $active_tab = 'sources';
        if (isset($_FILES['opml_file']) && $_FILES['opml_file']['error'] === UPLOAD_ERR_OK) {
            $opml_content = file_get_contents($_FILES['opml_file']['tmp_name']);
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($opml_content);

            if ($xml) {
                $existing_sources = telex_fetch_feed_sources($sources_file);
                $existing_urls = array_column($existing_sources, 'url');
                $new_sources_count = 0;

                foreach ($xml->body->outline as $outline) {
                    $url = (string)$outline['xmlUrl'];
                    $name = (string)$outline['text'];
                    if ($url && $name && !in_array($url, $existing_urls)) {
                        $existing_sources[] = ['name' => $name, 'url' => $url];
                        $existing_urls[] = $url;
                        $new_sources_count++;
                    }
                }

                if ($new_sources_count > 0) {
                    file_put_contents($sources_file, json_encode($existing_sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $message = "Se importaron $new_sources_count nuevas fuentes desde el archivo OPML.";
                    $message_type = 'success';
                } else {
                    $message = "No se encontraron nuevas fuentes en el archivo OPML.";
                    $message_type = 'info';
                }
            } else {
                $message = "Error al procesar el archivo OPML. Asegúrate de que es un archivo válido.";
                $message_type = 'error';
            }
        } else {
            $message = "Error al subir el archivo OPML.";
            $message_type = 'error';
        }
    }
    // --- END OPML IMPORT ---
    if (isset($_POST['save_rss'])) { if (file_exists($rss_file) && is_writable($rss_file)) { libxml_use_internal_errors(true); $xml = simplexml_load_file($rss_file); if ($xml && isset($xml->channel->item)) { for ($i = 0; $i < count($xml->channel->item); $i++) { if (isset($_POST['rss_title'][$i])) { $xml->channel->item[$i]->title = $_POST['rss_title'][$i]; $xml->channel->item[$i]->description = $_POST['rss_description'][$i]; $xml->channel->item[$i]->link = $_POST['rss_url'][$i]; } } $dom = new DOMDocument('1.0'); $dom->preserveWhiteSpace = false; $dom->formatOutput = true; $dom->loadXML($xml->asXML()); $dom->save($rss_file); $message = "Fichero rss.xml guardado con éxito."; $message_type = 'success'; } else { $message = "Error: El fichero rss.xml está mal formado."; $message_type = 'error'; } } else { $message = "Error: No se encontró o no se puede escribir en rss.xml."; $message_type = 'error'; } }
    // Eliminar seleccionados de RSS
    if (isset($_POST['delete_rss_selected'])) {
        $active_tab = 'rss';
        $toDelete = array_map('intval', $_POST['rss_delete'] ?? []);
        if (file_exists($rss_file) && is_writable($rss_file)) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($rss_file);
            if ($xml && isset($xml->channel)) {
                $new = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
                foreach ($xml->channel->children() as $child) {
                    if ($child->getName() !== 'item') { $new->channel->addChild($child->getName(), (string)$child); }
                }
                $idx = 0;
                foreach ($xml->channel->item as $it) {
                    if (in_array($idx, $toDelete, true)) { $idx++; continue; }
                    $ni = $new->channel->addChild('item');
                    // Básicos
                    if (isset($it->title)) $ni->addChild('title', (string)$it->title);
                    if (isset($it->link)) $ni->addChild('link', (string)$it->link);
                    if (isset($it->guid)) $ni->addChild('guid', (string)$it->guid);
                    if (isset($it->pubDate)) $ni->addChild('pubDate', (string)$it->pubDate);
                    // description CDATA
                    if (isset($it->description)) {
                        $d = $ni->addChild('description');
                        $d[0] = null; $dd = dom_import_simplexml($d); if ($dd) { $dd->appendChild($dd->ownerDocument->createCDATASection((string)$it->description)); }
                    }
                    // content:encoded CDATA
                    $content = $it->children('http://purl.org/rss/1.0/modules/content/');
                    if (isset($content->encoded)) {
                        $c = $ni->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/');
                        $cd = dom_import_simplexml($c); if ($cd) { $cd->appendChild($cd->ownerDocument->createCDATASection((string)$content->encoded)); }
                    }
                    // enclosure
                    if (isset($it->enclosure)) {
                        $enc = $ni->addChild('enclosure');
                        foreach ($it->enclosure->attributes() as $k => $v) { $enc->addAttribute($k, (string)$v); }
                    }
                    $idx++;
                }
                $dom = new DOMDocument('1.0'); $dom->preserveWhiteSpace = false; $dom->formatOutput = true; $dom->loadXML($new->asXML()); $dom->save($rss_file);
                $message = 'Entradas seleccionadas eliminadas de rss.xml.'; $message_type = 'success';
            } else { $message = 'rss.xml mal formado.'; $message_type = 'error'; }
        } else { $message = 'No se puede escribir en rss.xml.'; $message_type = 'error'; }
    }
    // Eliminar todas de RSS
    if (isset($_POST['delete_rss_all'])) {
        $active_tab = 'rss';
        if (file_exists($rss_file) && is_writable($rss_file)) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($rss_file);
            if ($xml && isset($xml->channel)) {
                $new = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
                foreach ($xml->channel->children() as $child) { if ($child->getName() !== 'item') { $new->channel->addChild($child->getName(), (string)$child); } }
                $dom = new DOMDocument('1.0'); $dom->preserveWhiteSpace = false; $dom->formatOutput = true; $dom->loadXML($new->asXML());
                if (safe_dom_save($dom, $rss_file)) {
                    $message = 'Todas las entradas eliminadas de rss.xml.'; $message_type = 'success';
                } else {
                    $message = 'No se pudo guardar rss.xml (vacío).'; $message_type = 'error';
                }
            } else { $message = 'rss.xml mal formado.'; $message_type = 'error'; }
        } else { $message = 'No se puede escribir en rss.xml.'; $message_type = 'error'; }
    }

    if (isset($_POST['save_rss_en'])) {
        libxml_use_internal_errors(true);
        $xml = null;
        if (file_exists($rss_en_file)) {
            $xml = simplexml_load_file($rss_en_file);
        } else {
            // Crear esqueleto si no existe
            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
            $xml->channel->addChild('title', 'Maximalismo — Noticias (' . $translated_lang_name . ')');
            $xml->channel->addChild('link', 'https://maximalismo.org/feed/');
            $xml->channel->addChild('description', 'Traducción al ' . $translated_lang_name . ' del feed de noticias de Maximalismo.');
            $xml->channel->addChild('language', $target_lang);
            $xml->channel->addChild('pubDate', date(DATE_RSS));
        }
        if ($xml && isset($xml->channel)) {
            if (isset($xml->channel->item)) {
                for ($i = 0; $i < count($xml->channel->item); $i++) {
                    if (isset($_POST['rss_en_title'][$i])) {
                        $xml->channel->item[$i]->title = $_POST['rss_en_title'][$i];
                        $xml->channel->item[$i]->description = $_POST['rss_en_description'][$i];
                        $xml->channel->item[$i]->link = $_POST['rss_en_url'][$i];
                    }
                }
            }
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            $dom->loadXML($xml->asXML());
            if (safe_dom_save($dom, $rss_en_file)) {
                $message = "Fichero rss_" . htmlspecialchars($target_lang) . ".xml guardado con éxito.";
                $message_type = 'success';
            } else {
                $message = "No se pudo guardar rss_" . htmlspecialchars($target_lang) . ".xml.";
                $message_type = 'error';
            }
        } else {
            $message = "Error: El fichero de traducción está mal formado.";
            $message_type = 'error';
        }
    }
    // Eliminar seleccionados de RSS_EN
    if (isset($_POST['delete_rss_en_selected'])) {
        $active_tab = 'traduccion';
        $toDelete = array_map('intval', $_POST['rss_en_delete'] ?? []);
        if (file_exists($rss_en_file) || is_writable(dirname($rss_en_file))) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($rss_en_file);
            if ($xml && isset($xml->channel)) {
                $new = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
                foreach ($xml->channel->children() as $child) { if ($child->getName() !== 'item') { $new->channel->addChild($child->getName(), (string)$child); } }
                $idx = 0;
                foreach ($xml->channel->item as $it) {
                    if (in_array($idx, $toDelete, true)) { $idx++; continue; }
                    $ni = $new->channel->addChild('item');
                    if (isset($it->title)) $ni->addChild('title', (string)$it->title);
                    if (isset($it->link)) $ni->addChild('link', (string)$it->link);
                    if (isset($it->guid)) $ni->addChild('guid', (string)$it->guid);
                    if (isset($it->pubDate)) $ni->addChild('pubDate', (string)$it->pubDate);
                    if (isset($it->description)) { $d = $ni->addChild('description'); $d[0] = null; $dd = dom_import_simplexml($d); if ($dd) { $dd->appendChild($dd->ownerDocument->createCDATASection((string)$it->description)); } }
                    $content = $it->children('http://purl.org/rss/1.0/modules/content/');
                    if (isset($content->encoded)) { $c = $ni->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/'); $cd = dom_import_simplexml($c); if ($cd) { $cd->appendChild($cd->ownerDocument->createCDATASection((string)$content->encoded)); } }
                    if (isset($it->enclosure)) { $enc = $ni->addChild('enclosure'); foreach ($it->enclosure->attributes() as $k => $v) { $enc->addAttribute($k, (string)$v); } }
                    $idx++;
                }
                $dom = new DOMDocument('1.0'); $dom->preserveWhiteSpace = false; $dom->formatOutput = true; $dom->loadXML($new->asXML());
                if (safe_dom_save($dom, $rss_en_file)) {
                    $message = 'Entradas seleccionadas eliminadas de rss_en.xml.'; $message_type = 'success';
                } else {
                    $message = 'No se puede escribir en rss_en.xml.'; $message_type = 'error';
                }
            } else { $message = 'rss_en.xml mal formado.'; $message_type = 'error'; }
        } else { $message = 'No se puede escribir en rss_en.xml.'; $message_type = 'error'; }
    }
    // Eliminar todas de RSS_EN
    if (isset($_POST['delete_rss_en_all'])) {
        $active_tab = 'traduccion';
        if (file_exists($rss_en_file) || is_writable(dirname($rss_en_file))) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($rss_en_file);
            if ($xml && isset($xml->channel)) {
                $new = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
                foreach ($xml->channel->children() as $child) { if ($child->getName() !== 'item') { $new->channel->addChild($child->getName(), (string)$child); } }
                $dom = new DOMDocument('1.0'); $dom->preserveWhiteSpace = false; $dom->formatOutput = true; $dom->loadXML($new->asXML());
                if (safe_dom_save($dom, $rss_en_file)) {
                    $message = 'Todas las entradas eliminadas de rss_en.xml.'; $message_type = 'success';
                } else {
                    $message = 'No se puede escribir en rss_en.xml.'; $message_type = 'error';
                }
            } else { $message = 'rss_en.xml mal formado.'; $message_type = 'error'; }
        } else { $message = 'No se puede escribir en rss_en.xml.'; $message_type = 'error'; }
    }


    // Enviar TODOS los pendientes de un idioma a su canal de Telegram
    if (isset($_POST['telegram_send_all'])) {
        $active_tab = 'telegram';
        $lang = strtolower(trim($_POST['lang'] ?? ''));
        $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
        $bot  = $bots[$lang] ?? null;
        if (!$bot || (is_array($bot) && (empty($bot['token']) || empty($bot['chat_id'])))) {
            $message = 'Falta token o chat ID para el idioma ' . htmlspecialchars($lang) . '.'; $message_type = 'error';
        } else {
            $token = is_array($bot) ? $bot['token'] : (string)$bot;
            $chat  = is_array($bot) ? ($bot['chat_id'] ?? '') : '';
            $feed_file = __DIR__ . '/' . feed_filename_for_lang($lang, $feed_custom);
            if (!file_exists($feed_file)) {
                $message = 'No existe la feed para el idioma ' . htmlspecialchars($lang) . '.'; $message_type = 'error';
            } else {
                // Cargar enviados y priming inicial si no existe
                $sent = file_exists($telegram_sent_file) ? (json_decode(@file_get_contents($telegram_sent_file), true) ?: []) : [];
                if (!isset($sent[$lang])) {
                    // Marcar existentes como enviados la primera vez
                    $sent[$lang] = [];
                    libxml_use_internal_errors(true);
                    $xml = simplexml_load_file($feed_file);
                    if ($xml && isset($xml->channel->item)) {
                        foreach ($xml->channel->item as $it) {
                            $key = (string)($it->link ?? '');
                            if ($key === '') { $key = (string)($it->guid ?? ''); }
                            if ($key !== '') { $sent[$lang][$key] = true; }
                        }
                    }
                    @file_put_contents($telegram_sent_file, json_encode($sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $message = 'Inicializado Telegram para ' . htmlspecialchars($lang) . '. No hay pendientes.'; $message_type = 'success';
                } else {
                    // Enviar los no enviados
                    libxml_use_internal_errors(true);
                    $xml = simplexml_load_file($feed_file);
                    $okCount = 0; $errCount = 0;
                    if ($xml && isset($xml->channel->item)) {
                        foreach ($xml->channel->item as $it) {
                            $parts = rss_item_parts($it);
                            $key = $parts['url'];
                            if ($key === '') continue;
                            if (!empty($sent[$lang][$key])) continue; // ya enviado
                            $resp = tg_send($token, $chat, $parts['title'], $parts['desc'], $parts['url'], $parts['image']);
                            if ($resp['ok']) { $sent[$lang][$key] = true; $okCount++; }
                            else { $errCount++; }
                        }
                        @file_put_contents($telegram_sent_file, json_encode($sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        $message = 'Telegram ' . htmlspecialchars($lang) . ': ' . $okCount . ' enviados, ' . $errCount . ' errores.'; $message_type = $errCount? 'error':'success';
                    } else { $message = 'Feed mal formada para ' . htmlspecialchars($lang) . '.'; $message_type = 'error'; }
                }
            }
        }
    }

    if (isset($_POST['telegram_forget_all'])) {
        $active_tab = 'telegram';
        $lang = strtolower(trim($_POST['lang'] ?? ''));
        if ($lang === '') {
            $message = 'No se indicó el idioma para olvidar los items.'; $message_type = 'error';
        } else {
            $feed_file = __DIR__ . '/' . feed_filename_for_lang($lang, $feed_custom);
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($feed_file);
            if ($xml && isset($xml->channel->item)) {
                $forgotten = file_exists($data_dir . '/telegram_forgotten.json') ? (json_decode(@file_get_contents($data_dir . '/telegram_forgotten.json'), true) ?: []) : [];
                if (!isset($forgotten[$lang])) { $forgotten[$lang] = []; }

                $sent = file_exists($telegram_sent_file) ? (json_decode(@file_get_contents($telegram_sent_file), true) ?: []) : [];
                if (!isset($sent[$lang])) { $sent[$lang] = []; }

                $forgottenCount = 0;
                foreach ($xml->channel->item as $it) {
                    $parts = rss_item_parts($it);
                    $key = $parts['url'];
                    if ($key === '') continue;
                    if (!empty($sent[$lang][$key])) continue; // ya enviado
                    if (!empty($forgotten[$lang][$key])) continue; // ya olvidado

                    $forgotten[$lang][$key] = true;
                    $forgottenCount++;
                }
                @file_put_contents($data_dir . '/telegram_forgotten.json', json_encode($forgotten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $message = 'Telegram ' . htmlspecialchars($lang) . ': ' . $forgottenCount . ' items olvidados.'; $message_type = 'success';
            } else {
                $message = 'Feed mal formada para ' . htmlspecialchars($lang) . '.'; $message_type = 'error';
            }
        }
    }

    // Enviar un item individual a Telegram (forzar)
    if (isset($_POST['telegram_send_item'])) {
        $idx  = (isset($_POST['telegram_send_item']) && $_POST['telegram_send_item'] !== '') ? intval($_POST['telegram_send_item']) : (isset($_POST['idx']) ? intval($_POST['idx']) : -1);
        $lang = strtolower(trim($_POST['lang'] ?? ''));
        if ($lang === '' && isset($_POST['rss_item_lang']) && is_array($_POST['rss_item_lang']) && isset($_POST['rss_item_lang'][$idx])) {
            $lang = strtolower((string)$_POST['rss_item_lang'][$idx]);
        }
        if ($lang === '' && isset($_POST['rss_en_item_lang']) && is_array($_POST['rss_en_item_lang']) && isset($_POST['rss_en_item_lang'][$idx])) {
            $lang = strtolower((string)$_POST['rss_en_item_lang'][$idx]);
        }
        $from_tab = $_POST['from_tab'] ?? '';
        if ($from_tab === '' && isset($_POST['rss_item_tab']) && is_array($_POST['rss_item_tab']) && isset($_POST['rss_item_tab'][$idx])) {
            $from_tab = (string)$_POST['rss_item_tab'][$idx];
        }
        if ($from_tab === '' && isset($_POST['rss_en_item_tab']) && is_array($_POST['rss_en_item_tab']) && isset($_POST['rss_en_item_tab'][$idx])) {
            $from_tab = (string)$_POST['rss_en_item_tab'][$idx];
        }
        if ($from_tab === '') { $from_tab = 'telegram'; }
        $active_tab = $from_tab;
        $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
        if ($lang === '') {
            $message = 'No se indicó el idioma para enviar a Telegram.'; $message_type = 'error';
        } else {
        $bot  = $bots[$lang] ?? null;
        if (!$bot || (is_array($bot) && (empty($bot['token']) || empty($bot['chat_id'])))) {
            $message = 'Falta token o chat ID para el idioma ' . htmlspecialchars($lang) . '.'; $message_type = 'error';
        } else {
            $token = is_array($bot) ? $bot['token'] : (string)$bot;
            $chat  = is_array($bot) ? ($bot['chat_id'] ?? '') : '';
            $feed_file = ($lang === 'es') ? $rss_file : (__DIR__ . '/' . feed_filename_for_lang($lang, $feed_custom));
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($feed_file);
            if ($xml && isset($xml->channel->item[$idx])) {
                $it = $xml->channel->item[$idx];
                $parts = rss_item_parts($it);
                $key   = $parts['url'];
                $resp = tg_send($token, $chat, $parts['title'], $parts['desc'], $parts['url'], $parts['image']);
                // Registrar como enviado, aunque sea forzado
                $sent = file_exists($telegram_sent_file) ? (json_decode(@file_get_contents($telegram_sent_file), true) ?: []) : [];
                if (!isset($sent[$lang])) { $sent[$lang] = []; }
                if ($key !== '') { $sent[$lang][$key] = true; }
                @file_put_contents($telegram_sent_file, json_encode($sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                if ($resp['ok']) { $message = 'Entrada enviada a Telegram (' . htmlspecialchars($lang) . ').'; $message_type = 'success'; }
                else { $message = 'Error al enviar a Telegram: ' . htmlspecialchars($resp['error']); $message_type = 'error'; }
            } else { $message = 'No se encontró el item solicitado.'; $message_type = 'error'; }
        }
        }
    }

    if (isset($_POST['telegram_forget_item'])) {
        $idx  = isset($_POST['idx']) ? intval($_POST['idx']) : (isset($_POST['telegram_forget_item']) ? intval($_POST['telegram_forget_item']) : -1);
        $lang = strtolower(trim($_POST['lang'] ?? ''));
        if ($lang === '' && isset($_POST['rss_item_lang']) && is_array($_POST['rss_item_lang']) && isset($_POST['rss_item_lang'][$idx])) {
            $lang = strtolower((string)$_POST['rss_item_lang'][$idx]);
        }
        if ($lang === '' && isset($_POST['rss_en_item_lang']) && is_array($_POST['rss_en_item_lang']) && isset($_POST['rss_en_item_lang'][$idx])) {
            $lang = strtolower((string)$_POST['rss_en_item_lang'][$idx]);
        }
        $from_tab = $_POST['from_tab'] ?? '';
        if ($from_tab === '' && isset($_POST['rss_item_tab']) && is_array($_POST['rss_item_tab']) && isset($_POST['rss_item_tab'][$idx])) {
            $from_tab = (string)$_POST['rss_item_tab'][$idx];
        }
        if ($from_tab === '' && isset($_POST['rss_en_item_tab']) && is_array($_POST['rss_en_item_tab']) && isset($_POST['rss_en_item_tab'][$idx])) {
            $from_tab = (string)$_POST['rss_en_item_tab'][$idx];
        }
        if ($from_tab === '') { $from_tab = 'telegram'; }
        $active_tab = $from_tab;

        if ($lang === '') {
            $message = 'No se indicó el idioma para olvidar el item.'; $message_type = 'error';
        } else {
            $feed_file = ($lang === 'es') ? $rss_file : (__DIR__ . '/' . feed_filename_for_lang($lang, $feed_custom));
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($feed_file);
            if ($xml && isset($xml->channel->item[$idx])) {
                $it = $xml->channel->item[$idx];
                $parts = rss_item_parts($it);
                $key   = $parts['url'];

                $forgotten = file_exists($data_dir . '/telegram_forgotten.json') ? (json_decode(@file_get_contents($data_dir . '/telegram_forgotten.json'), true) ?: []) : [];
                if (!isset($forgotten[$lang])) { $forgotten[$lang] = []; }
                if ($key !== '') { $forgotten[$lang][$key] = true; }
                @file_put_contents($data_dir . '/telegram_forgotten.json', json_encode($forgotten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $message = 'Entrada marcada como olvidada (' . htmlspecialchars($lang) . ').'; $message_type = 'success';
            } else {
                $message = 'No se encontró el item solicitado para olvidar.'; $message_type = 'error';
            }
        }
    }
    // Añadir entrada manual (otras fuentes)
    if (isset($_POST['add_manual_item'])) {
        $active_tab = 'gemini';
        $title = trim($_POST['manual_title'] ?? '');
        $link  = trim($_POST['manual_link'] ?? '');
        $desc  = trim($_POST['manual_description'] ?? '');
        $imgUrlInput = trim($_POST['manual_image_url'] ?? '');
        $finalImageUrl = '';

        // Subida de imagen (opcional)
        if (!empty($_FILES['manual_image']['name']) && is_uploaded_file($_FILES['manual_image']['tmp_name'])) {
            $fname = basename($_FILES['manual_image']['name']);
            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $safe = preg_replace('/[^a-zA-Z0-9._-]/u','_', $fname);
                $uniq = uniqid('img_', true) . '.' . $ext;
                $dest = $img_dir . '/' . $uniq;
                if (move_uploaded_file($_FILES['manual_image']['tmp_name'], $dest)) {
                    $imagePathRel = 'img/' . $uniq;
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'];
                    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                    $finalImageUrl = $protocol . '://' . $host . $path . '/' . $imagePathRel;
                } else {
                    $message = 'Error al mover el archivo subido. Comprueba permisos de la carpeta img/.';
                    $message_type = 'error';
                    goto after_manual_item;
                }
            } else {
                $message = 'Tipo de archivo de imagen no permitido.';
                $message_type = 'error';
                goto after_manual_item;
            }
        }
        if ($finalImageUrl === '' && $imgUrlInput !== '') {
            $finalImageUrl = $imgUrlInput; // URL externa o ruta ya servida
        }

        if (file_exists($rss_file) && !is_writable($rss_file)) {
            $message = 'No se puede escribir en rss.xml.';
            $message_type = 'error';
            goto after_manual_item;
        }

        // Cargar o crear rss.xml
        libxml_use_internal_errors(true);
        if (file_exists($rss_file)) {
            $rss = simplexml_load_file($rss_file);
        } else {
            $rss = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
            $rss->channel->addChild('title', 'Maximalismo — Noticias (ES)');
            $rss->channel->addChild('link', 'https://maximalismo.org/feed/');
            $rss->channel->addChild('description', 'Noticias seleccionadas y editadas desde Telex.');
            $rss->channel->addChild('language', 'es');
            $rss->channel->addChild('pubDate', date(DATE_RSS));
        }

        if (!$rss || !isset($rss->channel)) {
            $message = 'rss.xml mal formado, no se pudo cargar.';
            $message_type = 'error';
            goto after_manual_item;
        }

        // Evitar duplicados por link/guid
        $existingKeys = [];
        if (isset($rss->channel->item)) {
            foreach ($rss->channel->item as $it) {
                $linkKey = trim(html_entity_decode((string)($it->link ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                $guidKey = trim(html_entity_decode((string)($it->guid ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                $ek = $linkKey !== '' ? $linkKey : $guidKey;
                if ($ek !== '') $existingKeys[$ek] = true;
            }
        }

        if ($title !== '') {
            $desc_raw = trim($_POST['manual_description'] ?? '');
            $desc_html = telex_format_description_to_html($desc_raw);
            $desc_plain = telex_description_plain($desc_raw, $title);

            $full_html = '';
            if ($finalImageUrl !== '') {
                $safeImg = htmlspecialchars($finalImageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $full_html .= '<p><img src="' . $safeImg . '" alt="" style="max-width:100%; height:auto;" /></p>';
            }
            $full_html .= $desc_html;

            $guid = $link !== '' ? $link : ('telex:' . md5($title . '|' . $desc_plain . '|' . microtime(true)));
            $keyToCheck = trim(html_entity_decode($link !== '' ? $link : $guid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if (!isset($existingKeys[$keyToCheck])) {
                $item = $rss->channel->addChild('item');
                $item->addChild('title', htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                if ($link) { $item->addChild('link', htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); }
                $item->addChild('guid', htmlspecialchars($guid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

                $descNode = $item->addChild('description');
                $descNode[0] = null;
                $descDom = dom_import_simplexml($descNode);
                if ($descDom) {
                    $descDom->appendChild($descDom->ownerDocument->createCDATASection($full_html));
                }

                $contentNode = $item->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/');
                if ($contentNode) {
                    $contentDom = dom_import_simplexml($contentNode);
                    if ($contentDom) {
                        $contentDom->appendChild($contentDom->ownerDocument->createCDATASection($full_html));
                    }
                }

                // Enclosure (opcional) si la imagen es local o URL directa a imagen
                if ($finalImageUrl !== '') {
                    $ext = strtolower(pathinfo(parse_url($finalImageUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                    $mime = 'image/jpeg';
                    if (in_array($ext, ['png'])) $mime = 'image/png';
                    elseif (in_array($ext, ['gif'])) $mime = 'image/gif';
                    elseif (in_array($ext, ['webp'])) $mime = 'image/webp';
                    $enclosure = $item->addChild('enclosure');
                    $enclosure->addAttribute('url', $finalImageUrl);
                    $enclosure->addAttribute('type', $mime);
                }

                $item->addChild('pubDate', date(DATE_RSS));

                // Guardar rss.xml ordenado y sin duplicados
                if (!telex_save_rss_document($rss, $rss_file)) {
                    $message = 'No se pudo guardar rss.xml tras añadir la entrada.';
                    $message_type = 'error';
                    goto after_manual_item;
                }

                // Archive post
                $item_data = [
                    'title' => $title,
                    'link' => $link,
                    'description' => $descPlain,
                    'image' => $finalImageUrl
                ];
                archive_post($item_data);

                $message = 'Entrada añadida a rss.xml.';
                $message_type = 'success';

                // Envío automático a Telegram (ES) si está activado y hay bot/chat configurados
                $auto_enabled = telex_flag_enabled(telex_config_get($config, ['telegram', 'auto_send', 'es'], true));
                if ($auto_enabled) {
                    $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
                    $bot  = $bots['es'] ?? null;
                    if ($bot && (!is_array($bot) || (!empty($bot['token']) && !empty($bot['chat_id'])))) {
                        $token = is_array($bot) ? $bot['token'] : (string)$bot;
                        $chat  = is_array($bot) ? ($bot['chat_id'] ?? '') : '';
                        if ($token && $chat) {
                            $resp = tg_send($token, $chat, $title, $descPlain, ($link ?? ''), ($finalImageUrl ?? ''));
                            // Registrar como enviado
                            $sent = file_exists($telegram_sent_file) ? (json_decode(@file_get_contents($telegram_sent_file), true) ?: []) : [];
                            if (!isset($sent['es'])) { $sent['es'] = []; }
                            $key = ($link ?? '') !== '' ? $link : (string)$guid;
                            if ($key !== '') { $sent['es'][$key] = true; }
                            @file_put_contents($telegram_sent_file, json_encode($sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            if (!$resp['ok']) {
                                $message = 'Añadida, pero error al enviar a Telegram: ' . htmlspecialchars($resp['error'] ?? '');
                                $message_type = 'error';
                            }
                        }
                    }
                }
            } else {
                $message = 'Entrada duplicada (link/guid ya existente).';
                $message_type = 'error';
            }
        } else {
            $message = 'Falta el título.';
            $message_type = 'error';
        }

        after_manual_item:
        ;
    }



    // Guardar configuración centralizada
    if (isset($_POST['save_config'])) {
        $geminiKey = trim((string)($_POST['gemini_api_key'] ?? ''));
        $geminiModel = trim((string)($_POST['gemini_model'] ?? 'gemini-1.5-flash-latest'));
        $translateKey = trim((string)($_POST['google_translate_api_key'] ?? ''));
        $autoSendEs = isset($_POST['telegram_auto_send_es']);
        $translatorLang = strtolower(trim((string)($_POST['translator_lang'] ?? '')));

        telex_config_set($config, ['apis', 'gemini', 'api_key'], $geminiKey);
        telex_config_set($config, ['apis', 'gemini', 'model'], $geminiModel !== '' ? $geminiModel : 'gemini-1.5-flash-latest');
        telex_config_set($config, ['apis', 'google_translate', 'api_key'], $translateKey);
        telex_config_set($config, ['telegram', 'auto_send', 'es'], $autoSendEs);

        if ($translatorLang !== '') {
            telex_config_set($config, ['translator', 'target_language'], $translatorLang);
            $target_lang = $translatorLang;
            $translated_lang_name = lang_name_es($target_lang);
            $translations = $config['feeds']['translations'] ?? [];
            $translations[$target_lang] = feed_filename_for_lang($target_lang, $feed_custom);
            telex_config_set($config, ['feeds', 'translations'], $translations);
            $rss_en_file = __DIR__ . '/' . $translations[$target_lang];
        }

        if (isset($_POST['translator_interval_ms'])) {
            $interval = max(1000, (int)$_POST['translator_interval_ms']);
            telex_config_set($config, ['translator', 'interval_ms'], $interval);
        }

        if (telex_save_config($config)) {
            $message = 'Configuración guardada.';
            $message_type = 'success';
        } else {
            $message = 'No se pudo guardar la configuración.';
            $message_type = 'error';
        }
    }

    // Gestionar bots de Telegram por idioma
    if (isset($_POST['add_telegram_bot'])) {
        $active_tab = 'config';
        $lang  = strtolower(trim($_POST['telegram_lang'] ?? ''));
        $token = trim($_POST['telegram_token'] ?? '');
        $chat  = trim($_POST['telegram_chatid'] ?? '');

        if ($lang === '') {
            $message = 'Selecciona un idioma para el bot de Telegram.';
            $message_type = 'error';
        } else if ($token === '') {
            $message = 'Introduce el token del bot de Telegram.';
            $message_type = 'error';
        } else if ($chat === '') {
            $message = 'Introduce el Chat ID del bot de Telegram.';
            $message_type = 'error';
        } else if (!preg_match('/^@[A-Za-z0-9_]{3,}$/', $chat) && !preg_match('/^-?\d{5,}$/', $chat)) {
            $message = 'Chat ID no válido. Usa @canal o un ID numérico.';
            $message_type = 'error';
        } else {
            $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
            // Un bot por idioma: sustituye si ya existe. Estructura: { token, chat_id }
            $bots[$lang] = [
                'token'   => $token,
                'chat_id' => $chat,
            ];
            $tmp = $telegram_tokens_file . '.tmp';
            @file_put_contents($tmp, json_encode($bots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            @rename($tmp, $telegram_tokens_file);
            $message = 'Bot de Telegram guardado para ' . htmlspecialchars(lang_name_es($lang)) . ' (' . htmlspecialchars($lang) . ').';
            $message_type = 'success';
        }
    }
    if (isset($_POST['remove_telegram_bot'])) {
        $active_tab = 'config';
        $lang = strtolower(trim($_POST['remove_lang'] ?? ''));
        $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
        if ($lang === '' || !isset($bots[$lang])) {
            $message = 'No se encontró el bot a eliminar.'; $message_type = 'error';
        } else {
            unset($bots[$lang]);
            $tmp = $telegram_tokens_file . '.tmp';
            @file_put_contents($tmp, json_encode($bots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            @rename($tmp, $telegram_tokens_file);
            $message = 'Bot de Telegram eliminado para ' . htmlspecialchars($lang) . '.'; $message_type = 'success';
        }
    }
    if (isset($_POST['update_telegram_chat'])) {
        $active_tab = 'config';
        $lang = strtolower(trim($_POST['lang'] ?? ''));
        $chat = trim($_POST['telegram_chatid'] ?? '');
        $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
        if ($lang === '' || !isset($bots[$lang])) {
            $message = 'No se encontró el bot para actualizar.'; $message_type = 'error';
        } else if ($chat === '') {
            $message = 'Introduce el Chat ID del bot de Telegram.'; $message_type = 'error';
        } else if (!preg_match('/^@[A-Za-z0-9_]{3,}$/', $chat) && !preg_match('/^-?\d{5,}$/', $chat)) {
            $message = 'Chat ID no válido. Usa @canal o un ID numérico.'; $message_type = 'error';
        } else {
            // Asegurar estructura
            if (is_string($bots[$lang])) { $bots[$lang] = [ 'token' => $bots[$lang], 'chat_id' => '' ]; }
            $bots[$lang]['chat_id'] = $chat;
            $tmp = $telegram_tokens_file . '.tmp';
            @file_put_contents($tmp, json_encode($bots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            @rename($tmp, $telegram_tokens_file);
            $message = 'Chat ID actualizado para ' . htmlspecialchars($lang) . '.'; $message_type = 'success';
        }
    }

    // Probar Gemini (usa configuración guardada o lo enviado en el formulario principal si existe)
    if (isset($_POST['test_gemini'])) {
        $active_tab = 'config';
        $test_key   = $_POST['gemini_api_key'] ?? telex_config_get($config, ['apis', 'gemini', 'api_key'], '');
        $test_model = $_POST['gemini_model']   ?? telex_config_get($config, ['apis', 'gemini', 'model'], 'gemini-1.5-flash-latest');
        if (!$test_key) {
            $message = "Falta GEMINI_API_KEY.";
            $message_type = 'error';
        } else {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($test_model) . ":generateContent?key=" . rawurlencode($test_key);
            $payload = json_encode([ 'contents' => [ [ 'parts' => [ [ 'text' => 'ping' ] ] ] ] ], JSON_UNESCAPED_SLASHES);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [ 'Content-Type: application/json' ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($err) {
                $message = "Error de conexión a Gemini: " . htmlspecialchars($err);
                $message_type = 'error';
            } else if ($http < 200 || $http >= 300) {
                $message = "Gemini respondió HTTP $http. Verifica la clave/modelo.";
                $message_type = 'error';
            } else {
                $j = json_decode($resp, true);
                $ok = !empty($j['candidates'][0]['content']['parts'][0]['text']);
                if ($ok) { $message = "Gemini OK con el modelo " . htmlspecialchars($test_model) . "."; $message_type = 'success'; }
                else { $message = "Respuesta de Gemini sin contenido. Revisa permisos del proyecto/API."; $message_type = 'error'; }
            }
        }
    }

    // Probar Google Translate (usa configuración guardada o enviada)
    if (isset($_POST['test_translate'])) {
        $active_tab = 'config';
        $tr_key = $_POST['google_translate_api_key'] ?? telex_config_get($config, ['apis', 'google_translate', 'api_key'], '');
        if (!$tr_key) {
            $message = "Falta GOOGLE_TRANSLATE_API_KEY.";
            $message_type = 'error';
        } else {
            $url = "https://translation.googleapis.com/language/translate/v2?key=" . rawurlencode($tr_key) . "&q=" . rawurlencode('hola') . "&source=es&target=en&format=text";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($err) {
                $message = "Error de conexión a Translate: " . htmlspecialchars($err);
                $message_type = 'error';
            } else if ($http < 200 || $http >= 300) {
                $message = "Translate respondió HTTP $http. Verifica la clave.";
                $message_type = 'error';
            } else {
                $j = json_decode($resp, true);
                $ok = !empty($j['data']['translations'][0]['translatedText']);
                if ($ok) { $message = "Google Translate OK."; $message_type = 'success'; }
                else { $message = "Respuesta de Translate sin traducción."; $message_type = 'error'; }
            }
        }
    }

    // Cambiar idioma de traducción desde pestaña Traducción
    if (isset($_POST['set_translator_lang'])) {
        $active_tab = 'traduccion';
        $new_lang = strtolower(trim($_POST['translator_lang'] ?? ''));
        if ($new_lang === '') {
            $message = 'Selecciona un idioma válido.'; $message_type = 'error';
        } else {
            telex_config_set($config, ['translator', 'target_language'], $new_lang);
            $translations = $config['feeds']['translations'] ?? [];
            $translations[$new_lang] = feed_filename_for_lang($new_lang, $feed_custom);
            telex_config_set($config, ['feeds', 'translations'], $translations);
            if (telex_save_config($config)) {
                $target_lang = $new_lang;
                $translated_lang_name = lang_name_es($target_lang);
                $rss_en_file = __DIR__ . '/' . $translations[$target_lang];
                $message = 'Idioma de traducción actualizado a ' . htmlspecialchars($translated_lang_name) . ' (' . htmlspecialchars($translations[$target_lang]) . ').'; $message_type = 'success';
            } else {
                $message = 'No se pudo actualizar la configuración.'; $message_type = 'error';
            }
        }
    }

    // Cambiar contraseña
    if (isset($_POST['change_password'])) {
        $active_tab = 'config';
        $current = $_POST['current_password'] ?? '';
        $new1    = $_POST['new_password'] ?? '';
        $new2    = $_POST['new_password_confirm'] ?? '';
        $credentials_file = $data_dir . '/auth.json';
        $creds = json_decode(@file_get_contents($credentials_file), true) ?: [];
        if (empty($creds['password_hash']) || empty($creds['username'])) {
            $message = 'No hay credenciales para actualizar.'; $message_type = 'error';
        } else if (!password_verify($current, $creds['password_hash'])) {
            $message = 'La contraseña actual no es válida.'; $message_type = 'error';
        } else if ($new1 === '' || strlen($new1) < 6) {
            $message = 'La nueva contraseña debe tener al menos 6 caracteres.'; $message_type = 'error';
        } else if ($new1 !== $new2) {
            $message = 'La nueva contraseña y su confirmación no coinciden.'; $message_type = 'error';
        } else {
            $creds['password_hash'] = password_hash($new1, PASSWORD_BCRYPT);
            $tmp = $credentials_file . '.tmp';
            @file_put_contents($tmp, json_encode($creds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            @rename($tmp, $credentials_file);
            $message = 'Contraseña actualizada correctamente.'; $message_type = 'success';
        }
    }

    // Guardar personalización de feeds por idioma (archivo, título, descripción)
    if (isset($_POST['save_feed_customization'])) {
        $active_tab = 'config';
        $lang = strtolower(trim($_POST['lang'] ?? ''));
        $current_file = basename(trim($_POST['current_file'] ?? ''));
        $new_name = basename(trim($_POST['file_name'] ?? ''));
        $new_title = trim($_POST['feed_title'] ?? '');
        $new_desc  = trim($_POST['feed_description'] ?? '');

        if ($lang === '') { $message = 'Falta el idioma.'; $message_type='error'; goto after_post_redirect; }
        if ($current_file === '') { $message = 'Falta el archivo actual.'; $message_type='error'; goto after_post_redirect; }
        if ($new_name === '') { $new_name = $current_file; }
        if (!preg_match('/^[A-Za-z0-9_.\-]+\.xml$/', $new_name)) {
            $message = 'Nombre de archivo inválido. Usa solo letras, números, \'-_\' y termina en .xml';
            $message_type='error'; goto after_post_redirect;
        }
        // No permitir renombrar rss.xml (base)
        if (strtolower($current_file) === 'rss.xml' && strtolower($new_name) !== 'rss.xml') {
            $message = 'No se permite renombrar el fichero base rss.xml.'; $message_type='error'; goto after_post_redirect;
        }
        $old_path = __DIR__ . '/' . $current_file;
        $new_path = __DIR__ . '/' . $new_name;
        if (!file_exists($old_path)) { $message='El fichero actual no existe.'; $message_type='error'; goto after_post_redirect; }
        if ($new_name !== $current_file) {
            if (file_exists($new_path)) { $message='Ya existe un archivo con ese nombre.'; $message_type='error'; goto after_post_redirect; }
            if (!@rename($old_path, $new_path)) { $message='No se pudo renombrar el archivo.'; $message_type='error'; goto after_post_redirect; }
        }
        // Actualizar título y descripción del canal
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($new_path);
        if ($xml && isset($xml->channel)) {
            if ($new_title !== '') { $xml->channel->title = $new_title; }
            if ($new_desc !== '') { $xml->channel->description = $new_desc; }
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false; $dom->formatOutput = true; $dom->loadXML($xml->asXML());
            safe_dom_save($dom, $new_path);
        }
        // Persistir personalización
        $feed_custom = file_exists($feed_custom_file) ? (json_decode(@file_get_contents($feed_custom_file), true) ?: []) : [];
        if (!isset($feed_custom[$lang]) || !is_array($feed_custom[$lang])) { $feed_custom[$lang] = []; }
        $feed_custom[$lang]['filename'] = $new_name;
        $feed_custom[$lang]['title']    = $new_title;
        $feed_custom[$lang]['description'] = $new_desc;
        @file_put_contents($feed_custom_file, json_encode($feed_custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $translations = $config['feeds']['translations'] ?? [];
        $translations[$lang] = $new_name;
        telex_config_set($config, ['feeds', 'translations'], $translations);
        telex_save_config($config);
        if ($lang === $target_lang) {
            $rss_en_file = $new_path;
        }

        $message = 'Personalización de feed guardada para ' . htmlspecialchars($lang) . '.'; $message_type = 'success';
    }

    if (isset($_POST['run_analysis'])) {
        $active_tab = 'analysis';

        // Save the prompt
        $analysis_prompt = $_POST['analysis_prompt'] ?? '';
        file_put_contents($analysis_prompt_file, $analysis_prompt);

        $selected_files = $_POST['analysis_files'] ?? [];
        $gemini_model = $_POST['gemini_model'] ?? 'gemini-1.5-flash-latest';
        $gemini_key = telex_config_get($config, ['apis', 'gemini', 'api_key'], '');

        if (empty($gemini_key)) {
            $message = 'Falta la clave de API de Gemini. Configúrala en la pestaña de Config.';
            $message_type = 'error';
        } elseif (empty($selected_files)) {
            $message = 'No has seleccionado ningún archivo para analizar.';
            $message_type = 'error';
        } else {
            $xml_contents = '';
            foreach ($selected_files as $file) {
                $safe_path = __DIR__ . '/archive/' . basename($file);
                if (file_exists($safe_path)) {
                    $xml_contents .= "--- Contenido de " . basename($file) . " ---\n";
                    $xml_contents .= file_get_contents($safe_path);
                    $xml_contents .= "\n\n";
                }
            }

            $full_prompt = $analysis_prompt . "\n\n" . $xml_contents;

            $response = telex_gemini_request($gemini_key, $gemini_model, $full_prompt, ['source' => 'analysis'], $gemini_log_file);

            if ($response['ok']) {
                $_SESSION['analysis_result'] = $response['text'];
                $message = 'Análisis completado.';
                $message_type = 'success';
            } else {
                $message = 'Error en el análisis: ' . ($response['error'] ?? 'Error desconocido.');
                $message_type = 'error';
            }
        }
    }

    after_post_redirect:
    
    header("Location: telex.php?tab=" . urlencode($active_tab) . "&message=" . urlencode($message) . "&message_type=" . urlencode($message_type));
    exit;
}

// --- 3. GET: CARGAS PARA EL PANEL ---
if (isset($_GET['message']) && !$message) {
    $message = $_GET['message'];
    $message_type = $_GET['message_type'] ?? 'success';
}

$sugerencias_pendientes = json_decode(@file_get_contents($sugerencias_file), true) ?: [];
$seen_keys = array_flip(read_titlekeys($titlekeys_file));
$sugerencias_pendientes = array_values(array_filter($sugerencias_pendientes, function($sug) use ($seen_keys) {
    $k = title_key($sug['title'] ?? '');
    return $k !== '' && !isset($seen_keys[$k]);
}));
$_seen_local = [];
$sugerencias_pendientes = array_values(array_filter($sugerencias_pendientes, function($sug) use (&$_seen_local) {
    $k = title_key($sug['title'] ?? '');
    if ($k === '' || isset($_seen_local[$k])) return false;
    $_seen_local[$k] = true;
    return true;
}));

$prompt_actual  = file_exists($prompt_file) ? @file_get_contents($prompt_file) : '';
$sources_actuales = file_exists($sources_file) ? json_decode(@file_get_contents($sources_file), true) : [];

// Bots de Telegram guardados por idioma
$telegram_bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
$has_telegram = !empty($telegram_bots);
$available_tabs = ['gemini', 'rss', 'traduccion', 'config', 'prompt', 'sources', 'gemini_log', 'analysis'];
if ($has_telegram) { $available_tabs[] = 'telegram'; }
if (!in_array($active_tab, $available_tabs, true)) { $active_tab = 'gemini'; }

$archive_files = glob(__DIR__ . '/archive/*.xml');
$analysis_prompt_actual = '';
if (file_exists($analysis_prompt_file)) {
    $analysis_prompt_actual = file_get_contents($analysis_prompt_file);
} else {
    $analysis_prompt_actual = "Analiza cuidadosamente los archivos XML adjuntos, que contienen noticias clasificadas por meses. Tu tarea consiste en:\n\nComparar y sintetizar el contenido de todos los archivos, observando la evolución de los temas a lo largo del tiempo.\n\nIdentificar tendencias temáticas:\n\ntemas recurrentes,\n\nsu aparición y desaparición,\n\nvariaciones en el énfasis o tratamiento.\n\nResaltar novedades y rupturas: señala temas que emergen por primera vez o cambios llamativos en el periodo total estudiado.\n\nElaborar un informe analítico con un máximo de 5000 palabras, estructurado en:\n\nResumen ejecutivo (visión global en no más de 500 palabras).\n\nAnálisis de tendencias por periodo (cómo evolucionan los temas mes a mes o en bloques temporales significativos).\n\nNovedades destacadas (temas que irrumpen o cambian significativamente).\n\nSíntesis final y conclusiones (qué aprendizajes o implicaciones se derivan del conjunto).\n\nRequisitos de estilo:\n\nUsa un lenguaje claro y analítico, evitando repeticiones innecesarias.\n\nPresenta ejemplos concretos de noticias que ilustren cada tendencia.\n\nEstructura el texto con encabezados y subencabezados para facilitar la lectura. Utiliza formato MarkDown y pon los enlaces que utilices como ejemplos o referencias como notas al pie.\n\nNo superes en ningún caso las 5000 palabras.";
}

$analysis_result = '';
if (isset($_SESSION['analysis_result'])) {
    $analysis_result = $_SESSION['analysis_result'];
    unset($_SESSION['analysis_result']);
}

$rss_items_actuales = [];
if (file_exists($rss_file)) {
    libxml_use_internal_errors(true);
    $rss_content = simplexml_load_file($rss_file);
    if ($rss_content && isset($rss_content->channel->item)) {
        foreach ($rss_content->channel->item as $item) {
            $rss_items_actuales[] = $item;
        }
    }
}

$rss_en_items_actuales = [];
if (file_exists($rss_en_file)) {
    libxml_use_internal_errors(true);
    $comm_content = simplexml_load_file($rss_en_file);
    if ($comm_content && isset($comm_content->channel->item)) {
        foreach ($comm_content->channel->item as $item) {
            $rss_en_items_actuales[] = $item;
        }
    }
}
// Eliminado: lógica de envío a Communalia/Telegram

// <<<< --- TAREA 2: CARGAR Y PROCESAR EL LOG DE GEMINI --- >>>>
$gemini_logs = [];
$summary_logs = []; // New array for summary logs
if (file_exists($gemini_log_file)) {
    $log_content = file_get_contents($gemini_log_file);
    $log_lines = array_filter(explode("\n", trim($log_content)));
    foreach ($log_lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            if (isset($decoded['type']) && $decoded['type'] === 'summary') {
                $summary_logs[] = $decoded;
            } else {
                // Keep individual request logs for now, if needed for other display
                $gemini_logs[] = $decoded;
            }
        }
    }
    // Mostramos los logs más recientes primero
    $gemini_logs = array_reverse($gemini_logs); // Individual logs
    $summary_logs = array_reverse($summary_logs); // Summary logs
}

// Personalizaciones: detectar feeds activas por idioma
$active_lang_files = [];
// 1) Detectar por patrón rss_*.xml
foreach (glob(__DIR__ . '/rss_*.xml') as $fpath) {
    $base = basename($fpath);
    if (preg_match('/^rss_([a-z0-9\-]+)\.xml$/i', $base, $m)) {
        $lc = strtolower($m[1]);
        $active_lang_files[$lc] = $base;
    }
}
// 2) Incorporar personalizaciones guardadas
foreach (($feed_custom ?? []) as $lc => $cfg) {
    if (!is_array($cfg)) continue;
    $fname = $cfg['filename'] ?? '';
    if ($fname && file_exists(__DIR__ . '/' . $fname)) {
        $active_lang_files[strtolower($lc)] = $fname;
    }
}
// 2b) Incorporar correspondencias desde config.json
$configTranslations = $config['feeds']['translations'] ?? [];
foreach ($configTranslations as $lc => $fname) {
    $fname = trim((string)$fname);
    if ($fname === '') { continue; }
    if (file_exists(__DIR__ . '/' . $fname)) {
        $active_lang_files[strtolower((string)$lc)] = $fname;
    }
}
// 3) Incluir español base si existe rss.xml
if (file_exists(__DIR__ . '/rss.xml')) {
    $active_lang_files['es'] = 'rss.xml';
}
// Generar información de título/desc actuales para el panel
$feeds_personalizations = [];
ksort($active_lang_files);
foreach ($active_lang_files as $lc => $fname) {
    $full = __DIR__ . '/' . $fname;
    $title = ''; $desc = '';
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_file($full);
    if ($xml && isset($xml->channel)) {
        $title = (string)($xml->channel->title ?? '');
        $desc  = (string)($xml->channel->description ?? '');
    }
    // Pre-rellenar con custom si existe
    if (isset($feed_custom[$lc])) {
        if (!empty($feed_custom[$lc]['title'])) { $title = $feed_custom[$lc]['title']; }
        if (!empty($feed_custom[$lc]['description'])) { $desc = $feed_custom[$lc]['description']; }
    }
    $feeds_personalizations[] = [
        'lang' => $lc,
        'filename' => $fname,
        'title' => $title,
        'description' => $desc,
    ];
}

// Datos para pestaña Telegram
$telegram_langs_data = [];
$telegram_sent = file_exists($telegram_sent_file) ? (json_decode(@file_get_contents($telegram_sent_file), true) ?: []) : [];
$telegram_forgotten = file_exists($data_dir . '/telegram_forgotten.json') ? (json_decode(@file_get_contents($data_dir . '/telegram_forgotten.json'), true) ?: []) : [];
if (!empty($telegram_bots)) {
    foreach ($active_lang_files as $lc => $fname) {
        $bot = $telegram_bots[$lc] ?? null;
        if (!$bot) continue; // sin bot para este idioma
        $token = is_array($bot) ? ($bot['token'] ?? '') : (string)$bot;
        $chat  = is_array($bot) ? ($bot['chat_id'] ?? '') : '';
        if ($token === '' || $chat === '') continue; // incompleto

        $full = __DIR__ . '/' . $fname;
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_file($full);
        if (!$xml || !isset($xml->channel->item)) { continue; }

        // Inicializar baseline si no existe: marcar todos como enviados
        if (!isset($telegram_sent[$lc])) {
            $telegram_sent[$lc] = [];
            foreach ($xml->channel->item as $it) {
                $k = (string)($it->link ?? ''); if ($k === '') { $k = (string)($it->guid ?? ''); }
                if ($k !== '') { $telegram_sent[$lc][$k] = true; }
            }
            @file_put_contents($telegram_sent_file, json_encode($telegram_sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Calcular pendientes y preparar listado (limit 200)
        $pending = [];
        $all = [];
        $idx = 0;
        foreach ($xml->channel->item as $it) {
            $title = (string)($it->title ?? '');
            $link  = (string)($it->link ?? '');
            $guid  = (string)($it->guid ?? '');
            $key   = $link !== '' ? $link : $guid;
            $is_sent = !empty($telegram_sent[$lc][$key]);
            $is_forgotten = !empty($telegram_forgotten[$lc][$key]);
            $row = ['idx'=>$idx, 'title'=>$title, 'link'=>$link, 'sent'=> $is_sent, 'forgotten' => $is_forgotten];
            if (!$is_sent && !$is_forgotten) {
                $pending[] = $row;
                $all[] = $row; // Only add to $all if it's pending
            }
            $idx++;
            if ($idx >= 200) break; // no cargar demasiados
        }
        $telegram_langs_data[] = [
            'lang' => $lc,
            'filename' => $fname,
            'pending' => $pending,
            'all' => array_slice($all, 0, 200),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Telex</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="telex.png" rel="icon" type="image/png"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Special+Elite&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        :root { --primary-color: #0d6efd; --success-color: #198754; --danger-color: #dc3545; --light-gray: #f8f9fa; --gray: #dee2e6; --dark-gray: #212529; --font-sans-serif: 'Roboto', system-ui, -apple-system, "Segoe UI", Arial, sans-serif; }
        body { font-family: var(--font-sans-serif); margin: 0; background-color: var(--light-gray); color: var(--dark-gray); line-height: 1.6; }
        .container { max-width: 900px; margin: 2rem auto; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1, h2 { border-bottom: 1px solid var(--gray); padding-bottom: 0.5rem; margin-top: 0; margin-bottom: 1.5rem; font-weight: 500; }
        .item { border: 1px solid var(--gray); padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 5px; background: #fff; overflow: hidden; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .form-group input, .form-group textarea { width: 100%; padding: 0.75rem; box-sizing: border-box; border: 1px solid var(--gray); border-radius: 4px; font-size: 1rem; }
        .form-group textarea { min-height: 150px; resize: vertical; }
        .item-row { display: flex; align-items: center; gap: .5rem; }
        .item-row .grow { flex: 1 1 auto; }
        .button { background-color: var(--primary-color); color: white; padding: 0.6rem 1.2rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; text-decoration: none; display: inline-block; transition: background-color 0.2s; }
        .button:hover { opacity: 0.9; }
        .button.approve { background-color: var(--success-color); }
        .button.reject { background-color: var(--danger-color); }
        .message { padding: 1rem; margin-bottom: 1.5rem; border-radius: 4px; text-align: center; font-weight: 500; }
        .message.success { background-color: #d1e7dd; color: #0f5132; }
        .message.error { background-color: #f8d7da; color: #842029; }
        .tabs {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
            gap: 0.5rem;
        }
        .tab-link {
            padding: 0.5rem 1rem;
            cursor: pointer;
            background-color: transparent;
            color: var(--dark-gray);
            font-family: "Special Elite", system-ui;
            font-weight: 400;
            text-decoration: none;
            transition: transform 0.3s ease, color 0.3s ease;
            position: relative;
            font-size: 0.9rem;
        }
        .tab-link:hover {
            color: var(--primary-color);
        }
        .tab-link.active {
            background-color: transparent;
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.1rem;
        }
        .tab-link.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background-color: var(--primary-color);
            transform: scaleX(1);
            transition: transform 0.3s ease;
        }
        .tab-link:not(.active)::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background-color: var(--primary-color);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        .tab-link:not(.active):hover::after {
            transform: scaleX(0.8);
        }
        .tab-content { display: none; width: 100%; }
        .tab-content.active { display: block; }
        .source-item { display: flex; align-items: center; margin-bottom: 0.5rem; }
        .source-item input { margin-right: 1rem; }
        .source-item input[name="source_name[]"] { flex: 1; }
        .source-item input[name="source_url[]"] { flex: 2; }
        .source-item button { flex-shrink: 0; }
        .logout-form { position: absolute; top: 1rem; right: 2rem; }
        .floating-action-button {
            position: absolute;
            top: 1rem;
            right: 10rem; /* Adjust as needed to be above 'Salir' */
            z-index: 1000;
        }
        .special-elite-regular {font-family: "Special Elite", system-ui; font-weight: 400;font-style: normal; font-size:2.4em; color:#0d6efd; padding:16px; padding-top:0px; }
        
        /* <<<< --- TAREA 2: ESTILOS PARA LA PESTAÑA DE LOG --- >>>> */
        .log-entry { font-family: monospace; font-size: 0.9em; }
        .log-entry summary { cursor: pointer; font-weight: bold; margin-bottom: 0.5rem; }
        .log-entry pre {
            background-color: #f0f0f0;
            padding: 0.75rem;
            border-radius: 4px;
            white-space: pre-wrap;
            word-break: break-all;
            word-wrap: break-word; /* Added for better word breaking */
            max-height: 300px;
            overflow-y: auto;
            overflow-x: auto;
            max-width: 100%;
        }
        .log-entry strong { color: var(--primary-color); }
        .log-entry .error { color: var(--danger-color); font-weight: bold; }
        .app-footer {
            text-align: center;
            padding: 1.5rem 1rem;
            margin-top: 2rem;
            background-color: var(--light-gray);
            color: var(--dark-gray);
            font-size: 0.9rem;
            border-top: 1px solid var(--gray);
        }
        .app-footer p {
            margin: 0;
            line-height: 1.4;
        }
        .app-footer img {
            width: 30px;
            height: 30px;
            vertical-align: middle;
            margin-right: 0.5rem;
            filter: sepia(100%) saturate(500%) hue-rotate(300deg);
        }

        .result-container {
            position: relative;
            background-color: #f0f0f0;
            border-radius: 4px;
            padding: 1rem;
        }
        .result-container pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            margin: 0;
        }
        .result-container .copy-btn {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            padding: 0.3rem 0.6rem;
            background-color: var(--gray);
            color: var(--dark-gray);
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .result-container .copy-btn:hover {
            background-color: var(--dark-gray);
            color: white;
        }

    </style>
</head>
<body>
<div class="container">
    <form method="get" class="logout-form">
        <button type="submit" name="logout" value="1" class="button reject">Salir</button>
    </form>

    <div class="floating-action-button">
        <form method="post">
            <input type="hidden" name="active_tab" value="gemini">
            <button type="submit" name="fetch_suggestions" class="button approve">📡 Recibir Telex</button>
        </form>
    </div>
    
    <a href="telex.php"><img src="telex.png" alt="Telex" style="float:right; width:60px; height:auto; object-fit:contain;" /></a>
    <h1 class="special-elite-regular">Telex</h1>

    <?php if ($message): ?>
        <div class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?tab=gemini" class="tab-link<?php echo $active_tab === 'gemini' ? ' active' : ''; ?>" data-tab="gemini">Telex</a>
        <a href="?tab=rss" class="tab-link<?php echo $active_tab === 'rss' ? ' active' : ''; ?>" data-tab="rss">RSS</a>
        <a href="?tab=traduccion" class="tab-link<?php echo $active_tab === 'traduccion' ? ' active' : ''; ?>" data-tab="traduccion">Trad</a>
        <?php if ($has_telegram): ?>
            <a href="?tab=telegram" class="tab-link<?php echo $active_tab === 'telegram' ? ' active' : ''; ?>" data-tab="telegram">Telegram</a>
        <?php endif; ?>
        <a href="?tab=config" class="tab-link<?php echo $active_tab === 'config' ? ' active' : ''; ?>" data-tab="config">Config</a>
        <a href="?tab=prompt" class="tab-link<?php echo $active_tab === 'prompt' ? ' active' : ''; ?>" data-tab="prompt">Prompt</a>
        <a href="?tab=sources" class="tab-link<?php echo $active_tab === 'sources' ? ' active' : ''; ?>" data-tab="sources">Fuentes</a>
        <a href="?tab=analysis" class="tab-link<?php echo $active_tab === 'analysis' ? ' active' : ''; ?>" data-tab="analysis">Análisis</a>
        <a href="?tab=gemini_log" class="tab-link<?php echo $active_tab === 'gemini_log' ? ' active' : ''; ?>" data-tab="gemini_log">Log</a>
    </div>

    <div id="gemini" class="tab-content<?php echo $active_tab === 'gemini' ? ' active' : ''; ?>">

        
        <div id="suggestions-container">
            <h2>Telex Pendientes</h2>
            <?php if (!empty($sugerencias_pendientes)): ?>
                <?php foreach ($sugerencias_pendientes as $sug): ?>
                    <div class="item">
                        <form method="post">
                            <input type="hidden" name="active_tab" value="gemini">
                            <input type="hidden" name="suggestion_id" value="<?php echo htmlspecialchars($sug['id']); ?>">
                            <div class="form-group"><label>Título:</label><input type="text" name="title" value="<?php echo htmlspecialchars($sug['title']); ?>"></div>
                            <div class="form-group"><label>Descripción:</label><textarea name="description" rows="5"><?php echo htmlspecialchars(telex_remove_link_from_description($sug['summary'], $sug['link'])); ?></textarea></div>
                            <div class="form-group"><label><a href="<?php echo htmlspecialchars($sug['link']); ?>" target="_blank" rel="noopener noreferrer">Enlace:</a></label><input type="url" name="link" value="<?php echo htmlspecialchars($sug['link']); ?>"></div>
                            <div class="button-group">
                                <button type="submit" name="action" value="approve" class="button approve">Aprobar</button>
                                <button type="submit" name="action" value="edit" class="button">Guardar y Aprobar</button>
                                <button type="submit" name="action" value="reject" class="button reject">Rechazar</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No hay Telex pendientes de revisión.</p>
            <?php endif; ?>
        </div>
        <div class="item">
            <h2>Añadir entrada de otras fuentes</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="active_tab" value="gemini">
                <div class="form-group"><label>Título:</label><input type="text" name="manual_title" placeholder="Título de la noticia" required></div>
                <div class="form-group"><label>URL:</label><input type="url" name="manual_link" placeholder="https://... (opcional)"></div>
                <div class="form-group"><label>Descripción (HTML permitido):</label><textarea name="manual_description" rows="5" placeholder="Resumen en español"></textarea></div>
                <div class="form-group"><label>Imagen (subir):</label><input type="file" name="manual_image" accept="image/*"></div>
                <div class="form-group"><label>o URL de imagen:</label><input type="url" name="manual_image_url" placeholder="https://..."></div>
                <button type="submit" name="add_manual_item" class="button approve">Añadir a rss.xml</button>
            </form>
        </div>
    </div>

    <div id="rss" class="tab-content<?php echo $active_tab === 'rss' ? ' active' : ''; ?>">
        <h2>Editar `rss.xml`</h2>
        <form class="item" method="post">
            <input type="hidden" name="active_tab" value="rss">
            <?php $__i=0; if (!empty($rss_items_actuales)): foreach(array_slice($rss_items_actuales, 0, 200) as $item): ?>
                <div class="item">
                    <input type="hidden" name="rss_guid[]" value="<?php echo htmlspecialchars((string)$item->guid); ?>">
                    <input type="hidden" name="rss_date[]" value="<?php echo htmlspecialchars((string)$item->pubDate); ?>">
                    <div class="form-group item-row"><label style="min-width:60px;">Título:</label><input class="grow" type="text" name="rss_title[]" value="<?php echo htmlspecialchars((string)$item->title); ?>">
                        <div class="controls" style="display:flex; align-items:center; gap:.5rem;">
                            <label style="font-weight:400;"><input type="checkbox" name="rss_delete[]" value="<?php echo $__i; ?>"> Eliminar</label>
                        </div>
                    </div>
                    <div class="form-group"><label>URL:</label><input type="text" name="rss_url[]" value="<?php echo htmlspecialchars((string)$item->link); ?>"></div>
                    <div class="form-group"><label>Descripción:</label><textarea name="rss_description[]"><?php echo htmlspecialchars((string)$item->description); ?></textarea></div>
                    <div class="item-actions" style="margin-top:.5rem; display:flex; gap:.5rem; flex-wrap:wrap;">
                        <input type="hidden" name="rss_item_lang[<?php echo $__i; ?>]" value="es">
                        <input type="hidden" name="rss_item_tab[<?php echo $__i; ?>]" value="rss">
                        <button type="submit" name="delete_rss_item" value="<?php echo $__i; ?>" class="button reject" style="padding:.3rem .6rem;" onclick="return confirm('¿Borrar esta entrada de rss.xml?');">Borrar</button>
                        <button type="submit" name="save_rss_item" value="<?php echo $__i; ?>" class="button approve" style="padding:.3rem .6rem;">Guardar</button>
                        <button type="submit" name="telegram_send_item" value="<?php echo $__i; ?>" class="button" style="padding:.3rem .6rem;">Enviar a Telegram</button>
                    </div>
                </div>
            <?php $__i++; endforeach; else: ?><p>No se pudo cargar el fichero rss.xml o está vacío.</p><?php endif; ?>
            <div class="button-group">
                <button type="submit" name="save_rss" class="button approve">Guardar Cambios en RSS</button>
                <button type="submit" name="delete_rss_selected" class="button reject">Eliminar seleccionados</button>
                <button type="submit" name="delete_rss_all" class="button" onclick="return confirm('¿Eliminar todas las entradas de rss.xml?');">Eliminar todos</button>
            </div>
        </form>
    </div>

    <div id="traduccion" class="tab-content<?php echo $active_tab === 'traduccion' ? ' active' : ''; ?>">
        <h2>Traducción: <?php echo htmlspecialchars($target_feed_filename); ?></h2>
        <form class="item" method="post" style="margin-bottom:1rem;">
            <input type="hidden" name="active_tab" value="traduccion">
            <div class="form-group">
                <label>Idioma de traducción</label>
                <select name="translator_lang">
                    <?php
                    $langs = [
                        'en','es','fr','de','it','pt','ca','gl','eu','nl','sv','no','da','fi','pl','cs','sk','sl','hu','ro','bg','el','ru','uk','ar','he','tr','fa','hi','bn','ur','ta','te','kn','ml','mr','gu','pa','zh','zh-cn','zh-tw','ja','ko','vi','id','ms','th'
                    ];
                    foreach ($langs as $lc) {
                        $sel = ($lc === $target_lang) ? ' selected' : '';
                        echo '<option value="'.htmlspecialchars($lc).'"'.$sel.'>'.htmlspecialchars(lang_name_es($lc)).' ('.htmlspecialchars($lc).')</option>';
                    }
                    ?>
                </select>
                <small>Idioma al que traducir ahora. La traducción se guardará en <?php echo htmlspecialchars($target_feed_filename); ?></small>
            </div>
            <button type="submit" name="set_translator_lang" class="button">Guardar idioma de traducción</button>
        </form>
        <form class="item" method="post" style="margin-bottom:1rem;">
            <input type="hidden" name="active_tab" value="traduccion">
            <p>Genera ahora mismo la traducción de rss.xml a <?php echo htmlspecialchars($target_feed_filename); ?> con Google Translate (una sola ejecución).</p>
            <label style="display:flex; align-items:center; gap:.4rem; margin-bottom:.6rem;">
                <input type="checkbox" name="force_translate" value="1">
                Ignorar caché y traducir de nuevo todos los elementos
            </label>
            <button type="submit" name="run_translator_now" class="button">Traducir ahora</button>
        </form>
        <form class="item" method="post">
            <input type="hidden" name="active_tab" value="traduccion">
            <?php $__j=0; if (!empty($rss_en_items_actuales)): foreach(array_slice($rss_en_items_actuales, 0, 200) as $item): ?>
                <div class="item">
                    <div class="form-group item-row"><label style="min-width:60px;">Título (<?php echo htmlspecialchars($translated_lang_name); ?>):</label><input class="grow" type="text" name="rss_en_title[]" value="<?php echo htmlspecialchars((string)$item->title); ?>">
                        <div class="controls" style="display:flex; align-items:center; gap:.5rem;">
                            <label style="font-weight:400;"><input type="checkbox" name="rss_en_delete[]" value="<?php echo $__j; ?>"> Eliminar</label>
                        </div>
                    </div>
                    <div class="form-group"><label>URL:</label><input type="text" name="rss_en_url[]" value="<?php echo htmlspecialchars((string)$item->link); ?>"></div>
                    <div class="form-group"><label>Descripción (<?php echo htmlspecialchars($translated_lang_name); ?>):</label><textarea name="rss_en_description[]" rows="4"><?php echo htmlspecialchars((string)$item->description); ?></textarea></div>
                    <div class="item-actions" style="margin-top:.5rem; display:flex; gap:.5rem; flex-wrap:wrap;">
                        <input type="hidden" name="rss_en_item_lang[<?php echo $__j; ?>]" value="<?php echo htmlspecialchars($target_lang); ?>">
                        <input type="hidden" name="rss_en_item_tab[<?php echo $__j; ?>]" value="traduccion">
                        <button type="submit" name="delete_rss_en_item" value="<?php echo $__j; ?>" class="button reject" style="padding:.3rem .6rem;" onclick="return confirm('¿Borrar esta entrada de <?php echo htmlspecialchars($target_feed_filename); ?>?');">Borrar</button>
                        <button type="submit" name="save_rss_en_item" value="<?php echo $__j; ?>" class="button approve" style="padding:.3rem .6rem;">Guardar</button>
                        <button type="submit" name="telegram_send_item" value="<?php echo $__j; ?>" class="button" style="padding:.3rem .6rem;">Enviar a Telegram</button>
                    </div>
                </div>
            <?php $__j++; endforeach; else: ?><p>No se pudo cargar el fichero rss_en.xml o está vacío.</p><?php endif; ?>
            <div class="button-group">
                <button type="submit" name="save_rss_en" class="button approve">Guardar rss_<?php echo htmlspecialchars($target_lang); ?>.xml</button>
                <button type="submit" name="delete_rss_en_selected" class="button reject">Eliminar seleccionados</button>
                <button type="submit" name="delete_rss_en_all" class="button" onclick="return confirm('¿Eliminar todas las entradas de rss_<?php echo htmlspecialchars($target_lang); ?>.xml?');">Eliminar todos</button>
            </div>
        </form>
    </div>

    <?php if ($has_telegram): ?>
    <div id="telegram" class="tab-content<?php echo $active_tab === 'telegram' ? ' active' : ''; ?>">
        <h2>Telegram</h2>
        <?php if (!empty($telegram_langs_data)): foreach ($telegram_langs_data as $tg): ?>
            <div class="item">
                <h3 style="margin-top:0;">Canal <?php echo htmlspecialchars(lang_name_es($tg['lang'])); ?> (<?php echo htmlspecialchars($tg['lang']); ?>)</h3>
                <p>Feed: <code><?php echo htmlspecialchars($tg['filename']); ?></code> — Pendientes: <strong><?php echo count($tg['pending']); ?></strong></p>
                <form method="post" style="margin-bottom: .8rem;">
                    <input type="hidden" name="active_tab" value="telegram">
                    <input type="hidden" name="lang" value="<?php echo htmlspecialchars($tg['lang']); ?>">
                    <button type="submit" name="telegram_send_all" class="button approve">Enviar pendientes</button>
                    <button type="submit" name="telegram_forget_all" class="button reject" style="margin-left:.5rem;">Olvidar todos</button>
                </form>
                <div>
                    <p style="margin:.5rem 0 .3rem;">Últimos items</p>
                    <?php foreach ($tg['all'] as $row): ?>
                        <div class="form-group item-row" style="align-items:center; gap:.5rem;">
                            <span class="grow"><strong><?php echo htmlspecialchars($row['title']); ?></strong><br><small><?php echo htmlspecialchars($row['link']); ?></small><?php echo $row['sent']? ' — <em style="color:#198754;">enviado</em>':($row['forgotten']? ' — <em style="color:#6c757d;">olvidado</em>':''); ?></span>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="active_tab" value="telegram">
                                <input type="hidden" name="from_tab" value="telegram">
                                <input type="hidden" name="lang" value="<?php echo htmlspecialchars($tg['lang']); ?>">
                                <input type="hidden" name="idx" value="<?php echo intval($row['idx']); ?>">
                                <button type="submit" name="telegram_send_item" class="button" style="padding:.3rem .6rem;">Enviar este</button>
                                <?php if (!$row['sent'] && !$row['forgotten']): ?>
                                <button type="submit" name="telegram_forget_item" class="button reject" style="padding:.3rem .6rem; margin-left:.5rem;">Olvidar</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; else: ?>
            <div class="item"><p>No hay ningún canal con token y Chat ID configurados. Añádelos en Configuración → Bots de Telegram.</p></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div id="config" class="tab-content<?php echo $active_tab === 'config' ? ' active' : ''; ?>">
        <h2>Configuración</h2>
        <div class="item" style="margin-bottom:1rem;">
            <h3 style="margin-top:0;">Estado del traductor</h3>
            <p>
                <?php
                    function fmt_mtime($p) { return file_exists($p) ? date('Y-m-d H:i:s', filemtime($p)) : '—'; }
                    echo 'rss.xml: <strong>' . htmlspecialchars(fmt_mtime($rss_file)) . "</strong><br>";
                    echo htmlspecialchars($target_feed_filename) . ': <strong>' . htmlspecialchars(fmt_mtime($rss_en_file)) . "</strong><br>";
                    echo 'translation_cache.json: <strong>' . htmlspecialchars(fmt_mtime($translation_cache)) . "</strong><br>";
                    echo 'rss_change_cache.json: <strong>' . htmlspecialchars(fmt_mtime($rss_change_cache)) . "</strong>";
                ?>
            </p>
        </div>
        <form class="item" method="post">
            <input type="hidden" name="active_tab" value="config">
            <div class="form-group">
                <label>Gemini API Key</label>
                <input type="text" name="gemini_api_key" value="<?php echo htmlspecialchars(telex_config_get($config, ['apis','gemini','api_key'], '')); ?>" placeholder="GEMINI_API_KEY">
            </div>
            <div class="form-group">
                <label>Gemini Model</label>
                <input type="text" id="gemini_model" name="gemini_model" value="<?php echo htmlspecialchars(telex_config_get($config, ['apis','gemini','model'], 'gemini-1.5-flash-latest')); ?>" placeholder="gemini-1.5-flash-latest">
                <div style="margin-top: .5rem;">
                    <small>Modelos sugeridos:</small>
                    <select onchange="document.getElementById('gemini_model').value=this.value" style="margin-left:.5rem;">
                        <option value="">— elegir —</option>
                        <option>gemini-1.5-flash-latest</option>
                        <option>gemini-1.5-pro-latest</option>
                        <option>gemini-1.5-flash</option>
                        <option>gemini-1.5-pro</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Google Translate API Key</label>
                <input type="text" name="google_translate_api_key" value="<?php echo htmlspecialchars(telex_config_get($config, ['apis','google_translate','api_key'], '')); ?>" placeholder="GOOGLE_TRANSLATE_API_KEY">
            </div>

            <div class="form-group">
                <label>Telegram (ES): enviar automáticamente al aprobar</label>
                <?php $auto_es = telex_flag_enabled(telex_config_get($config, ['telegram','auto_send','es'], true)); ?>
                <label style="font-weight:400; display:inline-flex; align-items:center; gap:.4rem; margin-left:.5rem;">
                    <input type="checkbox" name="telegram_auto_send_es" value="1" <?php echo $auto_es ? 'checked' : ''; ?>> Sí
                </label>
                <small>Si hay un bot y Chat ID configurados para español, enviará al canal al aprobar.</small>
            </div>
            <div class="form-group">
                <label>Intervalo sugerido del traductor (ms)</label>
                <input type="number" name="translator_interval_ms" min="1000" step="500" value="<?php echo htmlspecialchars((string)telex_config_get($config, ['translator','interval_ms'], 60000)); ?>">
                <small>Úsalo si programas una tarea externa para traducciones periódicas.</small>
            </div>
            <button type="submit" name="save_config" class="button approve">Guardar Configuración</button>
        </form>

        <div class="item" style="margin-top:1rem;">
            <h3 style="margin-top:0;">Bots de Telegram por idioma</h3>
            <?php if (!empty($telegram_bots)): ?>
                <?php foreach ($telegram_bots as $lc => $botval): ?>
                    <?php $tokenStr = is_array($botval) ? ($botval['token'] ?? '') : (string)$botval; $chatStr = is_array($botval) ? ($botval['chat_id'] ?? '') : ''; ?>
                    <div class="form-group item-row" style="align-items:center; gap: .75rem;">
                        <strong style="min-width: 160px;">
                            <?php echo htmlspecialchars(lang_name_es($lc)); ?> (<?php echo htmlspecialchars($lc); ?>)
                        </strong>
                        <span style="font-family:monospace; color:#555;">
                            <?php
                                $len  = strlen($tokenStr);
                                $tail = ($len > 6) ? substr($tokenStr, -6) : '';
                                $mask = '••••••••' . $tail; // longitud fija para no romper maquetación
                                echo htmlspecialchars($mask);
                            ?>
                        </span>
                        <form method="post" class="item-row" style="align-items:center; gap:.5rem; margin:0;">
                            <input type="hidden" name="active_tab" value="config">
                            <input type="hidden" name="lang" value="<?php echo htmlspecialchars($lc); ?>">
                            <input type="text" name="telegram_chatid" value="<?php echo htmlspecialchars($chatStr); ?>" placeholder="@canal o ID numérico" style="min-width: 220px;">
                            <button type="submit" name="update_telegram_chat" class="button" style="padding:.4rem .6rem;">Guardar chat</button>
                        </form>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="active_tab" value="config">
                            <input type="hidden" name="remove_lang" value="<?php echo htmlspecialchars($lc); ?>">
                            <button type="submit" name="remove_telegram_bot" class="button reject" style="padding:.4rem .6rem;">Eliminar</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No hay bots de Telegram configurados.</p>
            <?php endif; ?>
        </div>

        <form class="item" method="post" style="margin-top:1rem;">
            <input type="hidden" name="active_tab" value="config">
            <h3 style="margin-top:0;">Añadir bot de Telegram</h3>
            <div class="form-group">
                <label>Idioma del bot</label>
                <select name="telegram_lang" required>
                    <?php
                    $langs_add = [
                        'en','es','fr','de','it','pt','ca','gl','eu','nl','sv','no','da','fi','pl','cs','sk','sl','hu','ro','bg','el','ru','uk','ar','he','tr','fa','hi','bn','ur','ta','te','kn','ml','mr','gu','pa','zh','zh-cn','zh-tw','ja','ko','vi','id','ms','th'
                    ];
                    foreach ($langs_add as $lc2) {
                        echo '<option value="'.htmlspecialchars($lc2).'">'.htmlspecialchars(lang_name_es($lc2)).' ('.htmlspecialchars($lc2).')</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>Token del bot</label>
                <input type="text" name="telegram_token" placeholder="123456789:ABCDEF..." required>
            </div>
            <div class="form-group">
                <label>Chat ID asociado</label>
                <input type="text" name="telegram_chatid" placeholder="@canal o ID numérico">
                <small>Se almacena localmente en <code>data/telegram_tokens.json</code>.</small>
            </div>
            <button type="submit" name="add_telegram_bot" class="button approve">Añadir/Actualizar bot</button>
        </form>

        <div class="item" style="margin-top:1rem;">
            <h3 style="margin-top:0;">Personalizaciones</h3>
            <p>Configura nombre de archivo, título y descripción de las feeds por idioma activo.</p>
            <?php if (!empty($feeds_personalizations)): ?>
                <?php foreach ($feeds_personalizations as $fp): ?>
                    <form method="post" class="item" style="margin-top:1rem;">
                        <input type="hidden" name="active_tab" value="config">
                        <input type="hidden" name="lang" value="<?php echo htmlspecialchars($fp['lang']); ?>">
                        <input type="hidden" name="current_file" value="<?php echo htmlspecialchars($fp['filename']); ?>">
                        <h4 style="margin-top:0;">
                            <?php echo htmlspecialchars(lang_name_es($fp['lang'])); ?> (<?php echo htmlspecialchars($fp['lang']); ?>)
                        </h4>
                        <div class="form-group">
                            <label>Nombre de archivo</label>
                            <input type="text" name="file_name" value="<?php echo htmlspecialchars($fp['filename']); ?>" placeholder="p.ej. noticias_en.xml">
                            <small>Debe terminar en .xml. No se renombra el fichero base rss.xml desde aquí.</small>
                        </div>
                        <div class="form-group">
                            <label>Título de la feed</label>
                            <input type="text" name="feed_title" value="<?php echo htmlspecialchars($fp['title']); ?>" placeholder="Título visible en lectores de feeds">
                        </div>
                        <div class="form-group">
                            <label>Descripción de la feed</label>
                            <textarea name="feed_description" rows="3" placeholder="Descripción visible en lectores de feeds"><?php echo htmlspecialchars($fp['description']); ?></textarea>
                        </div>
                        <button type="submit" name="save_feed_customization" class="button approve">Guardar personalización</button>
                    </form>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No se han detectado feeds de idiomas activas. Genera una traducción para crear <code>rss_&lt;idioma&gt;.xml</code>.</p>
            <?php endif; ?>
        </div>
        <form class="item" method="post" style="margin-top:1rem;">
            <input type="hidden" name="active_tab" value="config">
            <p>Pruebas de conectividad (usa los valores mostrados arriba):</p>
            <div class="button-group">
                <button type="submit" name="test_gemini" class="button">Probar Gemini</button>
                <button type="submit" name="test_translate" class="button" style="margin-left:.5rem;">Probar Translate</button>
            </div>
        </form>
        <form class="item" method="post" style="margin-top:1rem;">
            <input type="hidden" name="active_tab" value="config">
            <h3 style="margin-top:0;">Cambiar contraseña</h3>
            <div class="form-group"><label>Contraseña actual</label><input type="password" name="current_password" required></div>
            <div class="form-group"><label>Nueva contraseña</label><input type="password" name="new_password" required></div>
            <div class="form-group"><label>Confirmar nueva contraseña</label><input type="password" name="new_password_confirm" required></div>
            <button type="submit" name="change_password" class="button approve">Actualizar contraseña</button>
        </form>
    </div>

    <div id="prompt" class="tab-content<?php echo $active_tab === 'prompt' ? ' active' : ''; ?>">
        <h2>Editar Prompt de Gemini</h2>
        <form class="item" method="post">
            <input type="hidden" name="active_tab" value="prompt">
            <div class="form-group">
                <label for="prompt_text">Texto del Prompt (usa {{title}}, {{description}}, {{link}} y {{examples}} como variables):</label>
                <textarea name="prompt_text" id="prompt_text" style="height: 400px; font-family: monospace;"><?php echo htmlspecialchars($prompt_actual); ?></textarea>
            </div>
            <button type="submit" name="save_prompt" class="button">Guardar Prompt</button>
        </form>
    </div>

    <div id="sources" class="tab-content<?php echo $active_tab === 'sources' ? ' active' : ''; ?>">
        <h2>Gestionar Fuentes RSS</h2>

        <form class="item" method="post" id="sources-form">
            <input type="hidden" name="active_tab" value="sources">
            <div id="sources-list">
                 <?php if (!empty($sources_actuales)): foreach($sources_actuales as $source): ?>
                    <div class="form-group source-item">
                        <input type="text" name="source_name[]" value="<?php echo htmlspecialchars($source['name']); ?>" placeholder="Nombre de la fuente">
                        <input type="text" name="source_url[]" value="<?php echo htmlspecialchars($source['url']); ?>" placeholder="URL de la fuente">
                        <button type="button" onclick="this.parentElement.remove()" class="button reject" style="padding: 0.6rem 0.8rem;">X</button>
                    </div>
                <?php endforeach; else: ?><p>No hay fuentes configuradas.</p><?php endif; ?>
            </div>
            <hr style="margin: 1.5rem 0;">
            <button type="button" id="add-source-btn" class="button">Añadir Nueva Fuente</button>
            <button type="submit" name="save_sources" class="button approve">Guardar Todas las Fuentes</button>
        </form>

        <div class="item">
            <h3 style="margin-top:0;">Importar y Exportar OPML</h3>
            <form method="post" enctype="multipart/form-data" style="margin-bottom: 1rem;">
                <input type="hidden" name="active_tab" value="sources">
                <div class="form-group">
                    <label>Importar fuentes desde archivo OPML</label>
                    <input type="file" name="opml_file" accept=".opml,.xml" required>
                </div>
                <button type="submit" name="import_opml" class="button">Importar OPML</button>
            </form>
            <form method="post">
                <input type="hidden" name="active_tab" value="sources">
                <button type="submit" name="export_opml" class="button approve">Exportar Selección a OPML</button>
            </form>
        </div>
    </div>

    <div id="analysis" class="tab-content<?php echo $active_tab === 'analysis' ? ' active' : ''; ?>">
        <h2>Análisis de Archivos</h2>
        <form class="item" method="post">
            <input type="hidden" name="active_tab" value="analysis">
            <h3>1. Seleccionar Archivos</h3>
            <div class="form-group">
                <div id="archive-files-list">
                    <?php if (!empty($archive_files)): ?>
                        <?php foreach ($archive_files as $file): ?>
                            <label><input type="checkbox" name="analysis_files[]" value="<?php echo htmlspecialchars($file); ?>"> <?php echo htmlspecialchars(basename($file)); ?></label><br>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No se encontraron archivos en el directorio de archivos.</p>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 1rem;">
                    <button type="button" class="button" onclick="toggleArchiveCheckboxes(true)">Seleccionar Todos</button>
                    <button type="button" class="button" onclick="toggleArchiveCheckboxes(false)">Desmarcar Todos</button>
                </div>
            </div>

            <h3>2. Prompt de Análisis</h3>
            <div class="form-group">
                <textarea name="analysis_prompt" style="height: 300px; font-family: monospace;"><?php echo htmlspecialchars($analysis_prompt_actual); ?></textarea>
            </div>

            <h3>3. Ejecutar Análisis</h3>
            <div class="form-group">
                <label>Modelo de Gemini</label>
                <select name="gemini_model">
                    <option>gemini-1.5-flash-latest</option>
                    <option>gemini-1.5-pro-latest</option>
                    <option>gemini-1.5-flash</option>
                    <option>gemini-1.5-pro</option>
                </select>
            </div>
            <button type="submit" name="run_analysis" class="button approve">Enviar Telex 📡</button>
        </form>

        <?php if (!empty($analysis_result)): ?>
            <h2>Resultado del Análisis</h2>
            <div class="item">
                <div class="result-container">
                    <button class="copy-btn" onclick="copyToClipboard(this)">Copiar</button>
                    <pre><?php echo htmlspecialchars($analysis_result); ?></pre>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div id="gemini_log" class="tab-content<?php echo $active_tab === 'gemini_log' ? ' active' : ''; ?>">
        <h2>Log de Peticiones a Gemini</h2>
        <div class="item">
        <?php if (!empty($summary_logs)): ?>
            <h3>Resumen de las últimas 16 ejecuciones:</h3>
            <?php foreach (array_slice($summary_logs, 0, 16) as $log): ?>
                <div class="log-entry item">
                    <p>
                        <strong>Fecha:</strong> <?php echo htmlspecialchars($log['timestamp'] ?? 'N/A'); ?><br>
                        <strong>Artículos procesados:</strong> <?php echo htmlspecialchars($log['total_processed'] ?? 0); ?><br>
                        <strong>Sugerencias generadas:</strong> <?php echo htmlspecialchars($log['created'] ?? 0); ?><br>
                        <strong>Artículos rechazados:</strong> <?php echo htmlspecialchars($log['skipped'] ?? 0); ?><br>
                        <strong>Errores:</strong> <?php echo htmlspecialchars($log['errors'] ?? 0); ?>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No hay resúmenes de ejecución de Gemini. Pulsa "Recibir Telex" para generar uno.</p>
        <?php endif; ?>
        </div>
    </div>

</div>

</div>

<div class="app-footer">
    <p>
      <img src="maximalista.png" alt="Maximalista Logo">
      Telex es software libre bajo licencia
      <a href="https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12" target="_blank" rel="noopener">EUPL v1.2</a>
      creado por <a href="https://maximalista.coop" target="_blank" rel="noopener">Compañía Maximalista S.Coop.</a>
    </p>
</div>

<script>
    function openTab(evt, tabName) {
        if (evt && typeof evt.preventDefault === 'function') {
            evt.preventDefault();
        }

        var target = document.getElementById(tabName);
        if (!target) {
            var fallbackSection = document.querySelector('.tab-content');
            if (!fallbackSection) {
                return;
            }
            tabName = fallbackSection.id;
            target = fallbackSection;
        }

        var inputs = document.querySelectorAll('input[name="active_tab"]');
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].value = tabName;
        }

        var sections = document.getElementsByClassName('tab-content');
        for (var j = 0; j < sections.length; j++) {
            sections[j].classList.toggle('active', sections[j].id === tabName);
        }

        var buttons = document.getElementsByClassName('tab-link');
        for (var k = 0; k < buttons.length; k++) {
            var btnTab = buttons[k].getAttribute('data-tab') || '';
            if (btnTab === tabName) {
                buttons[k].classList.add('active');
            } else {
                buttons[k].classList.remove('active');
            }
        }

        try {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
        } catch (e) {
            // ignore URL update issues in older browsers
        }

        return false;
    }

    var addSourceBtn = document.getElementById('add-source-btn');
    if (addSourceBtn) {
        addSourceBtn.addEventListener('click', function () {
            var list = document.getElementById('sources-list');
            if (!list) {
                return;
            }
            var newItem = document.createElement('div');
            newItem.className = 'form-group source-item';
            newItem.innerHTML = `
                <input type="text" name="source_name[]" placeholder="Nombre de la fuente">
                <input type="text" name="source_url[]" placeholder="URL de la fuente">
                <button type="button" onclick="this.parentElement.remove()" class="button reject" style="padding: 0.6rem 0.8rem;">X</button>
            `;
            list.appendChild(newItem);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var urlParams = new URLSearchParams(window.location.search);
        var activeTab = urlParams.get('tab') || <?php echo json_encode($active_tab); ?>;
        if (!document.getElementById(activeTab)) {
            var firstSection = document.querySelector('.tab-content');
            if (firstSection) {
                activeTab = firstSection.id;
            }
        }

        var buttons = document.getElementsByClassName('tab-link');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function (e) {
                var tab = this.getAttribute('data-tab');
                if (!tab) {
                    return;
                }
                if (e && typeof e.preventDefault === 'function') {
                    e.preventDefault();
                }
                openTab(e, tab);
            });
        }

        openTab(null, activeTab);
    });

    function toggleArchiveCheckboxes(checked) {
        var checkboxes = document.querySelectorAll('#archive-files-list input[type="checkbox"]');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = checked;
        }
    }

    function copyToClipboard(button) {
        var pre = button.nextElementSibling;
        var text = pre.textContent;
        navigator.clipboard.writeText(text).then(function() {
            button.textContent = 'Copiado!';
            setTimeout(function() {
                button.textContent = 'Copiar';
            }, 2000);
        }, function(err) {
            console.error('Error al copiar: ', err);
        });
    }
</script>

</body>
</html>
