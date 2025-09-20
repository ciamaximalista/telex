// rss_translator.js — traduce rss.xml → rss_en.xml (EN), robusto y compatible ESM
// Requisitos: npm i axios xml2js rss he
// ENV recomendadas (PM2):
//   INPUT_RSS=/var/www/html/maximalismo/centro/feed/rss.xml
//   OUTPUT_RSS=/var/www/html/maximalismo/centro/feed/rss_en.xml
//   GOOGLE_TRANSLATE_API_KEY=...
//   TRANSLATOR_INTERVAL_MS=60000

import fs from 'fs';
import fsp from 'fs/promises';
import path from 'path';
import crypto from 'crypto';
import axios from 'axios';
import { parseStringPromise } from 'xml2js';
import RSS from 'rss';
import he from 'he';

process.umask(0o002);

// Cargar variables de entorno desde un fichero JSON (por defecto data/pm2_env.json)
function loadEnvFromFile() {
  try {
    const envFile = process.env.ENV_FILE || path.join(process.cwd(), 'data', 'pm2_env.json');
    if (fs.existsSync(envFile)) {
      const raw = fs.readFileSync(envFile, 'utf8');
      const json = JSON.parse(raw);
      // No sobrescribir lo ya definido en process.env
      for (const [k, v] of Object.entries(json)) {
        if (process.env[k] == null) process.env[k] = String(v);
      }
    }
  } catch (e) {
    console.warn('⚠️ No se pudo cargar ENV_FILE:', e.message);
  }
}
loadEnvFromFile();

const INPUT  = process.env.INPUT_RSS  || path.join(process.cwd(), 'rss.xml');
const TARGET_LANG = (process.env.TRANSLATOR_TARGET_LANG || 'en').toLowerCase();
const OUTPUT = process.env.OUTPUT_RSS || path.join(process.cwd(), `rss_${TARGET_LANG}.xml`);
const INTERVAL_MS = parseInt(process.env.TRANSLATOR_INTERVAL_MS || '60000', 10);

const DATA_DIR     = path.join(path.dirname(OUTPUT), 'data');
const CHANGE_CACHE = path.join(DATA_DIR, 'rss_change_cache.json');
const TRANS_CACHE  = path.join(DATA_DIR, 'translation_cache.json');

const API_KEY = process.env.GOOGLE_TRANSLATE_API_KEY || '';
const FORCE = process.env.FORCE === '1';

const LANGS_ES = {
  'af': 'afrikáans', 'sq': 'albanés', 'am': 'amárico', 'ar': 'árabe', 'hy': 'armenio', 'as': 'asamés', 'ay': 'aimara', 'az': 'azerbaiyano',
  'bm': 'bambara', 'eu': 'euskera', 'be': 'bielorruso', 'bn': 'bengalí', 'bho': 'bhojpurí', 'bs': 'bosnio', 'bg': 'búlgaro', 'ca': 'catalán',
  'ceb': 'cebuano', 'zh': 'chino', 'zh-cn': 'chino (simplificado)', 'zh-tw': 'chino (tradicional)', 'co': 'corso', 'hr': 'croata', 'cs': 'checo',
  'da': 'danés', 'dv': 'divehi', 'doi': 'dogri', 'nl': 'neerlandés', 'en': 'inglés', 'eo': 'esperanto', 'et': 'estonio', 'ee': 'ewé', 'fil': 'filipino',
  'fi': 'finés', 'fr': 'francés', 'fy': 'frisón', 'gl': 'gallego', 'ka': 'georgiano', 'de': 'alemán', 'el': 'griego', 'gn': 'guaraní', 'gu': 'guyaratí',
  'ht': 'criollo haitiano', 'ha': 'hausa', 'haw': 'hawaiano', 'he': 'hebreo', 'iw': 'hebreo', 'hi': 'hindi', 'hmn': 'hmong', 'hu': 'húngaro',
  'is': 'islandés', 'ig': 'igbo', 'ilo': 'ilocano', 'id': 'indonesio', 'ga': 'irlandés', 'it': 'italiano', 'ja': 'japonés', 'jv': 'javanés',
  'kn': 'canarés', 'kk': 'kazajo', 'km': 'jemer', 'rw': 'kinyarwanda', 'gom': 'konkani', 'ko': 'coreano', 'kri': 'krio', 'ku': 'kurdo (kurmanji)',
  'ckb': 'kurdo (sorani)', 'ky': 'kirguís', 'lo': 'lao', 'la': 'latín', 'lv': 'letón', 'ln': 'lingala', 'lt': 'lituano', 'lg': 'luganda', 'lb': 'luxemburgués',
  'mk': 'macedonio', 'mai': 'maithili', 'mg': 'malgache', 'ms': 'malayo', 'ml': 'malayalam', 'mt': 'maltés', 'mi': 'maorí', 'mr': 'maratí',
  'mni': 'meitei (manipuri)', 'lus': 'mizo', 'mn': 'mongol', 'my': 'birmano', 'ne': 'nepalí', 'no': 'noruego', 'or': 'odia (oriya)', 'om': 'oromo', 'ps': 'pastún',
  'fa': 'persa', 'pl': 'polaco', 'pt': 'portugués', 'pa': 'panyabí', 'qu': 'quechua', 'ro': 'rumano', 'ru': 'ruso', 'sm': 'samoano', 'sa': 'sánscrito',
  'gd': 'gaélico escocés', 'nso': 'sesotho del norte', 'st': 'sesotho del sur', 'sn': 'shona', 'sd': 'sindhi', 'si': 'cingalés', 'sk': 'eslovaco',
  'sl': 'esloveno', 'so': 'somalí', 'es': 'español', 'su': 'sundanés', 'sw': 'suajili', 'sv': 'sueco', 'tl': 'tagalo', 'tg': 'tayiko', 'ta': 'tamil',
  'tt': 'tártaro', 'te': 'telugu', 'th': 'tailandés', 'ti': 'tigriña', 'ts': 'tsonga', 'tr': 'turco', 'tk': 'turcomano', 'uk': 'ucraniano', 'ur': 'urdu',
  'ug': 'uigur', 'uz': 'uzbeko', 'vi': 'vietnamita', 'cy': 'galés', 'xh': 'xhosa', 'yi': 'yidis', 'yo': 'yoruba', 'zu': 'zulú'
};
const LANG_NAME_ES = LANGS_ES[TARGET_LANG] || TARGET_LANG.toUpperCase();

