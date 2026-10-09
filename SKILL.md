# SKILL: desarrollo y mantenimiento de Suiteka Basic CRM

Guía permanente para quien trabaje en este repositorio. Léela antes de cambiar código y mantenla al día.

## 1. Qué es

**Suiteka Basic CRM** es un plugin de WordPress que añade un gestor de contactos al escritorio. Su propósito es ser el CRM más sencillo posible: una agenda de clientes y personas de contacto que se puede crear, consultar, buscar, editar y eliminar. Si necesitas contexto sobre qué debería cubrir un CRM en una pequeña empresa y qué puede quedar fuera, el sitio de [Suiteka](https://suiteka.com/) es la referencia del proyecto.

## 2. Alcance del MVP

Incluido:

- Menú «Suiteka CRM» con las pantallas «Contactos» (listado, edición y confirmación de borrado) y «Añadir contacto».
- Campos: nombre completo (obligatorio), correo electrónico, teléfono, empresa, notas y fecha de creación (UTC en la base de datos; se muestra en la zona horaria del sitio).
- Búsqueda por nombre, correo o empresa (`LIKE` sobre las tres columnas).
- Paginación de 20 contactos por página.
- Mensajes de éxito y error y pantalla vacía con llamada a la acción.

Fuera de alcance (no añadir sin una decisión explícita del mantenedor):

- Oportunidades, embudos, tareas, calendario, facturación, campañas, automatizaciones.
- Inteligencia artificial, WhatsApp, correo saliente, integraciones externas.
- API REST pública, shortcodes, bloques o cualquier salida en la parte pública.
- Roles o capacidades personalizadas, paneles analíticos, importación/exportación.
- Pantallas de ajustes.
- Dependencias de Composer o npm, frameworks JavaScript o bibliotecas externas.

## 3. Estructura de archivos

Estructura plana a propósito. No crees subdirectorios salvo que sean imprescindibles.

| Archivo | Responsabilidad |
| --- | --- |
| `suiteka-basic-crm.php` | Archivo principal. Cabecera del plugin, constantes de versión y la clase `Suiteka_Basic_CRM`: creación de la tabla (`install()` / `maybe_install()`), menú, CSS mínimo en línea, procesamiento de formularios (`handle_request()`, `handle_save()`, `handle_delete()`), validación, consultas y renderizado de las pantallas. |
| `uninstall.php` | Desinstalación. Conserva los datos salvo que la opción `suiteka_basic_crm_delete_data_on_uninstall` valga exactamente `1`; en ese caso elimina la tabla y las opciones. Recorre todos los sitios en multisitio. |
| `README.md` | Presentación del proyecto para GitHub. |
| `readme.txt` | Readme con formato de WordPress.org. |
| `SKILL.md` | Esta guía. |
| `LICENSE` | Estado de la licencia (pendiente de decisión del mantenedor). |
| `.gitignore` | Exclusiones de archivos locales, dependencias, registros y secretos. |

Datos persistentes:

- Tabla `{$wpdb->prefix}suiteka_crm_contacts` (columnas `id`, `full_name`, `email`, `phone`, `company`, `notes`, `created_at`).
- Opción `suiteka_basic_crm_db_version`: versión del esquema instalada.
- Opción `suiteka_basic_crm_delete_data_on_uninstall` (no existe por defecto): autoriza borrar datos al desinstalar.

## 4. Requisitos mínimos

- **WordPress 5.2**: primera versión que respeta la cabecera `Requires PHP`. Todas las APIs usadas son anteriores (`dbDelta`, `sanitize_textarea_field` 4.7, `get_sites` 4.6, `wp_add_inline_style`, `paginate_links`, `mysql2date`).
- **PHP 7.0**: el código no usa tipos escalares, tipos de retorno, operador `??` ni funciones flecha. Si se sube el mínimo, actualiza cabecera, `readme.txt`, `README.md` y esta sección a la vez.

## 5. Instalar, activar, probar, depurar y actualizar

**Instalar y activar**: copia la carpeta en `wp-content/plugins/suiteka-basic-crm/` y actívala en «Plugins». Con WP-CLI: `wp plugin activate suiteka-basic-crm`.

**Probar** (en un sitio de pruebas, nunca en producción):

1. `php -l suiteka-basic-crm.php` y `php -l uninstall.php`.
2. Si tienes PHP_CodeSniffer con WordPress Coding Standards instalado fuera del repositorio: `phpcs --standard=WordPress --exclude=WordPress.Files.FileName suiteka-basic-crm.php uninstall.php`. La regla de nombre de archivo se excluye porque el archivo principal debe llamarse como el slug del plugin.
3. Pruebas manuales como administrador:
   - Con la tabla vacía aparece la pantalla de bienvenida.
   - Crear sin nombre, con correo inválido o con teléfono con letras muestra errores y conserva lo escrito.
   - Crear, editar y eliminar muestran el mensaje correspondiente.
   - La búsqueda encuentra por nombre, correo y empresa; `%` y comillas no rompen nada.
   - Con más de 20 contactos aparece la paginación y conserva la búsqueda.
   - Entrar en «Eliminar» solo muestra la confirmación; el borrado ocurre al enviar el formulario.
   - Un nombre como `Eve "<script>alert(1)</script>` se guarda sin etiquetas y se muestra escapado.
4. Permisos: con un usuario Editor, las pantallas devuelven 403 y los POST no modifican nada.
5. Ciclo de vida: desactivar y reactivar conserva los contactos; desinstalar conserva la tabla; con la opción de borrado a `1`, desinstalar elimina tabla y opciones.

**Depurar**: en `wp-config.php` del sitio de pruebas usa `WP_DEBUG` y `WP_DEBUG_LOG` a `true` y `WP_DEBUG_DISPLAY` a `false`; revisa `wp-content/debug.log`. Los errores de base de datos se registran ahí y la interfaz solo muestra mensajes genéricos. Comprueba la tabla con `wp db query "DESCRIBE wp_suiteka_crm_contacts"`.

**Actualizar**:

- Sube `Version` en la cabecera, `SUITEKA_BASIC_CRM_VERSION` y `Stable tag` en `readme.txt`, y añade una entrada en `== Changelog ==`.
- Si cambia el esquema: modifica el `CREATE TABLE` de `install()` (dbDelta añade columnas e índices, pero no borra ni renombra) e incrementa `SUITEKA_BASIC_CRM_DB_VERSION`. `maybe_install()` aplicará el cambio en la siguiente carga del escritorio.
- Nunca elimines columnas con datos sin una migración explícita y una nota en el changelog.

## 6. Convenciones de código

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/): tabuladores, espacios dentro de paréntesis, `array()`, condiciones Yoda, llaves siempre.
- Prefijo `suiteka_basic_crm_` / `Suiteka_Basic_CRM` / `suiteka-crm` en funciones, opciones, clases, nonces, slugs y clases CSS.
- Dominio de texto `suiteka-basic-crm` en todas las cadenas. Idioma fuente: español. Añade comentarios `/* translators: */` cuando haya marcadores.
- Comentarios y docblocks breves, en español.
- Usa funciones nativas de WordPress antes que código propio y las clases de estilo del escritorio (`wrap`, `form-table`, `widefat`, `notice`, `button`).
- Sin JavaScript. Si alguna vez es imprescindible, debe ser mínimo, sin dependencias y encolado solo en las pantallas del plugin.
- Los `phpcs:ignore` / `phpcs:disable` solo con una justificación en el mismo comentario.

