// worker.js - Versión final con FUSIÓN de sugerencias y logging a fichero

import fs from 'fs/promises';
import path from 'path';
import { fileURLToPath } from 'url';
import Parser from 'rss-parser';
import { GoogleGenerativeAI } from '@google/generative-ai';
import dotenv from 'dotenv';

dotenv.config();

// --- 1. CONFIGURACIÓN Y RUTAS DE FICHEROS ---
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const DATA_DIR = path.join(__dirname, 'data');
const EXAMPLES_FILE = path.join(DATA_DIR, 'examples.json');
const PROMPT_FILE = path.join(DATA_DIR, 'prompt.txt');
const SOURCES_FILE = path.join(DATA_DIR, 'sources.json');
const PENDING_SUGGESTIONS_FILE = path.join(DATA_DIR, 'sugerencias_pendientes.json');
const CACHE_TITLES_FILE = path.join(DATA_DIR, '.sent_titles_cache.json');
const GEMINI_LOG_FILE = path.join(DATA_DIR, 'gemini_log.jsonl');

const genAI = process.env.GEMINI_API_KEY ? new GoogleGenerativeAI(process.env.GEMINI_API_KEY) : null;
const GEMINI_MODEL = process.env.GEMINI_MODEL || 'gemini-1.5-flash-latest';
const model = genAI ? genAI.getGenerativeModel({ model: GEMINI_MODEL }) : null;
const parser = new Parser();

// --- 2. FUNCIONES DE AYUDA ---

function nowIso() {
    return new Date().toISOString();
}

async function logToGemini(logData) {
  const logLine = JSON.stringify({ timestamp: nowIso(), ...logData }) + '\n';
  try {
    await fs.appendFile(GEMINI_LOG_FILE, logLine);
  } catch (e) {
    console.error('Error al escribir el log de Gemini:', e.message);
  }
}

const loadFile = async (filePath, defaultValue) => {
    try {
        const content = await fs.readFile(filePath, 'utf-8');
        return content ? JSON.parse(content) : defaultValue;
    } catch (e) {
        return defaultValue;
    }
};

// <<< NUEVO: Función para eliminar duplicados de la lista de sugerencias >>>
function dedupeSuggestions(arr) {
    const map = new Map();
    // Usamos el link como clave única para evitar duplicados
    for (const s of arr) {
        if (s && s.link) {
            map.set(s.link.trim(), s);
        }
    }
    return Array.from(map.values());
}

function stripHtml(x = '') {
    return String(x).replace(/<[^>]+>/g, '');
}

function buildPrompt(item, examples, promptTemplate) {
    const lastExamples = examples.slice(-112).map(ex => `Título: "${ex.title}"\nResumen generado: ${ex.resumen_original}\nDecisión: ${ex.decision}\nResumen final: ${ex.resumen_final}`).join('\n\n');
    const rawDesc = item.contentSnippet || item.content || item.summary || item['content:encoded'] || '';
    const desc = stripHtml(rawDesc).slice(0, 1200); // Tamaño en caracteres de la descripción que mandamos a Gemini

    return promptTemplate
        .replace('{{examples}}', lastExamples)
        .replace('{{title}}', item.title || '')
        .replace('{{link}}', item.link || '')
        .replace('{{description}}', desc);
}

// --- 3. LÓGICA PRINCIPAL ---
async function findSuggestions() {
    console.error("--- INICIANDO BÚSQUEDA (worker.js) ---");

    await fs.mkdir(DATA_DIR, { recursive: true }).catch(() => {});

    // <<< CAMBIO 1: Cargar las sugerencias que ya existen ANTES de empezar >>>
    const existingSuggestions = await loadFile(PENDING_SUGGESTIONS_FILE, []);

    const sentTitles = new Set(await loadFile(CACHE_TITLES_FILE, []));
    const examples = await loadFile(EXAMPLES_FILE, []);
    const sources = await loadFile(SOURCES_FILE, []);
    const promptTemplate = await fs.readFile(PROMPT_FILE, 'utf-8').catch(() => '');
    
    if (!model || !promptTemplate) {
        console.error("ADVERTENCIA: Gemini no está configurado (API Key o prompt.txt faltan). No se generarán sugerencias.");
    }

    const newSuggestions = []; // Aquí guardaremos solo las nuevas

    for (const source of sources) {
        try {
            const feed = await parser.parseURL(source.url);
            for (const item of feed.items) {
                if (!item.title || !item.link || sentTitles.has(item.title)) {
                    continue;
                }
                
                let decisionText = 'IGNORAR';
                const prompt = buildPrompt(item, examples, promptTemplate);

                if (model && promptTemplate) {
                    try {
                        const result = await model.generateContent(prompt);
                        decisionText = (result.response?.text() || '').trim();
                        
                        await logToGemini({
                            title: item.title,
                            promptLength: prompt.length,
                            prompt,
                            response: decisionText
                        });

                    } catch (e) {
                        console.error(`  -> Error de Gemini en "${item.title}": ${e.message}`);
                        await logToGemini({
                            title: item.title,
                            promptLength: prompt.length,
                            prompt,
                            error: e.message
                        });
                    }
                }

                if (!decisionText.toUpperCase().startsWith('IGNORAR')) {
                    // Añadimos a la lista de NUEVAS sugerencias
                    newSuggestions.push({
                        id: `sug_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
                        title: item.title,
                        link: item.link,
                        summary: decisionText
                    });
                }
                sentTitles.add(item.title);
            }
        } catch (error) {
            console.error(`  -> Error procesando el feed ${source.name}: ${error.message}`);
        }
    }

    // <<< CAMBIO 2: Fusionar, deduplicar y guardar la lista combinada >>>
    const mergedSuggestions = existingSuggestions.concat(newSuggestions);
    const finalSuggestions = dedupeSuggestions(mergedSuggestions);

    await fs.writeFile(PENDING_SUGGESTIONS_FILE, JSON.stringify(finalSuggestions, null, 2));
    await fs.writeFile(CACHE_TITLES_FILE, JSON.stringify([...sentTitles]));
    
    console.error("--- BÚSQUEDA FINALIZADA ---");
}

findSuggestions().finally(() => {
    process.exit(0);
});