function sha256(s) {
  return crypto.createHash('sha256').update(String(s), 'utf8').digest('hex');
}
async function ensureDir(dir) {
  try { await fsp.mkdir(dir, { recursive: true }); } catch {}
}
function loadJSON(fp, fallback) {
  try { return fs.existsSync(fp) ? JSON.parse(fs.readFileSync(fp, 'utf8')) : fallback; }
  catch { return fallback; }
}
function saveJSON(fp, obj) {
  try { fs.writeFileSync(fp, JSON.stringify(obj, null, 2), 'utf8'); }
  catch (e) { console.warn('⚠️ Error guardando', fp, e.message); }
}
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function translate(text, fmt = 'text') {
  const src = he.decode(String(text ?? ''));
  if (!src.trim()) return '';
  if (!API_KEY) {
    // Sin API key: devolvemos tal cual (no rompemos el flujo)
    return src;
  }
  try {
    const { data } = await axios.post(
      'https://translation.googleapis.com/language/translate/v2',
      null,
      { params: { key: API_KEY, q: src, source: 'es', target: TARGET_LANG, format: fmt === 'html' ? 'html' : 'text' }, timeout: 20000 }
    );
    return he.decode(data?.data?.translations?.[0]?.translatedText ?? src);
  } catch (err) {
    console.error('❌ Error traduciendo:', err?.response?.data || err.message);
    return src; // fallback seguro
  }
}

function normalizeKey({ guid, link, title, pubDate }) {
  let k = (link && String(link).trim()) || (guid && String(guid).trim()) || '';
  if (!k) k = `${String(title||'').trim()}|${String(pubDate||'').trim()}`;
  try {
    if (/^https?:\/\//i.test(k)) {
      const u = new URL(k);
      if (u.pathname !== '/') k = k.replace(/\/+$/,'');
    } else {
      k = k.replace(/\/+$/,'');
    }
  } catch {}
  return k;
}
const toArray = (x) => Array.isArray(x) ? x : (x == null ? [] : [x]);

async function readSourceFeed() {
  const xml = await fsp.readFile(INPUT, 'utf8');
  const hash = sha256(xml);
  const cache = loadJSON(CHANGE_CACHE, { hash: '' });
  const outMissing = !fs.existsSync(OUTPUT);
  const changed = FORCE || outMissing || cache.hash !== hash;
  return { xml, hash, changed };
}

