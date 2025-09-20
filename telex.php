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

$sugerencias_file     = $data_dir . '/sugerencias_pendientes.json';
$prompt_file          = $data_dir . '/prompt.txt';
$sources_file         = $data_dir . '/sources.json';
$rss_file             = __DIR__ . '/rss.xml';
$pm2_env_file         = $data_dir . '/pm2_env.json';

if (!function_exists('pm2_env_defaults')) {
    function pm2_env_defaults(string $rss_path): array {
        return [
            'GEMINI_API_KEY' => '',
            'GEMINI_MODEL' => 'gemini-1.5-flash-latest',
            'GOOGLE_TRANSLATE_API_KEY' => '',
            'TRANSLATOR_TARGET_LANG' => 'en',
            'TRANSLATOR_INTERVAL_MS' => '60000',
            'TELEGRAM_AUTO_SEND_ES' => '1',
            'PM2_BIN' => '',
            'INPUT_RSS' => $rss_path,
            'OUTPUT_RSS' => '',
        ];
    }
}

if (!function_exists('load_pm2_env')) {
    function load_pm2_env(string $path, array $defaults): array {
        if (!file_exists($path)) {
            $encoded = json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                @file_put_contents($path, $encoded . "\n");
            }
            return $defaults;
        }
        $raw = @file_get_contents($path);
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            $data = [];
        }
        return array_merge($defaults, $data);
    }
}

if (!function_exists('save_pm2_env')) {
    function save_pm2_env(string $path, array $env): bool {
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $encoded = json_encode($env, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }
        $encoded .= "\n";
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $encoded) === false) {
            return false;
        }
        if (@rename($tmp, $path)) {
            @chmod($path, 0664);
            return true;
        }
        $ok = @file_put_contents($path, $encoded);
        @unlink($tmp);
        if ($ok === false) {
            return false;
        }
        @chmod($path, 0664);
        return true;
    }
}

