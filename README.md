# Telex

Telex es una aplicación web ligera desarrollada en PHP, diseñada para simplificar la gestión de contenidos RSS. Permite automatizar la curación de noticias mediante inteligencia artificial (Gemini), publicar y traducir feeds RSS, y distribuirlos a través de Telegram.

## Características Principales

Telex te ofrece un control completo sobre tu flujo de noticias con las siguientes funcionalidades:

*   **Curación Inteligente con Gemini (Pestaña "Telex")**:
    *   **Generación de Sugerencias**: Lee un gran volumen de fuentes RSS externas y utiliza la IA de Gemini para generar sugerencias de noticias relevantes.
    *   **Edición Detallada**: Las sugerencias de Gemini no se presentan como un bloque de texto único, sino que se dividen en **campos editables de Título, Descripción y Enlace**. Esto te permite refinar cada detalle antes de la publicación.
    *   **Aprobación y Rechazo**: Decide qué sugerencias son adecuadas para tu feed.
    *   **Entradas Manuales**: Añade noticias de "otras fuentes" manualmente, utilizando la misma estructura editable de Título, Descripción y Enlace. El título que introduzcas se usará **tal cual** en el feed RSS.
    *   **Subida de Imágenes Mejorada**: El formulario de entrada manual ahora gestiona la subida de imágenes (desde archivo o URL) de forma más robusta, incluyendo la etiqueta `<img>` en la descripción del ítem RSS para una mejor visibilidad.

*   **Gestión Avanzada de Feeds RSS (Pestañas "RSS" y "Traducción")**:
    *   **Edición Directa**: Edita los ítems de tu `rss.xml` principal y de los feeds traducidos (`rss_<idioma>.xml`) directamente desde la interfaz.
    *   **Visualización Ampliada**: Ambas pestañas ahora muestran hasta **200 ítems** para una gestión más completa.
    *   **Ordenación por Fecha**: Los ítems se muestran y se guardan automáticamente ordenados de **más reciente a más antiguo**.
    *   **Reordenación Temporal**: Los cursores (flechas ↑/↓) te permiten reordenar los ítems manualmente. Ten en cuenta que esta reordenación es temporal y se restablecerá al orden por fecha la próxima vez que se guarde el feed (por ejemplo, al añadir una nueva entrada).
    *   **Eliminación Flexible**: Elimina ítems seleccionados o vacía el feed completo.

*   **Traducción Automática**:
    *   **Feeds Multilingües**: Traduce automáticamente tu feed principal (`rss.xml`) a otros idiomas, generando archivos como `rss_en.xml`, `rss_fr.xml`, etc.
    *   **Control de Idioma**: Cambia el idioma objetivo de la traducción y fuerza la traducción en cualquier momento.

*   **Integración con Telegram (Opcional)**:
    *   **Configuración de Bots**: Configura bots de Telegram por idioma, especificando el token y el **Chat ID (ahora obligatorio)** del canal.
    *   **Envío Automático/Manual**: Envía entradas automáticamente a canales de Telegram al aprobarlas, o envíalas manualmente una a una.

*   **Personalización y Control**:
    *   **Prompt Editable**: Ajusta el prompt que se envía a Gemini para generar sugerencias, utilizando variables como `{{title}}`, `{{description}}`, `{{link}}`, `{{examples}}`.
    *   **Gestión de Fuentes**: Añade, ordena y elimina las fuentes RSS de las que Telex extrae noticias.
    *   **Registro de Actividad**: Visualiza un log detallado de las llamadas a la API de Gemini.
    *   **Configuración Centralizada**: Gestiona claves de API (Gemini, Google Translate), modelos de IA, ajustes de Telegram y personaliza los nombres y metadatos de tus archivos RSS por idioma.

## Arquitectura

Telex está construido con una arquitectura sencilla y eficiente:

*   **PHP Puro**:
    *   `telex.php`: La interfaz de usuario principal, que orquesta todas las acciones (sugerencias, gestión de feeds, traducción, Telegram).
    *   `includes/config.php`: Maneja la carga y guardado de la configuración (`data/config.json`) y utilidades relacionadas.
    *   `includes/services.php`: Contiene funciones auxiliares para la integración con Gemini, la agregación de feeds, la generación de sugerencias, la traducción con Google Translate y la persistencia de datos.
*   **Datos Planos**: Todos los datos de la aplicación (configuración, sugerencias, logs) se almacenan en ficheros JSON y de texto plano dentro del directorio `data/`, gestionados con escrituras atómicas para mayor seguridad.

## Requisitos

*   **PHP 7.4+** con las extensiones: `mbstring`, `intl` (para `Normalizer`), `dom`, `simplexml`, `curl`.
*   Un servidor web (Apache/Nginx) configurado para servir aplicaciones PHP.

## Instalación

1.  **Clonar el repositorio**:
    ```bash
    git clone https://github.com/ciamaximalista/telex.git
    cd telex
    ```

2.  **Configurar el servidor web**:
    *   Apunta tu servidor web a la carpeta donde se encuentran `index.html` y `telex.php`.
    *   **Permisos de escritura**: Asegúrate de que el usuario del servidor web (ej. `www-data` en Debian/Ubuntu) tenga permisos de escritura sobre:
        *   El directorio `data/` y todos sus contenidos.
        *   El directorio `img/` (esencial para la subida de imágenes manuales).
        *   Los ficheros `rss.xml` y `rss_<idioma>.xml` (ej. `rss_en.xml`).
        *   El archivo `data/config.json`.
    *   La aplicación utiliza `umask(0002)` para facilitar la colaboración en entornos de grupo.

3.  **Ajustar permisos y propiedad (ej. en Debian/Ubuntu)**:
    Si el servidor web usa `www-data`, ejecuta:
    ```bash
    sudo chgrp -R www-data .
    sudo chmod -R g+rwX .
    sudo find . -type d -exec chmod g+s {} \;
    ```
    Si encuentras problemas con `rss.xml` o `rss_<idioma>.xml` que no se actualizan debido a permisos incorrectos, puedes corregir la propiedad:
    ```bash
    sudo chown www-data:www-data rss.xml rss_en.xml # Ajusta según el idioma objetivo
    ```

4.  **Primer acceso y credenciales**:
    *   Abre `telex.php` en tu navegador. Si no hay credenciales configuradas, serás redirigido a `register.php` para crear el usuario inicial.
    *   Podrás cambiar la contraseña desde la pestaña "Configuración" más adelante.

5.  **Configuración Inicial (`data/config.json`)**:
    Accede a la pestaña "Configuración" y completa los siguientes campos (se guardarán automáticamente en `data/config.json`):
    *   `GEMINI_API_KEY`: Tu clave de API de Google AI Studio (Gemini).
    *   `GEMINI_MODEL`: El modelo de Gemini a utilizar (ej. `gemini-1.5-flash-latest`).
    *   `GOOGLE_TRANSLATE_API_KEY`: Tu clave de API de Google Cloud Translation.
    *   **Telegram**: Configura tus bots de Telegram por idioma. Recuerda que el **Chat ID es ahora obligatorio** para que el bot funcione correctamente en la pestaña "Telegram".
    *   Opcional: Activa/desactiva el envío automático a Telegram (ES) y ajusta el intervalo sugerido del traductor.
    Desde esta pestaña, puedes probar la conectividad con las APIs de Gemini y Google Translate.

## Uso Básico

1.  **Generar Sugerencias**: Ve a la pestaña "Telex" y haz clic en "📡 Recibir Telex". La aplicación buscará nuevas noticias en tus fuentes configuradas y generará sugerencias con Gemini.
2.  **Revisar y Publicar**:
    *   Revisa las sugerencias pendientes en la pestaña "Telex".
    *   **Edita el Título, Descripción y Enlace** directamente en los campos provistos.
    *   Haz clic en "Aprobar" para añadir la entrada a `rss.xml` o "Guardar y Aprobar" si has realizado ediciones.
    *   También puedes "Añadir entrada de otras fuentes" manualmente, incluyendo imágenes.