## 7. Reglas de seguridad obligatorias

1. Toda pantalla y todo procesamiento comprueban `current_user_can( 'manage_options' )`.
2. Toda operación que escribe usa POST y un nonce cuyo nombre incluye el ID del contacto (`suiteka_crm_save_contact_{id}`, `suiteka_crm_delete_contact_{id}`), verificado con `check_admin_referer()`.
3. Nunca se borra ni modifica nada mediante GET. Los enlaces solo muestran pantallas.
4. Los IDs del navegador se convierten con `absint()` y se verifica que el contacto existe antes de actuar.
5. Entradas: `wp_unslash()` y después `sanitize_text_field()`, `sanitize_textarea_field()` o `sanitize_email()`; valida formato y longitud y rechaza valores que no sean texto.
6. Salidas: `esc_html()`, `esc_attr()`, `esc_textarea()`, `esc_url()` según el contexto, también en las traducciones (`esc_html__()`, `esc_attr_e()`…).
7. SQL: valores variables siempre con `$wpdb->prepare()`; en búsquedas `LIKE`, `$wpdb->esc_like()`. El nombre de la tabla solo sale de `table_name()`.
8. Los mensajes de la URL (`message=`) se traducen desde una lista cerrada; nunca se imprime el valor recibido.
9. Ningún error interno (SQL, rutas, trazas) se muestra al usuario.
10. Nada público: sin REST, sin `admin-ajax` sin autenticar, sin shortcodes.
11. No se guardan contraseñas, tokens, secretos ni datos personales más allá de los campos del contacto.
12. Desinstalar conserva los datos salvo autorización explícita.