$env_defaults = pm2_env_defaults($rss_file);
$env_vars = load_pm2_env($pm2_env_file, $env_defaults);
$env_vars['INPUT_RSS'] = $rss_file;
$target_lang = strtolower($env_vars['TRANSLATOR_TARGET_LANG'] ?? 'en');
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
$rss_en_file          = __DIR__ . '/' . feed_filename_for_lang($target_lang, $feed_custom);
if (empty($env_vars['OUTPUT_RSS'])) {
    $env_vars['OUTPUT_RSS'] = $rss_en_file;
    save_pm2_env($pm2_env_file, $env_vars);
}
$worker_script        = __DIR__ . '/worker.js';
$translator_script    = __DIR__ . '/rss_translator.js';
$node_path            = '/usr/bin/node';
$examples_file        = $data_dir . '/examples.json';
$published_file       = $data_dir . '/published_messages.json';
$cache_titles_file    = $data_dir . '/.sent_titles_cache.json';
$titlekeys_file       = $data_dir . '/.sent_titlekeys_cache.json';
$gemini_log_file      = $data_dir . '/gemini_log.jsonl'; // <-- 1. RUTA DEL NUEVO LOG
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
ensure_file($rss_change_cache, json_encode(["hash" => ""], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n");
ensure_file($translation_cache, "{}\n");
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

if (!function_exists('tg_send')) {
    function tg_send($token, $chat_id, $title, $desc, $url, $photo_url = '') {
        $t = trim((string)$title); $d = trim((string)$desc); $u = trim((string)$url);
        $title_md = $t !== '' ? ('*' . escape_md($t) . '*') : '';
        $desc_md  = $d !== '' ? escape_md($d) : '';
        $url_md   = $u !== '' ? ('`' . $u . '`') : '';

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
        // description puede venir con CDATA/HTML: convertir a texto simple
        $desc_text = trim(html_entity_decode(strip_tags($desc_raw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
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
$pm2_detected = trim((string)@shell_exec('command -v pm2 2>/dev/null'));
if ($pm2_detected === '') { $pm2_detected = trim((string)@shell_exec('which pm2 2>/dev/null')); }
$active_tab = $_GET['tab'] ?? 'gemini';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active_tab = $_POST['active_tab'] ?? 'gemini';
    
    // 2.1 Recibir Telex (worker Node)
    if (isset($_POST['fetch_suggestions'])) {
        // <<<< --- TAREA 1: BORRAR EL LOG ANTERIOR --- >>>>
        @file_put_contents($gemini_log_file, '');

        $gemini_key = $env_vars['GEMINI_API_KEY'] ?? '';
        $gemini_model = $env_vars['GEMINI_MODEL'] ?? 'gemini-1.5-flash-latest';
        $command = "GEMINI_API_KEY='{$gemini_key}' GEMINI_MODEL='{$gemini_model}' {$node_path} {$worker_script} 2>&1";
        shell_exec($command);
        $message = "Búsqueda completada."; $message_type = 'success';
    }
    
    // Ejecutar traducción ahora (una vez)
    if (isset($_POST['run_translator_now'])) {
        $active_tab = 'traduccion';
        $gt_key = $env_vars['GOOGLE_TRANSLATE_API_KEY'] ?? '';
        $cmd = "GOOGLE_TRANSLATE_API_KEY='{$gt_key}' TRANSLATOR_TARGET_LANG='{$target_lang}' FORCE='1' INPUT_RSS='{$rss_file}' OUTPUT_RSS='{$rss_en_file}' RUN_ONCE='1' {$node_path} {$translator_script} 2>&1";
        $out = @shell_exec($cmd);
        if ($out === null) {
            $message = "No se pudo ejecutar el traductor. Verifica Node/permiso de ejecución.";
            $message_type = 'error';
        } else {
            $snippet = trim(substr($out, -200));
            $message = "Traducción ejecutada. Revisa rss_" . htmlspecialchars($target_lang) . ".xml" . ($snippet ? " — Log: " . htmlspecialchars($snippet) : "");
            $message_type = 'success';
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
        $finalMessage = ''; $decision = '';
        switch ($_POST['action']) {
            case 'approve': $finalMessage = $suggestion['summary']; $decision = 'enviada';   break;
            case 'edit':    $finalMessage = $_POST['summary'];       $decision = 'editada';  break;
            case 'reject':                                           $decision = 'descartada';   break;
        }
        // Añadir entrada al RSS (sin Telegram)
        if (!empty($finalMessage)) {
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

            $link = $suggestion['link'] ?? '';
            $guid = $link !== '' ? $link : ('telex:' . md5(($suggestion['title'] ?? '') . '|' . $finalMessage . '|' . microtime(true)));
            if (!isset($existingKeys[$guid])) {
                $item = $rss->channel->addChild('item');
                $derived = derive_title_from_summary($finalMessage);
                $item->addChild('title', htmlspecialchars($derived !== '' ? $derived : ($suggestion['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                if ($link) { $item->addChild('link', htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); }
                $item->addChild('guid', htmlspecialchars($guid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                $descNode = $item->addChild('description');
                $descNode[0] = null; // limpiar texto previo
                $descDom = dom_import_simplexml($descNode);
                if ($descDom) {
                    $ownerDoc = $descDom->ownerDocument;
                    $descDom->appendChild($ownerDoc->createCDATASection($finalMessage));
                } else {
                    $item->description = $finalMessage;
                }
                // Añadir content:encoded con el mismo HTML
                $contentNode = $item->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/');
                if ($contentNode) {
                    $contentDom = dom_import_simplexml($contentNode);
                    if ($contentDom) {
                        $ownerDoc = $contentDom->ownerDocument;
                        $contentDom->appendChild($ownerDoc->createCDATASection($finalMessage));
                    }
                }
                $item->addChild('pubDate', date(DATE_RSS));

                // Limitar a 200 ítems (si supera, recorta los más antiguos)
                $maxItems = 200;
                $items = $rss->channel->item;
                if (count($items) > $maxItems) {
                    $toRemove = count($items) - $maxItems;
                    // SimpleXML carece de unset directo fiable; reconstruimos
                    $newRss = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel></channel></rss>');
                    foreach ($rss->channel->children() as $child) {
                        if ($child->getName() !== 'item') {
                            $newRss->channel->addChild($child->getName(), (string)$child);
                        }
                    }
                    $i = 0;
                    foreach ($items as $it) {
                        if ($i++ < $toRemove) continue;
                        $ni = $newRss->channel->addChild('item');
                        foreach ($it->children() as $c) { $ni->addChild($c->getName(), (string)$c); }
                    }
                    $rss = $newRss;
                }

                // Guardar rss.xml con formato
                $dom = new DOMDocument('1.0', 'UTF-8');
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = true;
                $dom->loadXML($rss->asXML());
                $dom->save($rss_file);
            }

            $published[] = ['title' => $suggestion['title'], 'text' => $finalMessage, 'timestamp' => date('c')];
        }
        $examples[] = [ 'title' => $suggestion['title'], 'link'  => $suggestion['link'], 'decision' => $decision, 'resumen_original' => $suggestion['summary'], 'resumen_final'    => !empty($finalMessage) ? $finalMessage : $suggestion['summary'] ];
        $sent_titles[] = $suggestion['title'];
        $sent_titlekeys[] = title_key($suggestion['title']);
        if (!empty($_POST['edited_title'])) {
            $sent_titlekeys[] = title_key($_POST['edited_title']);
        }
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
                $dom = new DOMDocument('1.0'); $dom->preserveWhiteSpace = false; $dom->formatOutput = true; $dom->loadXML($new->asXML()); $dom->save($rss_file);
                $message = 'Todas las entradas eliminadas de rss.xml.'; $message_type = 'success';
            } else { $message = 'rss.xml mal formado.'; $message_type = 'error'; }
        } else { $message = 'No se puede escribir en rss.xml.'; $message_type = 'error'; }
    }
    // Mover item en RSS (↑/↓) limitado a ventana mostrada
    if (isset($_POST['move_rss'])) {
        $active_tab = 'rss';
        $dir = $_POST['dir'] ?? '';
        $idx = intval($_POST['idx'] ?? -1);
        if (file_exists($rss_file) && is_writable($rss_file) && ($dir==='up' || $dir==='down') && $idx>=0) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($rss_file);
            if ($xml && isset($xml->channel->item)) {
                $items = iterator_to_array($xml->channel->item);
                $a = $idx; $b = ($dir==='up') ? $idx-1 : $idx+1;
                if ($b>=0 && $b<count($items)) {
                    $tmp = $items[$a]; $items[$a] = $items[$b]; $items[$b] = $tmp;
                    // reconstruir
                    $new = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
                    foreach ($xml->channel->children() as $child) { if ($child->getName()!=='item') $new->channel->addChild($child->getName(), (string)$child); }
                    foreach ($items as $it) {
                        $ni = $new->channel->addChild('item');
                        if (isset($it->title)) $ni->addChild('title', (string)$it->title);
                        if (isset($it->link)) $ni->addChild('link', (string)$it->link);
                        if (isset($it->guid)) $ni->addChild('guid', (string)$it->guid);
                        if (isset($it->pubDate)) $ni->addChild('pubDate', (string)$it->pubDate);
                        if (isset($it->description)) { $d=$ni->addChild('description'); $d[0]=null; $dd=dom_import_simplexml($d); if($dd){$dd->appendChild($dd->ownerDocument->createCDATASection((string)$it->description));} }
                        $content = $it->children('http://purl.org/rss/1.0/modules/content/');
                        if (isset($content->encoded)) { $c=$ni->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/'); $cd=dom_import_simplexml($c); if($cd){$cd->appendChild($cd->ownerDocument->createCDATASection((string)$content->encoded));} }
                        if (isset($it->enclosure)) { $enc=$ni->addChild('enclosure'); foreach($it->enclosure->attributes() as $k=>$v){ $enc->addAttribute($k,(string)$v);} }
                    }
                    $dom = new DOMDocument('1.0'); $dom->preserveWhiteSpace=false; $dom->formatOutput=true; $dom->loadXML($new->asXML()); $dom->save($rss_file);
                }
            }
        }
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
    // Mover item en RSS traducido
    if (isset($_POST['move_rss_en'])) {
        $active_tab = 'traduccion';
        $dir = $_POST['en_dir'] ?? '';
        $idx = intval($_POST['en_idx'] ?? -1);
        if ((file_exists($rss_en_file) || is_writable(dirname($rss_en_file))) && ($dir==='up' || $dir==='down') && $idx>=0) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($rss_en_file);
            if ($xml && isset($xml->channel->item)) {
                $items = iterator_to_array($xml->channel->item);
                $a = $idx; $b = ($dir==='up') ? $idx-1 : $idx+1;
                if ($b>=0 && $b<count($items)) {
                    $tmp = $items[$a]; $items[$a] = $items[$b]; $items[$b] = $tmp;
                    $new = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel></channel></rss>');
                    foreach ($xml->channel->children() as $child) { if ($child->getName()!=='item') $new->channel->addChild($child->getName(), (string)$child); }
                    foreach ($items as $it) {
                        $ni = $new->channel->addChild('item');
                        if (isset($it->title)) $ni->addChild('title', (string)$it->title);
                        if (isset($it->link)) $ni->addChild('link', (string)$it->link);
                        if (isset($it->guid)) $ni->addChild('guid', (string)$it->guid);
                        if (isset($it->pubDate)) $ni->addChild('pubDate', (string)$it->pubDate);
                        if (isset($it->description)) { $d=$ni->addChild('description'); $d[0]=null; $dd=dom_import_simplexml($d); if($dd){$dd->appendChild($dd->ownerDocument->createCDATASection((string)$it->description));} }
                        $content = $it->children('http://purl.org/rss/1.0/modules/content/');
                        if (isset($content->encoded)) { $c=$ni->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/'); $cd=dom_import_simplexml($c); if($cd){$cd->appendChild($cd->ownerDocument->createCDATASection((string)$content->encoded));} }
                        if (isset($it->enclosure)) { $enc=$ni->addChild('enclosure'); foreach($it->enclosure->attributes() as $k=>$v){ $enc->addAttribute($k,(string)$v);} }
                    }
                    $dom = new DOMDocument('1.0'); $dom->preserveWhiteSpace=false; $dom->formatOutput=true; $dom->loadXML($new->asXML());
                    safe_dom_save($dom, $rss_en_file);
                }
            }
        }
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

    // Enviar un item individual a Telegram (forzar)
    if (isset($_POST['telegram_send_item'])) {
        $active_tab = $_POST['from_tab'] ?? 'telegram';
        $lang = strtolower(trim($_POST['lang'] ?? ''));
        $idx  = isset($_POST['idx']) ? intval($_POST['idx']) : (isset($_POST['telegram_send_item']) ? intval($_POST['telegram_send_item']) : -1);
        $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
        $bot  = $bots[$lang] ?? null;
        if (!$bot || (is_array($bot) && (empty($bot['token']) || empty($bot['chat_id'])))) {
            $message = 'Falta token o chat ID para el idioma ' . htmlspecialchars($lang) . '.'; $message_type = 'error';
        } else {
            $token = is_array($bot) ? $bot['token'] : (string)$bot;
            $chat  = is_array($bot) ? ($bot['chat_id'] ?? '') : '';
            $feed_file = __DIR__ . '/' . feed_filename_for_lang($lang, $feed_custom);
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
    // Añadir entrada manual (otras fuentes)
    if (isset($_POST['add_manual_item'])) {
        $active_tab = 'gemini';
        $title = trim($_POST['manual_title'] ?? '');
        $link  = trim($_POST['manual_link'] ?? '');
        $desc  = trim($_POST['manual_description'] ?? '');
        $imgUrlInput = trim($_POST['manual_image_url'] ?? '');
        $imagePathRel = '';

        // Subida de imagen (opcional)
        if (!empty($_FILES['manual_image']['name']) && is_uploaded_file($_FILES['manual_image']['tmp_name'])) {
            $fname = basename($_FILES['manual_image']['name']);
            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $safe = preg_replace('/[^a-zA-Z0-9._-]/','_', $fname);
                $uniq = uniqid('img_', true) . '.' . $ext;
                $dest = $img_dir . '/' . $uniq;
                if (@move_uploaded_file($_FILES['manual_image']['tmp_name'], $dest)) {
                    $imagePathRel = 'img/' . $uniq;
                }
            }
        }
        if (!$imagePathRel && $imgUrlInput !== '') {
            $imagePathRel = $imgUrlInput; // URL externa o ruta ya servida
        }

        // Cargar o crear rss.xml
        libxml_use_internal_errors(true);
        if (file_exists($rss_file)) { $rss = simplexml_load_file($rss_file); }
        else {
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

        if ($title !== '') {
            $guid = $link !== '' ? $link : ('telex:' . md5($title . '|' . $desc . '|' . microtime(true)));
            if (!isset($existingKeys[$guid])) {
                $htmlDesc = $desc;
                if ($imagePathRel !== '') {
                    $safeImg = htmlspecialchars($imagePathRel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $htmlDesc = '<p><img src="' . $safeImg . '" alt="" style="max-width:100%; height:auto;" /></p>' . $htmlDesc;
                }

                $item = $rss->channel->addChild('item');
                $item->addChild('title', htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                if ($link) { $item->addChild('link', htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); }
                $item->addChild('guid', htmlspecialchars($guid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

                // description CDATA
                $descNode = $item->addChild('description');
                $descNode[0] = null;
                $descDom = dom_import_simplexml($descNode);
                if ($descDom) {
                    $ownerDoc = $descDom->ownerDocument;
                    $descDom->appendChild($ownerDoc->createCDATASection($htmlDesc));
                } else { $item->description = $htmlDesc; }

                // content:encoded CDATA
                $contentNode = $item->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/');
                if ($contentNode) {
                    $contentDom = dom_import_simplexml($contentNode);
                    if ($contentDom) {
                        $ownerDoc = $contentDom->ownerDocument;
                        $contentDom->appendChild($ownerDoc->createCDATASection($htmlDesc));
                    }
                }

                // Enclosure (opcional) si la imagen es local o URL directa a imagen
                if ($imagePathRel !== '') {
                    $ext = strtolower(pathinfo(parse_url($imagePathRel, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                    $mime = 'image/jpeg';
                    if (in_array($ext, ['png'])) $mime = 'image/png';
                    elseif (in_array($ext, ['gif'])) $mime = 'image/gif';
                    elseif (in_array($ext, ['webp'])) $mime = 'image/webp';
                    $enclosure = $item->addChild('enclosure');
                    $enclosure->addAttribute('url', $imagePathRel);
                    $enclosure->addAttribute('type', $mime);
                }

                $item->addChild('pubDate', date(DATE_RSS));

                // Guardar rss.xml con formato
                $dom = new DOMDocument('1.0', 'UTF-8');
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = true;
                $dom->loadXML($rss->asXML());
                $dom->save($rss_file);

                $message = 'Entrada añadida a rss.xml.';
                $message_type = 'success';

                // Envío automático a Telegram (ES) si está activado y hay bot/chat configurados
                $auto = strtolower((string)($env_vars['TELEGRAM_AUTO_SEND_ES'] ?? '1'));
                $auto_enabled = !in_array($auto, ['0','false','off','no'], true);
                if ($auto_enabled) {
                    $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
                    $bot  = $bots['es'] ?? null;
                    if ($bot && (!is_array($bot) || (!empty($bot['token']) && !empty($bot['chat_id'])))) {
                        $token = is_array($bot) ? $bot['token'] : (string)$bot;
                        $chat  = is_array($bot) ? ($bot['chat_id'] ?? '') : '';
                        $desc_plain = trim(html_entity_decode(strip_tags($htmlDesc), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                        if ($token && $chat) {
                            $resp = tg_send($token, $chat, $title, $desc_plain, ($link ?? ''), ($imagePathRel ?? ''));
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
    }

    // Envío automático a Telegram al aprobar (ES) si está activado
    if (isset($_POST['action']) && ($_POST['action'] === 'approve' || $_POST['action'] === 'edit')) {
        // Nota: El bloque de aprobación anterior ya guardó en rss.xml
        $auto = strtolower((string)($env_vars['TELEGRAM_AUTO_SEND_ES'] ?? '1'));
        $auto_enabled = !in_array($auto, ['0','false','off','no'], true);
        if ($auto_enabled) {
            $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
            $bot  = $bots['es'] ?? null;
            if ($bot && (!is_array($bot) || (!empty($bot['token']) && !empty($bot['chat_id'])))) {
                $token = is_array($bot) ? $bot['token'] : (string)$bot;
                $chat  = is_array($bot) ? ($bot['chat_id'] ?? '') : '';
                // Cargar último item añadido (suponemos el más reciente por pubDate o al final)
                libxml_use_internal_errors(true);
                $xml = @simplexml_load_file($rss_file);
                if ($xml && isset($xml->channel->item)) {
                    // Tomar el último item
                    $items = iterator_to_array($xml->channel->item);
                    $it = end($items);
                    if ($it) {
                        $title = (string)($it->title ?? '');
                        $link  = (string)($it->link ?? '');
                        $guid  = (string)($it->guid ?? '');
                        $key   = $link !== '' ? $link : $guid;
                        $desc_plain = trim(html_entity_decode(strip_tags($finalMessage), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                        $text = trim($title . "\n\n" . $desc_plain . "\n\n" . ($link ?? ''));
                        if ($token && $chat && ($title !== '' || $desc_plain !== '' || ($link ?? '') !== '')) {
                            $resp = tg_send($token, $chat, $title, $desc_plain, ($link ?? ''), '');
                            // Registrar como enviado
                            $sent = file_exists($telegram_sent_file) ? (json_decode(@file_get_contents($telegram_sent_file), true) ?: []) : [];
                            if (!isset($sent['es'])) { $sent['es'] = []; }
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
        }
    }

    // Guardar configuración (pm2_env.json)
    if (isset($_POST['save_config'])) {
        $new_env = array_merge($env_defaults, $env_vars);
        $new_env['GEMINI_API_KEY'] = trim($_POST['gemini_api_key'] ?? '');
        $new_env['GEMINI_MODEL'] = trim($_POST['gemini_model'] ?? 'gemini-1.5-flash-latest');
        $new_env['GOOGLE_TRANSLATE_API_KEY'] = trim($_POST['google_translate_api_key'] ?? '');
        $new_env['TELEGRAM_AUTO_SEND_ES'] = isset($_POST['telegram_auto_send_es']) ? '1' : '0';
        if (isset($_POST['pm2_bin'])) { $new_env['PM2_BIN'] = trim($_POST['pm2_bin']); }
        if (!empty($_POST['translator_lang'])) { $new_env['TRANSLATOR_TARGET_LANG'] = strtolower(trim($_POST['translator_lang'])); }

        $new_target = strtolower($new_env['TRANSLATOR_TARGET_LANG'] ?? 'en');
        $rss_en_file = __DIR__ . '/' . feed_filename_for_lang($new_target, $feed_custom);
        $new_env['INPUT_RSS'] = $rss_file;
        $new_env['OUTPUT_RSS'] = $rss_en_file;
        if (!isset($new_env['TRANSLATOR_INTERVAL_MS']) || $new_env['TRANSLATOR_INTERVAL_MS'] === '') {
            $new_env['TRANSLATOR_INTERVAL_MS'] = '60000';
        }

        if (!save_pm2_env($pm2_env_file, $new_env)) {
            $message = 'No se pudo guardar la configuración.';
            $message_type = 'error';
        } else {
            $env_vars = $new_env;
            $target_lang = $new_target;
            $translated_lang_name = lang_name_es($target_lang);
            $message = "Configuración guardada.";
            $message_type = 'success';
        }
    }

    // Reiniciar PM2 (traductor RSS)
    if (isset($_POST['restart_pm2'])) {
        $active_tab = 'config';
        $pm2_bin = $env_vars['PM2_BIN'] ?? 'pm2';
        if (!preg_match('/^[A-Za-z0-9_\/.\-]+$/', $pm2_bin)) { $pm2_bin = 'pm2'; }
        $cmd = $pm2_bin . ' restart rss-translator 2>&1';
        $out = @shell_exec($cmd);
        if ($out === null) {
            $message = "No se pudo ejecutar PM2. Verifica permisos/PM2_BIN.";
            $message_type = 'error';
        } else {
            $message = "PM2 reiniciado (rss-translator).";
            $message_type = 'success';
        }
    }

    // Regenerar data/pm2_env.json y reiniciar PM2
    if (isset($_POST['regen_pm2_env_restart'])) {
        $active_tab = 'config';
        // Regenerar pm2_env.json con valores actuales
        $current_target = strtolower($env_vars['TRANSLATOR_TARGET_LANG'] ?? $target_lang ?? 'en');
        $pm2_env = array_merge($env_defaults, $env_vars);
        $pm2_env['INPUT_RSS'] = $rss_file;
        $pm2_env['OUTPUT_RSS'] = __DIR__ . '/' . feed_filename_for_lang($current_target, $feed_custom);
        if (!isset($pm2_env['TRANSLATOR_INTERVAL_MS']) || $pm2_env['TRANSLATOR_INTERVAL_MS'] === '') {
            $pm2_env['TRANSLATOR_INTERVAL_MS'] = '60000';
        }
        $ok = save_pm2_env($pm2_env_file, $pm2_env);
        if (!$ok) {
            $message = 'No se pudo escribir data/pm2_env.json.'; $message_type = 'error';
        } else {
            $pm2_bin = $env_vars['PM2_BIN'] ?? 'pm2';
            if (!preg_match('/^[A-Za-z0-9_\/.\-]+$/', $pm2_bin)) { $pm2_bin = 'pm2'; }
            $cmd = $pm2_bin . ' restart rss-translator 2>&1';
            $out = @shell_exec($cmd);
            if ($out === null) {
                $message = 'pm2_env.json regenerado, pero no se pudo ejecutar PM2.'; $message_type = 'error';
            } else {
                $message = 'pm2_env.json regenerado y PM2 reiniciado (rss-translator).'; $message_type = 'success';
            }
        }
    }

    // Gestionar bots de Telegram por idioma
    if (isset($_POST['add_telegram_bot'])) {
        $active_tab = 'config';
        $lang  = strtolower(trim($_POST['telegram_lang'] ?? ''));
        $token = trim($_POST['telegram_token'] ?? '');
        $chat  = trim($_POST['telegram_chatid'] ?? '');
        $bots = file_exists($telegram_tokens_file) ? (json_decode(@file_get_contents($telegram_tokens_file), true) ?: []) : [];
        if ($lang === '') {
            $message = 'Selecciona un idioma para el bot de Telegram.'; $message_type = 'error';
        } else if ($token === '') {
            $message = 'Introduce el token del bot de Telegram.'; $message_type = 'error';
        } else if (!preg_match('/^[0-9]{6,}:[A-Za-z0-9_-]{10,}$/', $token)) {
            // Validación simple del formato de token
            $message = 'Token de Telegram con formato no reconocido.'; $message_type = 'error';
        } else {
            // Validación ligera de chat id: @canal o id numérico (p.ej. -100...)
            if ($chat !== '' && !preg_match('/^@[A-Za-z0-9_]{3,}$/', $chat) && !preg_match('/^-?\d{5,}$/', $chat)) {
                $message = 'Chat ID no válido. Usa @canal o un ID numérico.'; $message_type = 'error';
                header("Location: telex.php?tab=" . urlencode($active_tab) . "&message=" . urlencode($message) . "&message_type=" . urlencode($message_type));
                exit;
            }
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
        } else if ($chat !== '' && !preg_match('/^@[A-Za-z0-9_]{3,}$/', $chat) && !preg_match('/^-?\d{5,}$/', $chat)) {
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
        $test_key   = $_POST['gemini_api_key'] ?? ($env_vars['GEMINI_API_KEY'] ?? '');
        $test_model = $_POST['gemini_model']   ?? ($env_vars['GEMINI_MODEL']   ?? 'gemini-1.5-flash-latest');
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
        $tr_key = $_POST['google_translate_api_key'] ?? ($env_vars['GOOGLE_TRANSLATE_API_KEY'] ?? '');
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
            $new_env = array_merge($env_defaults, $env_vars);
            $new_env['TRANSLATOR_TARGET_LANG'] = $new_lang;
            $new_env['OUTPUT_RSS'] = __DIR__ . '/' . feed_filename_for_lang($new_lang, $feed_custom);
            $new_env['INPUT_RSS'] = $rss_file;
            if (!isset($new_env['TRANSLATOR_INTERVAL_MS']) || $new_env['TRANSLATOR_INTERVAL_MS'] === '') {
                $new_env['TRANSLATOR_INTERVAL_MS'] = '60000';
            }
            if (!save_pm2_env($pm2_env_file, $new_env)) {
                $message = 'No se pudo actualizar la configuración.'; $message_type = 'error';
            } else {
                $env_vars = $new_env;
                $target_lang = $new_lang;
                $translated_lang_name = lang_name_es($target_lang);
                $rss_en_file = __DIR__ . '/' . feed_filename_for_lang($target_lang, $feed_custom);
                $message = 'Idioma de traducción actualizado a ' . htmlspecialchars($translated_lang_name) . '.'; $message_type = 'success';
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
        if (strtolower($current_file) === 'rss.xml') {
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
        $message = 'Personalización de feed guardada para ' . htmlspecialchars($lang) . '.'; $message_type = 'success';
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

$rss_items_actuales = [];
if (file_exists($rss_file)) {
    libxml_use_internal_errors(true);
    $rss_content = simplexml_load_file($rss_file);
    if ($rss_content && isset($rss_content->channel->item)) {
        foreach($rss_content->channel->item as $item) { $rss_items_actuales[] = $item; }
    }
}

$rss_en_items_actuales = [];
if (file_exists($rss_en_file)) {
    libxml_use_internal_errors(true);
    $comm_content = simplexml_load_file($rss_en_file);
    if ($comm_content && isset($comm_content->channel->item)) {
        foreach ($comm_content->channel->item as $item) { $rss_en_items_actuales[] = $item; }
    }
}
// Eliminado: lógica de envío a Communalia/Telegram

// <<<< --- TAREA 2: CARGAR Y PROCESAR EL LOG DE GEMINI --- >>>>
$gemini_logs = [];
if (file_exists($gemini_log_file)) {
    $log_content = file_get_contents($gemini_log_file);
    // Dividimos por saltos de línea y filtramos líneas vacías
    $log_lines = array_filter(explode("\n", trim($log_content)));
    foreach ($log_lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $gemini_logs[] = $decoded;
        }
    }
    // Mostramos los logs más recientes primero
    $gemini_logs = array_reverse($gemini_logs);
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

        // Calcular pendientes y preparar listado (limit 20)
        $pending = [];
        $all = [];
        $idx = 0;
        foreach ($xml->channel->item as $it) {
            $title = (string)($it->title ?? '');
            $link  = (string)($it->link ?? '');
            $guid  = (string)($it->guid ?? '');
            $key   = $link !== '' ? $link : $guid;
            $row = ['idx'=>$idx, 'title'=>$title, 'link'=>$link, 'sent'=> !empty($telegram_sent[$lc][$key])];
            if (empty($telegram_sent[$lc][$key])) { $pending[] = $row; }
            $all[] = $row;
            $idx++;
            if ($idx >= 200) break; // no cargar demasiados
        }
        $telegram_langs_data[] = [
            'lang' => $lc,
            'filename' => $fname,
            'pending' => $pending,
            'all' => array_slice($all, 0, 20),
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
    <style>
        :root { --primary-color: #0d6efd; --success-color: #198754; --danger-color: #dc3545; --light-gray: #f8f9fa; --gray: #dee2e6; --dark-gray: #212529; --font-sans-serif: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", "Noto Sans", "Liberation Sans", Arial, sans-serif; }
        body { font-family: var(--font-sans-serif); margin: 0; background-color: var(--light-gray); color: var(--dark-gray); line-height: 1.6; }
        .container { max-width: 900px; margin: 2rem auto; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1, h2 { border-bottom: 1px solid var(--gray); padding-bottom: 0.5rem; margin-top: 0; margin-bottom: 1.5rem; font-weight: 500; }
        .item { border: 1px solid var(--gray); padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 5px; background: #fff; }
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
        .tabs { display: flex; flex-wrap: wrap; border-bottom: 1px solid var(--gray); margin-bottom: 1.5rem; }
        .tab-link { padding: 0.75rem 1.5rem; cursor: pointer; background: transparent; border: none; border-bottom: 3px solid transparent; font-size: 1rem; }
        .tab-link.active { border-bottom-color: var(--primary-color); font-weight: 600; }
        .tab-content { display: none; }
        .source-item { display: flex; gap: 1rem; margin-bottom: 0.5rem; align-items: center; }
        .source-item input[name="source_name[]"] { flex-basis: 30%; }
        .source-item input[name="source_url[]"] { flex-basis: 60%; }
        .logout-form { position: absolute; top: 1rem; right: 2rem; }
        .special-elite-regular {font-family: "Special Elite", system-ui; font-weight: 400;font-style: normal; font-size:2.4em; color:#0d6efd; padding:16px; padding-top:0px; }
        
        /* <<<< --- TAREA 2: ESTILOS PARA LA PESTAÑA DE LOG --- >>>> */
        .log-entry { font-family: monospace; font-size: 0.9em; }
        .log-entry summary { cursor: pointer; font-weight: bold; margin-bottom: 0.5rem; }
        .log-entry pre { background-color: #f0f0f0; padding: 0.75rem; border-radius: 4px; white-space: pre-wrap; word-break: break-all; max-height: 300px; overflow-y: auto; }
        .log-entry strong { color: var(--primary-color); }
        .log-entry .error { color: var(--danger-color); font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <form method="get" class="logout-form">
        <button type="submit" name="logout" value="1" class="button reject">Salir</button>
    </form>
    
    <img src="telex.png" alt="Telex" style="float:right; width:60px; height:auto; object-fit:contain;" />
    <h1 class="special-elite-regular">Telex</h1>

    <?php if ($message): ?>
        <div class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab-link" onclick="openTab(event, 'gemini')">Telex</button>
        <button class="tab-link" onclick="openTab(event, 'rss')">RSS</button>
        <button class="tab-link" onclick="openTab(event, 'traduccion')">Traducción</button>
        <?php $has_telegram = !empty($telegram_bots); if ($has_telegram): ?>
            <button class="tab-link" onclick="openTab(event, 'telegram')">Telegram</button>
        <?php endif; ?>
        <button class="tab-link" onclick="openTab(event, 'config')">Configuración</button>
        <button class="tab-link" onclick="openTab(event, 'prompt')">Prompt</button>
        <button class="tab-link" onclick="openTab(event, 'sources')">Fuentes</button>
        <button class="tab-link" onclick="openTab(event, 'gemini_log')">Log</button>
    </div>

    <div id="gemini" class="tab-content">
        <div class="item">
            <h2>Acciones</h2>
            <form method="post">
                <input type="hidden" name="active_tab" value="gemini">
                <button type="submit" name="fetch_suggestions" class="button">📡 Recibir Telex</button>
            </form>
        </div>
        
        <div id="suggestions-container">
            <h2>Telex Pendientes</h2>
            <?php if (!empty($sugerencias_pendientes)): ?>
                <?php foreach ($sugerencias_pendientes as $sug): ?>
                    <div class="item">
                        <h3><?php echo htmlspecialchars($sug['title']); ?></h3>
                        <form method="post">
                            <input type="hidden" name="active_tab" value="gemini">
                            <input type="hidden" name="suggestion_id" value="<?php echo htmlspecialchars($sug['id']); ?>">
                            <div class="form-group"><label>Resumen Sugerido:</label><textarea name="summary"><?php echo htmlspecialchars($sug['summary']); ?></textarea></div>
                            <div class="form-group"><p><strong>Enlace:</strong> <a href="<?php echo htmlspecialchars($sug['link']); ?>" target="_blank" rel="noopener noreferrer">Ver noticia original</a></p></div>
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

    <div id="rss" class="tab-content">
        <h2>Editar `rss.xml`</h2>
        <form class="item" method="post">
            <input type="hidden" name="active_tab" value="rss">
            <input type="hidden" id="move_dir" name="dir" value="">
            <input type="hidden" id="move_idx" name="idx" value="">
            <?php $__i=0; if (!empty($rss_items_actuales)): foreach(array_slice($rss_items_actuales, 0, 32) as $item): ?>
                <div class="item">
                    <input type="hidden" name="rss_guid[]" value="<?php echo htmlspecialchars((string)$item->guid); ?>">
                    <input type="hidden" name="rss_date[]" value="<?php echo htmlspecialchars((string)$item->pubDate); ?>">
                    <div class="form-group item-row"><label style="min-width:60px;">Título:</label><input class="grow" type="text" name="rss_title[]" value="<?php echo htmlspecialchars((string)$item->title); ?>">
                        <div class="controls" style="display:flex; align-items:center; gap:.5rem;">
                            <button type="submit" name="move_rss" value="1" class="button" style="padding:.2rem .6rem;" onclick="document.getElementById('move_dir').value='up'; document.getElementById('move_idx').value='<?php echo $__i; ?>'">↑</button>
                            <button type="submit" name="move_rss" value="1" class="button" style="padding:.2rem .6rem;" onclick="document.getElementById('move_dir').value='down'; document.getElementById('move_idx').value='<?php echo $__i; ?>'">↓</button>
                            <label style="font-weight:400;"><input type="checkbox" name="rss_delete[]" value="<?php echo $__i; ?>"> Eliminar</label>
                        </div>
                    </div>
                    <div class="form-group"><label>URL:</label><input type="text" name="rss_url[]" value="<?php echo htmlspecialchars((string)$item->link); ?>"></div>
                    <div class="form-group"><label>Descripción:</label><textarea name="rss_description[]"><?php echo htmlspecialchars((string)$item->description); ?></textarea></div>
                    <?php
                        $auto = strtolower((string)($env_vars['TELEGRAM_AUTO_SEND_ES'] ?? '1'));
                        $auto_enabled = !in_array($auto, ['0','false','off','no'], true);
                        $has_es_bot = !empty($telegram_bots['es']) && (!is_array($telegram_bots['es']) || (!empty($telegram_bots['es']['token']) && !empty($telegram_bots['es']['chat_id'])));
                        if ($has_es_bot && !$auto_enabled): ?>
                            <div style="margin-top:.5rem;">
                                <input type="hidden" name="from_tab" value="rss">
                                <input type="hidden" name="lang" value="es">
                                <button type="submit" name="telegram_send_item" value="<?php echo $__i; ?>" class="button" style="padding:.3rem .6rem;">Enviar a Telegram</button>
                            </div>
                    <?php endif; ?>
                </div>
            <?php $__i++; endforeach; else: ?><p>No se pudo cargar el fichero rss.xml o está vacío.</p><?php endif; ?>
            <div class="button-group">
                <button type="submit" name="save_rss" class="button approve">Guardar Cambios en RSS</button>
                <button type="submit" name="delete_rss_selected" class="button reject">Eliminar seleccionados</button>
                <button type="submit" name="delete_rss_all" class="button" onclick="return confirm('¿Eliminar todas las entradas de rss.xml?');">Eliminar todos</button>
            </div>
        </form>
    </div>

    <div id="traduccion" class="tab-content">
        <h2>Traducción: rss_<?php echo htmlspecialchars($target_lang); ?>.xml</h2>
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
                <small>Idioma al que traducir ahora. La traducción se guardará en rss_<?php echo htmlspecialchars($target_lang); ?>.xml</small>
            </div>
            <button type="submit" name="set_translator_lang" class="button">Guardar idioma de traducción</button>
        </form>
        <form class="item" method="post" style="margin-bottom:1rem;">
            <input type="hidden" name="active_tab" value="traduccion">
            <p>Genera ahora mismo la traducción de rss.xml a rss_<?php echo htmlspecialchars($target_lang); ?>.xml con Google Translate (una sola ejecución).</p>
            <button type="submit" name="run_translator_now" class="button">Forzar traducción ahora</button>
        </form>
        <form class="item" method="post">
            <input type="hidden" name="active_tab" value="traduccion">
            <input type="hidden" id="en_move_dir" name="en_dir" value="">
            <input type="hidden" id="en_move_idx" name="en_idx" value="">
            <?php $__j=0; if (!empty($rss_en_items_actuales)): foreach(array_slice($rss_en_items_actuales, 0, 20) as $item): ?>
                <div class="item">
                    <div class="form-group item-row"><label style="min-width:60px;">Título (<?php echo htmlspecialchars($translated_lang_name); ?>):</label><input class="grow" type="text" name="rss_en_title[]" value="<?php echo htmlspecialchars((string)$item->title); ?>">
                        <div class="controls" style="display:flex; align-items:center; gap:.5rem;">
                            <button type="submit" name="move_rss_en" value="1" class="button" style="padding:.2rem .6rem;" onclick="document.getElementById('en_move_dir').value='up'; document.getElementById('en_move_idx').value='<?php echo $__j; ?>'">↑</button>
                            <button type="submit" name="move_rss_en" value="1" class="button" style="padding:.2rem .6rem;" onclick="document.getElementById('en_move_dir').value='down'; document.getElementById('en_move_idx').value='<?php echo $__j; ?>'">↓</button>
                            <label style="font-weight:400;"><input type="checkbox" name="rss_en_delete[]" value="<?php echo $__j; ?>"> Eliminar</label>
                        </div>
                    </div>
                    <div class="form-group"><label>URL:</label><input type="text" name="rss_en_url[]" value="<?php echo htmlspecialchars((string)$item->link); ?>"></div>
                    <div class="form-group"><label>Descripción (<?php echo htmlspecialchars($translated_lang_name); ?>):</label><textarea name="rss_en_description[]" rows="4"><?php echo htmlspecialchars((string)$item->description); ?></textarea></div>
                    <?php $tg_lang_key = strtolower($target_lang); if (!empty($telegram_bots[$tg_lang_key]) && (!is_array($telegram_bots[$tg_lang_key]) || (!empty($telegram_bots[$tg_lang_key]['token']) && !empty($telegram_bots[$tg_lang_key]['chat_id'])))): ?>
                        <div style="margin-top:.5rem;">
                            <input type="hidden" name="from_tab" value="traduccion">
                            <input type="hidden" name="lang" value="<?php echo htmlspecialchars($tg_lang_key); ?>">
                            <button type="submit" name="telegram_send_item" value="<?php echo $__j; ?>" class="button" style="padding:.3rem .6rem;">Enviar a Telegram</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php $__j++; endforeach; else: ?><p>No se pudo cargar el fichero rss_en.xml o está vacío.</p><?php endif; ?>
            <div class="button-group">
                <button type="submit" name="save_rss_en" class="button approve">Guardar rss_<?php echo htmlspecialchars($target_lang); ?>.xml</button>
                <button type="submit" name="delete_rss_en_selected" class="button reject">Eliminar seleccionados</button>
                <button type="submit" name="delete_rss_en_all" class="button" onclick="return confirm('¿Eliminar todas las entradas de rss_<?php echo htmlspecialchars($target_lang); ?>.xml?');">Eliminar todos</button>
            </div>
        </form>
    </div>

    <?php $has_telegram = !empty($telegram_bots); if (!empty($has_telegram)): ?>
    <div id="telegram" class="tab-content">
        <h2>Telegram</h2>
        <?php if (!empty($telegram_langs_data)): foreach ($telegram_langs_data as $tg): ?>
            <div class="item">
                <h3 style="margin-top:0;">Canal <?php echo htmlspecialchars(lang_name_es($tg['lang'])); ?> (<?php echo htmlspecialchars($tg['lang']); ?>)</h3>
                <p>Feed: <code><?php echo htmlspecialchars($tg['filename']); ?></code> — Pendientes: <strong><?php echo count($tg['pending']); ?></strong></p>
                <form method="post" style="margin-bottom: .8rem;">
                    <input type="hidden" name="active_tab" value="telegram">
                    <input type="hidden" name="lang" value="<?php echo htmlspecialchars($tg['lang']); ?>">
                    <button type="submit" name="telegram_send_all" class="button approve">Enviar pendientes</button>
                </form>
                <div>
                    <p style="margin:.5rem 0 .3rem;">Últimos items</p>
                    <?php foreach ($tg['all'] as $row): ?>
                        <div class="form-group item-row" style="align-items:center; gap:.5rem;">
                            <span class="grow"><strong><?php echo htmlspecialchars($row['title']); ?></strong><br><small><?php echo htmlspecialchars($row['link']); ?></small><?php echo $row['sent']? ' — <em style="color:#198754;">enviado</em>':''; ?></span>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="active_tab" value="telegram">
                                <input type="hidden" name="from_tab" value="telegram">
                                <input type="hidden" name="lang" value="<?php echo htmlspecialchars($tg['lang']); ?>">
                                <input type="hidden" name="idx" value="<?php echo intval($row['idx']); ?>">
                                <button type="submit" name="telegram_send_item" class="button" style="padding:.3rem .6rem;">Enviar este</button>
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

    <div id="config" class="tab-content">
        <h2>Configuración</h2>
        <div class="item" style="margin-bottom:1rem;">
            <h3 style="margin-top:0;">Estado del traductor</h3>
            <p>
                <?php
                    function fmt_mtime($p) { return file_exists($p) ? date('Y-m-d H:i:s', filemtime($p)) : '—'; }
                    echo 'rss.xml: <strong>' . htmlspecialchars(fmt_mtime($rss_file)) . "</strong><br>";
                    echo 'rss_' . htmlspecialchars($target_lang) . '.xml: <strong>' . htmlspecialchars(fmt_mtime($rss_en_file)) . "</strong><br>";
                    echo 'translation_cache.json: <strong>' . htmlspecialchars(fmt_mtime($translation_cache)) . "</strong><br>";
                    echo 'rss_change_cache.json: <strong>' . htmlspecialchars(fmt_mtime($rss_change_cache)) . "</strong>";
                ?>
            </p>
        </div>
        <form class="item" method="post">
            <input type="hidden" name="active_tab" value="config">
            <div class="form-group">
                <label>Gemini API Key</label>
                <input type="text" name="gemini_api_key" value="<?php echo htmlspecialchars($env_vars['GEMINI_API_KEY'] ?? ''); ?>" placeholder="GEMINI_API_KEY">
            </div>
            <div class="form-group">
                <label>Gemini Model</label>
                <input type="text" id="gemini_model" name="gemini_model" value="<?php echo htmlspecialchars($env_vars['GEMINI_MODEL'] ?? 'gemini-1.5-flash-latest'); ?>" placeholder="gemini-1.5-flash-latest">
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
                <input type="text" name="google_translate_api_key" value="<?php echo htmlspecialchars($env_vars['GOOGLE_TRANSLATE_API_KEY'] ?? ''); ?>" placeholder="GOOGLE_TRANSLATE_API_KEY">
            </div>

            <div class="form-group">
                <label>Telegram (ES): enviar automáticamente al aprobar</label>
                <?php $auto_es = strtolower((string)($env_vars['TELEGRAM_AUTO_SEND_ES'] ?? '1')); $checked = !in_array($auto_es, ['0','false','off','no'], true) ? 'checked' : ''; ?>
                <label style="font-weight:400; display:inline-flex; align-items:center; gap:.4rem; margin-left:.5rem;">
                    <input type="checkbox" name="telegram_auto_send_es" value="1" <?php echo $checked; ?>> Sí
                </label>
                <small>Si hay un bot y Chat ID configurados para español, enviará al canal al aprobar.</small>
            </div>
            
            <div class="form-group">
                <label>Ruta binario PM2 (opcional)</label>
                <input type="text" name="pm2_bin" value="<?php echo htmlspecialchars($env_vars['PM2_BIN'] ?? ''); ?>" placeholder="/usr/bin/pm2 o pm2">
                <small>Si el servidor web no tiene el PATH de pm2, indícalo aquí.</small>
                <?php if (empty($env_vars['PM2_BIN'] ?? '') && !empty($pm2_detected)): ?>
                    <div style="margin-top:.4rem;">
                        <small>Sugerido: <code><?php echo htmlspecialchars($pm2_detected); ?></code></small>
                        <button type="button" class="button" style="padding:.3rem .6rem; margin-left:.5rem;"
                                data-suggest="<?php echo htmlspecialchars($pm2_detected, ENT_QUOTES); ?>"
                                onclick="document.querySelector('input[name=pm2_bin]').value=this.dataset.suggest">
                            Usar sugerido
                        </button>
                    </div>
                <?php endif; ?>
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
            <p>Si cambiaste modelo o llaves, reinicia los procesos Node para aplicar cambios.</p>
            <button type="submit" name="restart_pm2" class="button">Reiniciar PM2 (rss-translator)</button>
        </form>
        <form class="item" method="post" style="margin-top:1rem;">
            <input type="hidden" name="active_tab" value="config">
            <p>Regenerar el fichero privado de entorno de PM2 y reiniciar el traductor.</p>
            <button type="submit" name="regen_pm2_env_restart" class="button approve">Regenerar data/pm2_env.json y reiniciar PM2</button>
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

    <div id="prompt" class="tab-content">
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

    <div id="sources" class="tab-content">
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
    </div>
    
    <div id="gemini_log" class="tab-content">
        <h2>Log de Peticiones a Gemini</h2>
        <div class="item">
        <?php if (!empty($gemini_logs)): ?>
            <?php foreach ($gemini_logs as $log): ?>
                <div class="log-entry item">
                    <p>
                        <strong>Time:</strong> <?php echo htmlspecialchars($log['timestamp'] ?? 'N/A'); ?><br>
                        <strong>Title:</strong> <?php echo htmlspecialchars($log['title'] ?? 'N/A'); ?>
                    </p>
                    <?php if (isset($log['error'])): ?>
                        <p class="error">ERROR: <?php echo htmlspecialchars($log['error']); ?></p>
                    <?php else: ?>
                        <p><strong>Respuesta:</strong> <?php echo htmlspecialchars(substr($log['response'] ?? '', 0, 150)); ?>...</p>
                    <?php endif; ?>
                    <details>
                        <summary>Ver Prompt Completo (<?php echo htmlspecialchars($log['promptLength'] ?? 'N/A'); ?> caracteres)</summary>
                        <pre><?php echo htmlspecialchars($log['prompt'] ?? 'Prompt no disponible.'); ?></pre>
                    </details>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>El log de Gemini está vacío. Pulsa "Recibir Telex" para generar uno.</p>
        <?php endif; ?>
        </div>
    </div>

    <p style="text-align:center; font-size:12px; color:#6c757d; margin-top:16px;">
      Telex es software libre bajo licencia
      <a href="https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12" target="_blank" rel="noopener">EUPL v1.2</a>
      creado por <a href="https://maximalista.coop" target="_blank" rel="noopener">Compañía Maximalista S.Coop.</a>
    </p>
</div>

<script>
    function openTab(evt, tabName) {
        let i, tabcontent, tablinks;
        document.querySelectorAll('input[name="active_tab"]').forEach(input => input.value = tabName);
        
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; }
        
        tablinks = document.getElementsByClassName("tab-link");
        for (i = 0; i < tablinks.length; i++) { tablinks[i].className = tablinks[i].className.replace(" active", ""); }
        
        document.getElementById(tabName).style.display = "block";
        evt.currentTarget.className += " active";
    }

    document.getElementById('add-source-btn').addEventListener('click', function() {
        const list = document.getElementById('sources-list');
        const newItem = document.createElement('div');
        newItem.className = 'form-group source-item';
        newItem.innerHTML = `
            <input type="text" name="source_name[]" placeholder="Nombre de la fuente">
            <input type="text" name="source_url[]" placeholder="URL de la fuente">
            <button type="button" onclick="this.parentElement.remove()" class="button reject" style="padding: 0.6rem 0.8rem;">X</button>
        `;
        list.appendChild(newItem);
    });

    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab') || 'gemini';
        
        const tabButtons = document.querySelectorAll('.tab-link');
        let tabToActivate = document.querySelector('.tab-link');
        tabButtons.forEach(button => {
            if (button.getAttribute('onclick').includes("'" + activeTab + "'")) {
                tabToActivate = button;
            }
        });
        tabToActivate.click();
    });
</script>

</body>
</html>
