# SKILL: desarrollo y mantenimiento de Suiteka Basic CRM

Guía permanente para quien trabaje en este repositorio (personas o agentes). Léela antes de cambiar código y mantenla al día en el mismo commit que cambie lo que describe.

## 1. Qué es y hacia dónde va

**Suiteka Basic CRM** es un plugin de WordPress que convierte el escritorio en un CRM ligero para autónomos y pequeñas empresas. Mantenido por [Suiteka](https://suiteka.com/).

Visión: un CRM modular, seguro y fácil de usar que crece por fases — contactos y empresas (hecho), oportunidades y embudos, tareas y actividades, panel con métricas reales, importación y exportación CSV — sin dejar de ser un plugin instalable, sin dependencias externas y sin poner en riesgo los datos existentes.

Prioridades, en este orden: **seguridad y conservación de datos → facilidad de uso → cantidad de funciones**.

Documentos de referencia:

| Documento | Contenido |
| --- | --- |
| [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md) | Capas, modelo de datos, integridad, permisos, migraciones |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Fases aprobadas, estado y decisiones pendientes |
| [docs/AUDITORIA-TECNICA.md](docs/AUDITORIA-TECNICA.md) | Auditoría de 1.0.0 y seguimiento de hallazgos |
| [docs/PRUEBAS.md](docs/PRUEBAS.md) | Pruebas ejecutadas y sus resultados reales |

## 2. Alcance

**Implementado (1.1.0):** contactos (nombre y apellidos, correo, teléfono, cargo, empresa, origen, estado, etiquetas, responsable, notas, fechas), empresas, etiquetas, historial (eventos del sistema, notas, llamadas, reuniones), búsqueda, filtros, ordenación, paginación, detección y combinación de duplicados, capacidades propias con reglas por registro, migración desde 1.0.0.

**Aprobado para fases siguientes** (ver hoja de ruta): oportunidades y embudos con tablero Kanban accesible, tareas y recordatorios dentro del escritorio, panel de métricas, importación y exportación CSV, rol «Comercial» y pantalla de ajustes.

**Fuera de alcance salvo decisión explícita del mantenedor:**

- Envío de correos, SMS o mensajes externos sin configuración y consentimiento explícitos.
- Servicios externos, integraciones de terceros, inteligencia artificial.
- Cualquier salida pública: API REST sin autenticación, `admin-ajax` para visitantes, shortcodes, bloques o páginas que muestren datos del CRM.
- Dependencias de Composer o npm en tiempo de ejecución, frameworks JavaScript o bibliotecas externas.
- Facturación, contabilidad, campañas de marketing.

Reglas de trabajo: una fase de producto cada vez, en una rama creada desde `main`; nunca commits directos en `main`; nada se publica como versión de producción sin autorización del mantenedor.

## 3. Estructura de archivos

| Ruta | Responsabilidad |
| --- | --- |
| `suiteka-basic-crm.php` | Cabecera, constantes, carga de clases y fachada `Suiteka_Basic_CRM` (`init()`, `table()`, `install()`, `maybe_install()`, `now()`) |
| `uninstall.php` | Desinstalación; conserva todo salvo que `suiteka_basic_crm_delete_data_on_uninstall` valga `1` |
| `includes/class-suiteka-basic-crm-installer.php` | Esquema (`dbDelta`), migraciones versionadas, bloqueo |
| `includes/class-suiteka-basic-crm-capabilities.php` | Capacidades, `map_meta_cap`, ámbito de visibilidad, responsables |
| `includes/class-suiteka-basic-crm-validator.php` | Saneado y validación con errores por campo |
| `includes/class-suiteka-basic-crm-contacts.php` | Repositorio y reglas de contactos |
| `includes/class-suiteka-basic-crm-companies.php` | Repositorio y reglas de empresas |
| `includes/class-suiteka-basic-crm-tags.php` | Etiquetas |
| `includes/class-suiteka-basic-crm-activities.php` | Historial |
| `includes/class-suiteka-basic-crm-duplicates.php` | Duplicados y combinación |
| `admin/class-suiteka-basic-crm-admin.php` | Menú, CSS, avisos, utilidades de interfaz (paginación, ordenación, errores accesibles) |
| `admin/class-suiteka-basic-crm-admin-*.php` | Un controlador por pantalla: contactos, empresas, actividades, duplicados |
| `assets/css/admin.css` | Estilos de las pantallas del plugin |
| `tests/phpunit/` | Pruebas de integración con la librería oficial de WordPress |
| `tests/e2e/` | Prueba de actualización desde 1.0.0 y prueba de humo HTTP |
| `docs/` | Auditoría, arquitectura, hoja de ruta, registro de pruebas |
| `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist` | Herramientas **solo de desarrollo** |
| `.github/workflows/ci.yml` | Integración continua |
| `.gitattributes` | Excluye del paquete distribuible todo lo que no es el plugin |

Reglas de capas: el dominio (`includes/`) no lee `$_GET`/`$_POST`, no comprueba nonces ni imprime HTML; la interfaz (`admin/`) no construye SQL. Un archivo por clase, con el nombre `class-{nombre-de-la-clase}.php`.

Datos persistentes: tablas `{prefijo}suiteka_crm_contacts`, `_companies`, `_tags`, `_tag_relationships`, `_activities`; opciones `suiteka_basic_crm_db_version`, `suiteka_basic_crm_upgrade_lock` (temporal) y `suiteka_basic_crm_delete_data_on_uninstall` (solo si se crea a mano); capacidades `suiteka_crm_*` en los roles. Detalle en `docs/ARQUITECTURA.md` §3.

## 4. Requisitos mínimos

- **WordPress 5.2** y **PHP 7.0**. Probado de verdad en WordPress 5.2.4 con PHP 7.2.24 y en WordPress 7.1.3 con PHP 8.3; sintaxis comprobada con PHP 7.0.33. Ver `docs/PRUEBAS.md`.
- PHP 7.0 implica: sin tipos anulables (`?int`), sin `void`, sin `array_key_first()`, sin funciones flecha, sin propiedades tipadas. `??` sí está permitido. PHPCompatibility lo verifica (`testVersion 7.0-`).
- WordPress 5.2 implica: sin `wp_date()` ni `wp_timezone()` (5.3), sin el argumento `capability` de `get_users()` (5.9), sin `wp_admin_notice()` (6.4). Usa `mysql2date()` + `get_date_from_gmt()`.
- Las herramientas de desarrollo (PHPUnit 9) necesitan PHP 7.3 o superior; no afectan al plugin.
- Si se eleva un mínimo: cabecera del plugin, `readme.txt`, `README.md`, esta sección, `phpcs.xml.dist` (`testVersion`, `minimum_wp_version`) y la matriz de CI, a la vez.

## 5. Desarrollo y pruebas

### Preparar el entorno

```sh
composer install                       # solo herramientas de desarrollo
```

Necesitas una base de datos MySQL/MariaDB **exclusiva para pruebas** (la suite borra todas sus tablas) y una copia del núcleo de WordPress.

### Comprobaciones (todas deben pasar antes de cada commit)

```sh
composer lint                          # php -l
vendor/bin/phpcs                       # WordPress Coding Standards + PHPCompatibilityWP (PHP 7.0+, WP 5.2+)

WP_CORE_DIR=/ruta/a/wordpress \
WP_TESTS_DB_NAME=wordpress_test WP_TESTS_DB_USER=usuario WP_TESTS_DB_PASSWORD=clave WP_TESTS_DB_HOST=127.0.0.1 \
vendor/bin/phpunit                     # integración con WordPress

tests/e2e/upgrade-from-1.0.0.sh /ruta/a/un/wordpress/desechable
tests/e2e/http-smoke.sh http://sitio-de-pruebas admin clave [usuario-sin-acceso clave]
```

- `upgrade-from-1.0.0.sh` **borra las tablas** de ese WordPress: instala 1.0.0 desde Git, crea datos heredados con casos límite, actualiza, comprueba que nada se pierde ni cambia, repite la migración, prueba el bloqueo, la desactivación y ambas desinstalaciones.
- `http-smoke.sh` recorre todas las pantallas por HTTP con un administrador (crea, busca, combina y borra registros de prueba) y comprueba los 403 de un usuario sin acceso.
- Para WordPress 5.2 se puede usar la imagen `wordpress:5.2-php7.2` (ver `docs/PRUEBAS.md`).

### Pruebas manuales que no cubren las automáticas

- Revisión con lector de pantalla (NVDA, VoiceOver) de formularios, errores e historial.
- Multisitio: activación en red, visitar un subsitio, desinstalar.
- MySQL 5.7 con intercalación `utf8_general_ci`.

### Depurar

En el `wp-config.php` del sitio de pruebas: `WP_DEBUG` y `WP_DEBUG_LOG` a `true`, `WP_DEBUG_DISPLAY` a `false`; revisa `wp-content/debug.log`. La interfaz nunca muestra errores internos.

### Escribir pruebas

- Hereda de `Suiteka_Basic_CRM_Test_Case`: crea un administrador, dos comerciales (rol de pruebas con `view`, `edit` y `delete`) y un editor; convierte las redirecciones en excepciones; restablece el estado estático de los controladores.
- Simula peticiones con `$this->request( Controlador, $get, $post, $accion_del_nonce )`; un nonce `null` es inválido.
- Cada cambio de comportamiento lleva su prueba; cada error corregido, una prueba que lo reproduzca.

## 6. Migraciones del esquema

1. Modifica `Suiteka_Basic_CRM_Installer::schema()` (solo añadir columnas, índices o tablas).
2. Incrementa `SUITEKA_BASIC_CRM_DB_VERSION`.
3. Añade un método `migrate_to_N()` idempotente y reanudable, y llámalo en `install()` si la versión instalada es menor que `N`.
4. No elimines ni renombres columnas con datos. Si una columna deja de usarse, se conserva y se documenta como heredada.
5. Prueba la actualización desde la última versión publicada y desde 1.0.0 (amplía `tests/e2e/upgrade-from-1.0.0.sh`).
6. Documenta el paso en `docs/ARQUITECTURA.md` §7 y en el changelog, indicando qué ocurre con los campos vacíos y los duplicados.

## 7. Convenciones de código

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) (verificado por `phpcs.xml.dist`): tabuladores, `array()`, condiciones Yoda, llaves siempre.
- Prefijos: `Suiteka_Basic_CRM_` (clases), `suiteka_basic_crm_` (funciones, opciones, filtros), `suiteka_crm_` (capacidades y nonces), `suiteka-crm` (slugs, clases e IDs de HTML).
- Dominio de texto `suiteka-basic-crm`; idioma fuente español; comentario `/* translators: */` cuando haya marcadores.
- Comentarios y docblocks breves, en español.
- APIs de WordPress antes que código propio; clases de estilo del escritorio (`wrap`, `form-table`, `widefat`, `notice`, `button`).
- Sin JavaScript. Si alguna vez es imprescindible: mínimo, sin dependencias, solo en las pantallas del plugin y con la página funcionando sin él.
- Todo `phpcs:ignore` / `phpcs:disable` lleva su justificación en el mismo comentario.