3.  **Gestionar Feeds RSS**:
    *   En las pestañas "RSS" y "Traducción", puedes editar los campos de los ítems, moverlos (temporalmente), eliminar seleccionados o todos, y guardar los cambios.
    *   La ordenación por defecto es de más reciente a más antiguo.
4.  **Traducir Feeds**: En la pestaña "Traducción", selecciona el idioma objetivo y haz clic en "Traducir ahora" para generar o actualizar `rss_<idioma>.xml`.
5.  **Enviar a Telegram**: Si tienes bots configurados, puedes enviar entradas pendientes o individuales a tus canales de Telegram desde la pestaña "Telegram".

## Integración con Telegram (Detalle)

*   **Configuración**: En "Configuración" -> "Bots de Telegram por idioma", añade el token de tu bot y el Chat ID del canal (ej. `@mi_canal` o un ID numérico como `-1001234567890`). El Chat ID es ahora un campo obligatorio.
*   **Envío Automático (ES)**: Si la opción "Telegram (ES): enviar automáticamente al aprobar" está activada y tienes un bot configurado para español, las entradas aprobadas o añadidas manualmente se enviarán automáticamente.
*   **Envío Manual**: Si el envío automático está desactivado, verás un botón "Enviar a Telegram" debajo de cada entrada en las pestañas "RSS" y "Traducción" para enviarla manualmente.
*   **Formato de Envío**: Los mensajes se envían con el título en negrita, la imagen (si existe), la descripción en texto plano y el enlace.

## Cambio de Idioma de la Segunda Feed

*   Por defecto, la segunda feed se traduce al inglés (`rss_en.xml`).
*   Desde la pestaña "Traducción", puedes seleccionar cualquier otro idioma. El archivo resultante será `rss_<idioma>.xml` (ej. `rss_fr.xml`), sin sobrescribir feeds de otros idiomas existentes.

## Estructura de Ficheros

*   `telex.php`: Interfaz principal (pestañas, lógica de guardado, pruebas y acciones de traducción/sugerencias).
*   `includes/config.php`: Gestión de `data/config.json` y migraciones.
*   `includes/services.php`: Funciones auxiliares para Gemini, feeds, traducción y persistencia.
*   `data/`: Directorio autogenerado para el estado de la aplicación, incluyendo:
    *   `sugerencias_pendientes.json`, `examples.json`, `published_messages.json`
    *   `.sent_titles_cache.json`, `.sent_titlekeys_cache.json`
    *   `sources.json`, `prompt.txt`, `gemini_log.jsonl`
    *   `rss_change_cache.json`, `translation_cache.json`
    *   `telegram_tokens.json` (tokens y Chat ID por idioma)
    *   `telegram_sent.json` (ítems enviados a Telegram por idioma)
    *   `feed_customizations.json` (nombre de archivo, título y descripción de feeds por idioma)
*   `img/`: Directorio para imágenes subidas manualmente.

## Solución de Problemas

*   `rss_<idioma>.xml` no cambia: Verifica la conectividad con Google Translate en "Configuración" ("Probar Translate"), usa "Traducir ahora" (con la opción *Ignorar caché* si es necesario) y revisa los permisos de escritura.
*   Gemini falla: Usa "Probar Gemini" en "Configuración" y verifica tu clave de API y el modelo seleccionado.
*   **Subida de imágenes falla**: Asegúrate de que el directorio `img/` tenga permisos de escritura para el usuario del servidor web.
*   Permisos generales: El usuario del servidor web debe poder escribir en `data/`, `img/`, `data/config.json`, `rss.xml` y `rss_<idioma>.xml`.

## Seguridad de Secretos

*   El directorio `data/` incluye un `.htaccess` con `Require all denied` para impedir el acceso directo a ficheros sensibles (como `config.json`).
*   **Importante**: No publiques `data/config.json` con claves reales en repositorios públicos.

## Actualizaciones

Para actualizar la aplicación a la última versión:
```bash
git pull
```

## Licencia

Este software está bajo la licencia [EUPL v1.2](https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12).