async function buildCommunalia(parsed, transCache) {
  const ch = parsed?.rss?.channel?.[0] || parsed?.rss?.channel || {};
  const srcTitle = Array.isArray(ch.title) ? ch.title[0] : (ch.title || 'Maximalismo Feed (ES)');
  const srcLink  = Array.isArray(ch.link)  ? ch.link[0]  : (ch.link  || 'https://maximalismo.org/feed/');
  const items    = toArray(ch.item);

  const feed = new RSS({
    title: `Maximalismo — Noticias (${LANG_NAME_ES})`,
    feed_url: srcLink.replace(/\/+$/,'') + `/rss_${TARGET_LANG}.xml`,
    site_url: srcLink,
    description: `Traducción al ${LANG_NAME_ES} del feed de noticias de Maximalismo.`,
    language: TARGET_LANG,
    pubDate: new Date(),
    custom_namespaces: { 'content': 'http://purl.org/rss/1.0/modules/content/' }
  });

  const updatedCache = { ...transCache };

  // Orden descendente (por si el origen no viniera ordenado)
  const normalized = items.map((it) => {
    const t = Array.isArray(it.title)       ? it.title[0]       : it.title;
    const d = Array.isArray(it.description) ? it.description[0] : it.description;
    const l = Array.isArray(it.link)        ? it.link[0]        : it.link;
    const g = Array.isArray(it.guid)        ? it.guid[0]        : it.guid;
    const p = Array.isArray(it.pubDate)     ? it.pubDate[0]     : it.pubDate;
    return { title: t, description: d, link: l, guid: g, pubDate: p };
  }).sort((a,b) => new Date(b.pubDate || 0) - new Date(a.pubDate || 0));

  for (const it of normalized) {
    const key = normalizeKey(it);
    if (!updatedCache[key]) updatedCache[key] = {};

    let titleEN = updatedCache[key].titleEN;
    let descEN  = updatedCache[key].descEN;

    if (!titleEN) {
      titleEN = await translate(it.title, 'text');
      updatedCache[key].titleEN = titleEN;
    }
    if (!descEN) {
      descEN = await translate(it.description, 'html');
      updatedCache[key].descEN = descEN;
    }

    const cleanTitle = he.decode(String(titleEN || ''));
    const cleanDescHtml = he.decode(String(descEN || ''));

    // rss_translator.js (CÓDIGO CORREGIDO)

feed.item({
    title: cleanTitle,
    url: it.link || undefined, // CORREGIDO: usa it.link
    guid: it.guid || key, // CORREGIDO: usa it.guid
    date: it.pubDate ? new Date(it.pubDate) : new Date(), // CORREGIDO: usa it.pubDate
    custom_elements: [
        { description: { _cdata: cleanDescHtml } },
        { 'content:encoded': { _cdata: cleanDescHtml } }
    ]
});


    // Pequeña pausa para no golpear la API si hay muchos ítems
    await sleep(50);
  }

  // Escribir de forma atómica
  await ensureDir(path.dirname(OUTPUT));
  const tmp = OUTPUT + '.tmp';
  await fsp.writeFile(tmp, feed.xml({ indent: true }), 'utf8');
  await fsp.rename(tmp, OUTPUT);
}

async function runOnce() {
  try {
    // Asegurar carpeta de trabajo
    await ensureDir(DATA_DIR);

    const { xml, hash, changed } = await readSourceFeed();
    if (!changed) {
      console.log(`ℹ️ rss.xml sin cambios (rss_${TARGET_LANG}.xml existe). Use FORCE=1 para forzar.`);
      return;
    }

    const parsed = await parseStringPromise(xml, {
      explicitArray: true,
      preserveChildrenOrder: true,
      trim: true
    });

    const transCache = loadJSON(TRANS_CACHE, {});
    await buildCommunalia(parsed, transCache);

    // Actualizar cachés
    saveJSON(CHANGE_CACHE, { hash });
    saveJSON(TRANS_CACHE, transCache);

    console.log(`✅ rss_${TARGET_LANG}.xml regenerado.`);
  } catch (e) {
    console.error('❌ Error en runOnce:', e.stack || e.message);
  }
}

function main() {
  const args = process.argv.slice(2);
  const runOnceFlag = process.env.RUN_ONCE === '1' || args.includes('--once');
  console.log('[RSS EN] Iniciado.');
  console.log('  INPUT :', INPUT);
  console.log('  OUTPUT:', OUTPUT);
  if (runOnceFlag) {
    console.log('  Modo   : una sola ejecución');
    runOnce().then(() => process.exit(0));
    return;
  }
  console.log('  Cada  :', INTERVAL_MS, 'ms');
  // Ejecución inicial + intervalo
  runOnce();
  setInterval(runOnce, INTERVAL_MS);
}

main();