## 8. Reglas de seguridad obligatorias

1. **Permisos.** Toda pantalla comprueba su capacidad en el menú y en `load-{pantalla}`; toda operación comprueba la meta-capacidad del registro (`suiteka_crm_view_record`, `_edit_record`, `_delete_record`, con el ID del responsable) antes de leerlo o modificarlo. Nunca `manage_options` para el CRM.
2. **Visibilidad.** Toda consulta de listados, búsquedas, recuentos, sugerencias o exportaciones aplica `Suiteka_Basic_CRM_Capabilities::scope()` en el SQL. Un usuario no puede ver, por ninguna vía, registros que no está autorizado a consultar.
3. **Escrituras.** Solo por POST, con `check_admin_referer()` y un nonce que incluye la operación y el ID (`suiteka_crm_save_contact_{id}`, `suiteka_crm_merge_contacts_{a}_{b}`…). Nada se modifica por GET.
4. **Entradas.** `wp_unslash()`, comprobación de tipo (`is_string`), saneado, validación de formato, longitud y lista blanca con `Suiteka_Basic_CRM_Validator`. Si el saneado altera el valor, se rechaza el campo y se conserva lo escrito.
5. **Salidas.** `esc_html()`, `esc_attr()`, `esc_textarea()`, `esc_url()` según el contexto, también en traducciones.
6. **SQL.** Valores con `$wpdb->prepare()`; `LIKE` con `$wpdb->esc_like()`; tablas solo desde `Suiteka_Basic_CRM::table()`; `ORDER BY` desde lista blanca.
7. **Mensajes de la URL** desde una lista cerrada; nunca se imprime el valor recibido.
8. **Errores internos** solo en el registro de depuración.
9. **Nada público.** Sin REST ni `admin-ajax` sin autenticación y autorización, sin shortcodes, sin bloques.
10. **Datos.** No se guardan contraseñas, tokens ni secretos. El historial guarda los nombres de los campos modificados, no los valores antiguos.
11. **Repositorio.** Sin credenciales, volcados, registros ni datos personales reales; los datos de prueba usan dominios `example.test`/`example.org`.
12. **Desinstalación.** Conserva todo salvo autorización explícita.

