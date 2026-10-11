# Registro de pruebas

Resultados **reales** de las pruebas ejecutadas sobre la versión 1.1.0 (esquema 2) antes de entregarla para revisión. Si una prueba no se ejecutó, se indica expresamente.

- **Fecha:** 2026-10-11.
- **Rama:** `claude/confident-hawking-irtqnt` (creada desde `main`, commit `6c3e011`).
- **Datos:** solo datos de prueba generados (dominios `example.test` y `example.org`); ningún dato personal real.

## 1. Entornos

| Entorno | WordPress | PHP | Base de datos | Servidor |
| --- | --- | --- | --- | --- |
| A (actual) | 7.1.3 | 8.3.6 | MariaDB 10.11.14 | `php -S` (servidor integrado de PHP) |
| B (mínimo) | 5.2.4 | 7.2.24 | MariaDB 10.11.14 | Apache 2.4.38 (imagen oficial `wordpress:5.2-php7.2`) |
| C (sintaxis) | — | 7.0.33 | — | imagen oficial `php:7.0-cli` |

Herramientas: WP-CLI 2.12.0, PHPUnit 9.6.38, `wp-phpunit/wp-phpunit` 7.1.1, WordPress Coding Standards 3.4.1, PHPCompatibilityWP, Playwright 1.48.2 con Chromium (headless shell 1194) y axe-core 4.10.2.

## 2. Resumen

| Prueba | Entorno | Resultado |
| --- | --- | --- |
| `php -l` (todos los archivos PHP del repositorio) | A | 26 archivos sin errores |
| `php -l` (archivos del plugin) | C — PHP 7.0.33 | 15 archivos sin errores |
| PHPCS: WordPress + PHPCompatibilityWP (`testVersion 7.0-`, WP 5.2+) | A | 0 errores, 0 avisos (26 archivos) |
| PHPUnit de integración | A | **81 pruebas, 389 aserciones, todas correctas** |
| Prueba de mutación (3 errores de seguridad introducidos a propósito) | A | Los 3 detectados (3, 1 y 3 pruebas fallidas); código restaurado |
| Actualización desde 1.0.0 y desinstalación (`tests/e2e/upgrade-from-1.0.0.sh`) | A | **37/37** comprobaciones |
| Ídem | B | **37/37** comprobaciones |
| Humo HTTP de todas las pantallas (`tests/e2e/http-smoke.sh`) | A | **33/33** comprobaciones; `debug.log` sin entradas del plugin ni de PHP |
| Ídem | B | **33/33** comprobaciones; `debug.log` sin entradas del plugin ni de PHP |
| Accesibilidad automática (axe-core, WCAG 2.0/2.1 A y AA) en 9 pantallas × 2 anchos | A | 0 infracciones en las 18 vistas (tras corregir 1, ver §5) |
| Desbordamiento horizontal a 375 px | A | 0 px en las 9 pantallas (tras corregir 2, ver §5) |
| Uso con teclado del formulario y del resumen de errores | A | Correcto |

En el `debug.log` solo aparecieron avisos del núcleo por no poder conectar con wordpress.org (la red del entorno lo bloquea), no relacionados con el plugin.

## 3. Detalle

### 3.1 PHPUnit (`tests/phpunit/`)