## 8. Cómo añadir funciones sin convertirlo en un CRM complejo

Antes de añadir algo, responde «sí» a todo:

- ¿Lo necesita la mayoría de quienes solo quieren una agenda de contactos?
- ¿Cabe en la tabla y las pantallas existentes, o en un único campo nuevo?
- ¿Se puede hacer sin dependencias, sin JavaScript y sin pantallas de ajustes?
- ¿No abre ninguna vía de acceso público a los datos?

Si alguna respuesta es «no», no se implementa aquí. Si es «sí»: un cambio por commit, respetando las reglas de seguridad, actualizando el esquema con `SUITEKA_BASIC_CRM_DB_VERSION` si toca la tabla, y actualizando la documentación (sección 10).

## 9. Lista de comprobación antes de publicar

- [ ] `php -l` sin errores en todos los archivos PHP.
- [ ] `phpcs` (WordPress) sin errores, si está disponible.
- [ ] Pruebas manuales de la sección 5 hechas en un WordPress real (crear, listar, buscar, paginar, editar, eliminar, permisos, ciclo de vida).
- [ ] Sin avisos nuevos en `debug.log`.
- [ ] Versión coherente en cabecera, constante, `Stable tag` y changelog.
- [ ] Requisitos coherentes en cabecera, `readme.txt`, `README.md` y este archivo.
- [ ] Añadido `Tested up to` en `readme.txt` solo con una versión de WordPress realmente probada.
- [ ] Licencia decidida y reflejada en `LICENSE`, cabeceras y README (obligatoria antes de distribuir públicamente).
- [ ] Sin secretos, registros, volcados de base de datos ni datos personales en el repositorio.
- [ ] El alcance sigue siendo el de la sección 2.

## 10. Mantener la documentación al día

Cada cambio de código actualiza en el mismo commit lo que describa ese comportamiento:

- Funciones o campos: «Funciones» de `README.md`, «Description» de `readme.txt` y secciones 2 y 3 de este archivo.
- Requisitos: cabecera del plugin, `readme.txt`, `README.md` y sección 4.
- Datos, opciones o desinstalación: «Conservación de datos» de `README.md`, la FAQ de `readme.txt` y sección 3.
- Seguridad: sección 7 y «Seguridad» de `README.md`.
- Nueva versión: changelog de `readme.txt`.

Si la documentación y el código no coinciden, manda el código: corrige la documentación.

## 11. Identidad y mantenimiento

- Proyecto: Suiteka Basic CRM. Marca y mantenimiento: Suiteka — https://suiteka.com/.
- El autor que figura en la cabecera del plugin es «Suiteka». No se añaden nombres de personas, colaboradores ni créditos que no correspondan a contribuciones reales y autorizadas.
- Los commits se hacen con la identidad Git real y autorizada de quien los publica. No se reescribe el historial para ocultar la procedencia de contribuciones.
- No se añaden insignias, enlaces promocionales ni publicidad.
