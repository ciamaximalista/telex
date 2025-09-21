# Telex

- Telex es una app web ligera en PHP para:
  - Leer un grupo masivo de fuentes RSS y generar sugerencias de selección con Gemini (pestaña “Telex”).
  - Aprobar/editar y publicar un feed `rss.xml` en español.
  - Traducir automáticamente ese feed a `rss_<idioma>.xml` (por defecto `rss_en.xml`).
  - Editar ambos feeds desde la interfaz y gestionar fuentes, prompt, claves y procesos.

Estado: incluye integración opcional con Telegram (bots por idioma y envío automático/manual).

Características

- Pestañas de la interfaz (`telex.php`):
  - Telex: recibir sugerencias (Gemini), aprobar/editar/rechazar; añadir entradas manuales (“otras fuentes”).
  - RSS: editar `rss.xml` (mover ↑/↓, editar campos, eliminar seleccionados o todos).
  - Traducción: ver/editar `rss_<idioma>.xml`, cambiar idioma objetivo y “Forzar traducción ahora”.
  - Telegram: enviar entradas a canales de Telegram por idioma (pendientes o individuales).
  - Prompt: editar el prompt con variables `{{title}} {{description}} {{link}} {{examples}}`.
  - Fuentes: añadir/ordenar/eliminar feeds de entrada.
  - Log: visor del log JSONL de llamadas a Gemini.
  - Configuración: claves (`GEMINI_API_KEY`, `GOOGLE_TRANSLATE_API_KEY`), modelo (`GEMINI_MODEL`), pruebas/diagnóstico y ajustes del traductor. Incluye:
    - Bots de Telegram por idioma (token + Chat ID `@canal` o ID numérico) con listado y eliminación.
    - Opción “Telegram (ES): enviar automáticamente al aprobar” (activada por defecto).
    - Personalizaciones: cambiar nombre de archivo por idioma activo, y el `title`/`description` del canal RSS.
- Generación/edición de `rss.xml` y traducción a `rss_<idioma>.xml` (bajo demanda) con caché de traducción.
- Ficheros de datos planos en `data/` creados automáticamente si faltan.

Arquitectura

- PHP puro:
  - `telex.php`: UI principal y orquestación de acciones (sugerencias, feeds, traducción, Telegram).
  - `includes/config.php`: carga/guardado de `data/config.json`, migración desde el antiguo `pm2_env.json` y utilidades de acceso a configuración.
  - `includes/services.php`: helpers para Gemini, agregación de feeds, generación de sugerencias, traducción con Google Translate y utilidades de persistencia.
- Datos en `data/` (JSON y logs) gestionados con escrituras atómicas.

Requisitos

- PHP 7.4+ con: `mbstring`, `intl` (Normalizer), `dom`, `simplexml`, `curl`.
- Servidor web (Apache/Nginx) con PHP.

Instalación

1) Clonar el repositorio

```
git clone https://github.com/ciamaximalista/telex.git
cd telex
```

2) Configurar el servidor web

- Sirve el proyecto apuntando a la carpeta donde están `index.html` y `telex.php`.
- Verifica que PHP tiene permisos de escritura sobre:
  - Directorio `data/` (y `img/` si subes imágenes).
  - Ficheros `rss.xml` y `rss_<idioma>.xml` (p. ej., `rss_en.xml`).
  - Archivo `data/config.json` (la pestaña Configuración lo crea o migra automáticamente).
- La app utiliza `umask(0002)` para facilitar la colaboración por grupo.

3) Permisos y propiedad (Debian/Ubuntu)

Recomendado si el servidor web usa `www-data`:

```
sudo chgrp -R www-data .
sudo chmod -R g+rwX .
sudo find . -type d -exec chmod g+s {} \;
```

- Si ya existen `rss.xml` o `rss_<idioma>.xml` con otro propietario y no se actualizan, corrige propiedad o elimínalos para que la app los regenere:

```
sudo chown www-data:www-data rss.xml rss_en.xml  # ajusta según el idioma objetivo
```

4) Primer acceso y credenciales

- Abre `telex.php`. Si no hay credenciales, se mostrará `register.php` para crear el usuario inicial.
- Podrás cambiar la contraseña desde la pestaña Configuración más adelante.

5) Configuración (`data/config.json`)

Entra en “Configuración” y completa (se guardará en `data/config.json`):

- `GEMINI_API_KEY`: clave de Google AI Studio (Gemini).
- `GEMINI_MODEL`: por ejemplo `gemini-1.5-flash-latest`.
- `GOOGLE_TRANSLATE_API_KEY`: clave de Cloud Translation.
- Opcional: activa/desactiva el envío automático a Telegram (ES) y ajusta el intervalo sugerido del traductor.