| Archivo | Qué cubre |
| --- | --- |
| `test-installer.php` | Tablas y columnas, versión del esquema, tabla desconocida rechazada, capacidades del administrador (y ninguna del editor), migración de nombres y empresas sin distinguir mayúsculas, idempotencia, contactos ya migrados intactos, bloqueo frente a instalaciones simultáneas |
| `test-capabilities.php` | Meta-capacidades propio/ajeno/sin responsable, `view_others` no implica editar, editor sin acceso, ámbito de visibilidad (incluido usuario sin sesión), responsables asignables |
| `test-validator.php` | Obligatorios, espacios, HTML y `%41` rechazados conservando lo escrito, `<` suelto, saltos de línea, valores que no son texto, longitud multibyte, correo, teléfono, URL (https añadido, `javascript:` rechazado), listas cerradas, IDs |
| `test-contacts.php` | Alta con nombre derivado, fechas, etiquetas e historial; cambios registrados sin valores antiguos; validación combinada; borrado en cascada conservando el historial compartido; búsqueda con `%`, `_` y comillas; filtros; ámbito; ordenación por lista blanca (intento de inyección en `orderby`); paginación ajustada; resolución de empresa; estados filtrados por terceros |
| `test-companies.php` | Nombre único sin distinguir mayúsculas, validación, historial de cambios, borrado que conserva contactos, recuentos y listados con ámbito, historial limitado a contactos visibles, búsqueda |
| `test-duplicates.php` | Coincidencia por correo, dígitos del teléfono y nombre; exclusión del propio registro; ocultación de duplicados ajenos; grupos; combinación sin pérdida de datos; pares inválidos |
| `test-admin-contacts.php` | Nonce inválido, nonce de otro registro, usuario sin acceso, alta y redirección, empresa creada al vuelo, errores con `aria-invalid`/`aria-describedby` y datos conservados escapados, confirmación de duplicados, comercial sin poder elegir responsable ni ver/editar/borrar lo ajeno, listado limitado, GET de borrado sin efecto, borrado por POST, paginación sin `message`, `aria-sort`, escape de salida, mensajes de URL desconocidos |
| `test-admin-other.php` | Alta de empresa y nombre repetido, borrado de empresa con contactos ajenos (403 al comercial), borrado por el administrador, alta y borrado de actividades, errores de actividad conservando datos, eventos del sistema no borrables, nota ajena no borrable, combinación desde la pantalla, combinación prohibida al comercial, vista previa sin efectos |

Prueba de mutación: se introdujeron por separado tres errores — `edit_record` sin comprobar responsable, guardar sin `check_admin_referer()` y listado sin ámbito de visibilidad — y en cada caso la suite falló (3, 1 y 3 pruebas). Después se restauró el código.

### 3.2 Actualización desde 1.0.0 (`tests/e2e/upgrade-from-1.0.0.sh`)

Instala 1.0.0 desde Git (`6c3e011`), crea 260 contactos heredados — duplicados por correo y nombre, variantes «ACME», «Acme» y «Ácme », «Beta S.L.» y «beta s.l.», campos vacíos, un emoji, comillas, saltos de línea y 230 empresas distintas para forzar varios lotes — y actualiza al código actual. Comprueba:

- Esquema 2, bloqueo liberado, mismo número de contactos y **suma MD5 idéntica** de las columnas originales (`id`, `full_name`, `email`, `phone`, `company`, `notes`, `created_at`).
- Nombre copiado a `first_name`, `updated_at = created_at`, una empresa por valor distinto, todos los contactos enlazados, variantes agrupadas, responsables vacíos, sin historial inventado, emoji intacto.
- Capacidades para el administrador y ninguna para el editor; duplicados detectados y no combinados.
- Repetir la migración no crea empresas ni altera datos; un bloqueo activo la impide y uno caducado se reemplaza.
- Desactivar y reactivar conserva todo; desinstalar por defecto conserva tablas, datos y opciones; reinstalar no altera nada; desinstalar con la opción de borrado elimina las cinco tablas, las opciones y las capacidades.
- Instalación limpia del código actual: esquema 2, cinco tablas y capacidades.

### 3.3 Humo HTTP (`tests/e2e/http-smoke.sh`)

Como administrador: carga de las cinco pantallas y de la hoja de estilos; alta de empresa; formulario con errores (no guarda, marca los campos y conserva lo escrito); alta de contacto con empresa; ficha; actividad; aviso de duplicado y «Guardar de todos modos»; búsqueda (incluida `%'"`), filtros y orden con `aria-sort`; pantalla de duplicados, vista previa y combinación; GET de borrado sin efecto; nonce inválido con 403; borrado de contacto y de empresa. Como editor: 403 en las cuatro pantallas.

### 3.4 Accesibilidad, teclado y móvil

Script de Playwright fuera del repositorio (no forma parte de la suite porque requiere Node y un navegador). Pantallas: contactos, contactos filtrados, nuevo contacto, ficha, edición, empresas, ficha de empresa, duplicados y combinación, a 1280 px y 375 px.