## 9. Lista de comprobación antes de integrar o publicar

- [ ] `composer lint`, `vendor/bin/phpcs` y `vendor/bin/phpunit` sin errores.
- [ ] `tests/e2e/upgrade-from-1.0.0.sh` y `tests/e2e/http-smoke.sh` correctos en un WordPress real.
- [ ] Sin avisos nuevos en `debug.log`.
- [ ] CI en verde.
- [ ] Versión coherente en cabecera, `SUITEKA_BASIC_CRM_VERSION`, `Stable tag` y changelog; esquema en `SUITEKA_BASIC_CRM_DB_VERSION`.
- [ ] Requisitos coherentes en cabecera, `readme.txt`, `README.md` y sección 4.
- [ ] `Tested up to` solo con una versión de WordPress realmente probada.
- [ ] `docs/PRUEBAS.md` actualizado con resultados reales.
- [ ] **Licencia decidida** y reflejada en `LICENSE`, cabeceras y README (obligatorio antes de distribuir).
- [ ] Revisión y aprobación del mantenedor.

## 10. Mantener la documentación al día

Cada cambio de código actualiza en el mismo commit lo que describa ese comportamiento: `README.md` y `readme.txt` (funciones, permisos, datos, changelog), `docs/ARQUITECTURA.md` (modelo, reglas, migraciones), `docs/ROADMAP.md` (estado), `docs/PRUEBAS.md` (resultados) y esta guía. Si la documentación y el código no coinciden, manda el código: corrige la documentación.

## 11. Identidad y mantenimiento

- Proyecto: Suiteka Basic CRM. Marca y mantenimiento: Suiteka — https://suiteka.com/.
- El autor de la cabecera del plugin es «Suiteka». No se añaden nombres de personas ni créditos que no correspondan a contribuciones reales y autorizadas.
- Los commits se hacen con la identidad Git real y autorizada de quien los publica. No se reescribe el historial publicado.
- No se añaden insignias, enlaces promocionales ni publicidad.
- **Licencia pendiente** (ver `LICENSE` y `docs/AUDITORIA-TECNICA.md` §5): ninguna persona ni agente elige licencia; lo decide el mantenedor.