Desde esa pestaña puedes probar ambas integraciones.

Uso básico

- Telex → “Recibir Telex”: ejecuta el generador de sugerencias (Gemini) y carga resultados.
- Revisa, edita y “Aprobar” para añadir a `rss.xml`. También puedes “Añadir entrada de otras fuentes” manualmente.
- Al aprobar una entrada, el título del ítem en la feed se deriva automáticamente del resumen final: se toma el texto hasta el primer punto (`.`), cierre de exclamación (`!`) o cierre de interrogación (`?`). Si no se encuentra ninguno, se recorta a ~140 caracteres.
- RSS/Traducción: edita, mueve ↑/↓, elimina seleccionados o todos, y guarda.
- Traducción → “Guardar idioma de traducción” para cambiar el idioma objetivo; “Forzar traducción ahora” para generar `rss_<idioma>.xml` al instante.
- Fuentes: gestiona tus feeds de entrada.
- Prompt: ajusta el prompt con variables `{{title}} {{description}} {{link}} {{examples}}`.
- Log: inspecciona `data/gemini_log.jsonl` desde la pestaña Log.

Integración con Telegram (opcional)

- Configuración → Bots de Telegram por idioma:
  - Añade el token del bot y el Chat ID del canal (formato `@canal` o ID numérico tipo `-100...`).
  - Puedes editar el Chat ID de cada idioma sin reescribir el token.
- Envío automático (ES):
  - Opción “Telegram (ES): enviar automáticamente al aprobar” (activada por defecto). Si hay bot y Chat ID para español, al aprobar o añadir manualmente se envía automáticamente al canal de ES.
  - Si esta opción está desactivada y existe bot/Chat ID para ES, en la pestaña RSS verás un botón “Enviar a Telegram” debajo de cada entrada para enviarla manualmente.
- Pestaña Telegram (visible si hay bots configurados):
  - Un bloque por idioma con bot y Chat ID configurados. Muestra el archivo de feed y el número de pendientes (los existentes la primera vez se consideran enviados).
  - Botón “Enviar pendientes” y botones “Enviar este” por item.
- Formato de envío a Telegram (para cualquier idioma):
  - Título en negrita (Markdown)
  - Imagen si existe (enclosure o primera imagen de la descripción)
  - Dos saltos de línea
  - Descripción (texto plano)
  - Dos saltos de línea
  - URL

Cambio de idioma de la segunda feed

- Por defecto se traduce al inglés (`rss_en.xml`).
- Desde “Traducción” puedes seleccionar otro idioma. El archivo objetivo será `rss_<idioma>.xml` (no sobrescribe otros idiomas previos).

Estructura de ficheros

- `telex.php`: interfaz principal (pestañas, guardado, pruebas y acciones de traducción/sugerencias).
- `includes/config.php`: gestión de `data/config.json` y migraciones desde `pm2_env.json`.
- `includes/services.php`: helpers para Gemini, feeds, traducción y persistencia.
- `data/`: estado de la app (autogenerado):
  - `sugerencias_pendientes.json`, `examples.json`, `published_messages.json`
  - `.sent_titles_cache.json`, `.sent_titlekeys_cache.json`
  - `sources.json`, `prompt.txt`, `gemini_log.jsonl`
  - `rss_change_cache.json`, `translation_cache.json`
  - `telegram_tokens.json` (tokens y Chat ID por idioma)
  - `telegram_sent.json` (ítems enviados a Telegram por idioma)
  - `feed_customizations.json` (nombre de archivo, título y descripción de feeds por idioma)

Solución de problemas

- `rss_<idioma>.xml` no cambia: revisa “Probar Translate”, usa “Traducir ahora” (con la opción *Ignorar caché* si procede) y verifica permisos.
- Gemini falla: usa “Probar Gemini” y verifica clave/modelo.
- Permisos: el usuario del servidor web debe poder escribir `data/`, `img/`, `data/config.json`, `rss.xml` y `rss_<idioma>.xml`.

Seguridad de secretos

- `data/` incluye un `.htaccess` con `Require all denied` para impedir el acceso directo a ficheros sensibles (como `config.json`).

Actualizaciones

```
git pull
```

Seguridad

- Login básico (usuario/contraseña); considera restringir por IP o VPN.
- No publiques `data/config.json` con claves reales en repositorios públicos.

Licencia

- [EUPL v1.2](https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12)