- axe-core (`wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`) sobre `#wpbody-content`: 0 infracciones.
- Ancho del documento a 375 px: igual al de la ventana en todas las pantallas.
- Teclado: el botón de envío se alcanza con Tab, el formulario se envía con Intro, el resumen enlaza con los campos erróneos y al activarlo se llega al campo, que conserva el valor y tiene `aria-invalid="true"`; foco visible.

## 4. No verificado

| Aspecto | Motivo | Cómo verificarlo |
| --- | --- | --- |
| Ejecución real en PHP 7.0 y 7.1 | No hay imagen de WordPress 5.2 con PHP 7.0; solo se comprobó la sintaxis con PHP 7.0.33 y la compatibilidad con PHPCompatibility | Sitio con PHP 7.0 y WordPress 5.2: `tests/e2e/upgrade-from-1.0.0.sh` y `tests/e2e/http-smoke.sh` |
| WordPress 5.2.0–5.2.3 | La imagen disponible es 5.2.4 | Repetir con el paquete de 5.2.0 |
| MySQL 5.7/8.0 y bases de datos `utf8` | Solo había MariaDB 10.11 (el CI usa MySQL 8.0, ver §6) | Repetir la prueba de actualización con `utf8_general_ci` |
| Multisitio | No se montó una red | Activación en red, visitar un subsitio (crea sus tablas), desinstalar con y sin borrado |
| Lectores de pantalla reales | No disponibles en el entorno | Revisión con NVDA o VoiceOver |
| Plugin Check de WordPress.org | wordpress.org bloqueado por la red del entorno | Instalar «Plugin Check» en un sitio de pruebas |
| Volúmenes grandes (más de 50 000 contactos) | Fuera del alcance de esta fase | Generar datos con WP-CLI y medir listados, búsqueda y duplicados |

## 5. Problemas encontrados y corregidos durante las pruebas

| Prueba que lo detectó | Problema | Corrección |
| --- | --- | --- |
| PHPUnit | Con etiquetas repetidas en distinta capitalización se conservaba la última forma escrita | Se conserva la primera |
| PHPUnit | Un POST sin el campo `owner_id` dejaba el contacto o la empresa sin responsable | Si el campo no llega, el responsable no cambia |
| axe-core / Playwright | A 375 px, los listados de contactos y empresas desbordaban la página 298 px y 238 px: los textos para lectores de pantalla (posición absoluta) escapaban del contenedor con desplazamiento | `position: relative` en el contenedor |
| axe-core | La tabla de comparación de «Combinar» era desplazable pero no alcanzable con teclado (`scrollable-region-focusable`) | Contenedores de tablas con `role="region"`, `tabindex="0"`, nombre accesible y foco visible |
| Prueba de actualización | El emoji aparecía como `????` | No era un fallo del plugin: el cliente `mysql` usado para insertar los datos de prueba conectaba en `utf8mb3`. El script usa ahora la conexión `utf8mb4` de WordPress |

## 6. Integración continua

`.github/workflows/ci.yml` ejecuta en cada push: sintaxis con PHP 7.0, 7.4 y 8.3; PHPCS; PHPUnit con la última versión de WordPress y MySQL 8.0; y la prueba de actualización desde 1.0.0. El resultado de la primera ejecución en GitHub se indica en la descripción de la entrega o del pull request.

## 7. Cómo repetir las pruebas

Ver `SKILL.md` §5. Para el entorno B:

```sh
docker create --name wp52 wordpress:5.2-php7.2 && docker cp wp52:/usr/src/wordpress /ruta/wp52 && docker rm wp52
wp --path=/ruta/wp52 config create --dbname=wp52 --dbuser=… --dbpass=… --dbhost=127.0.0.1 --skip-check
docker run --rm --network host -v /ruta/wp52:/var/www/html -v "$PWD":/repo:ro \
  -v /ruta/legacy-1.0.0:/legacy:ro -v "$(command -v wp)":/usr/local/bin/wp:ro -e LEGACY_DIR=/legacy \
  wordpress:5.2-php7.2 /repo/tests/e2e/upgrade-from-1.0.0.sh /var/www/html
```

`/ruta/legacy-1.0.0` contiene `suiteka-basic-crm.php` y `uninstall.php` extraídos con `git show 6c3e011:<archivo>`.
