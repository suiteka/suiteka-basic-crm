# Auditoría técnica de Suiteka Basic CRM 1.0.0

- **Versión auditada:** 1.0.0 (commit `6c3e011`, rama `main`).
- **Fecha:** 2026-10-11.
- **Alcance:** todos los archivos del repositorio (`suiteka-basic-crm.php`, `uninstall.php`, `README.md`, `readme.txt`, `SKILL.md`, `LICENSE`, `.gitignore`) y su historial (2 commits, sin etiquetas de versión).

## 1. Cómo se ha auditado

Se combinaron lectura completa del código con pruebas reales. Cada hallazgo indica cómo se comprobó:

- **Confirmado (ejecutado):** reproducido en un WordPress real.
- **Confirmado (código):** se deduce sin ambigüedad del código fuente; no requiere ejecución.
- **Recomendación:** no es un error, sino una mejora de diseño o de mantenimiento.
- **No verificado:** no se pudo comprobar en este entorno; se indica por qué.

Entorno de pruebas usado (local, desechable, sin datos reales):

| Componente | Versión |
| --- | --- |
| WordPress | 7.1.3 (núcleo extraído de la imagen oficial `wordpress:php8.3-fpm`) |
| PHP | 8.3.6 (CLI y servidor integrado `php -S`) |
| Base de datos | MariaDB 10.11.14 |
| WP-CLI | 2.12.0 |
| PHP_CodeSniffer | WordPress Coding Standards 3.4.1 y PHPCompatibilityWP |

Las peticiones se hicieron con `curl` autenticado como administrador y como un usuario con rol Editor.

## 2. Resumen

El plugin 1.0.0 es pequeño, legible y está bien protegido para su alcance: comprobación de permisos, nonces ligados al registro, POST para escribir, saneado, escape y consultas preparadas. No se encontró ninguna vulnerabilidad explotable ni riesgo de pérdida de datos en una actualización ordinaria.

Los problemas reales son de **producto y de base técnica** para crecer: un único permiso (`manage_options`), la empresa como texto libre, ausencia de detección de duplicados, de historial y de pruebas automatizadas, y detalles de accesibilidad y usabilidad. La **licencia** sigue sin decidir y bloquea cualquier redistribución.

| Gravedad | Cantidad |
| --- | --- |
| Crítica | 0 |
| Alta | 1 (bloqueante de distribución, no técnico) |
| Media | 6 |
| Baja | 8 |
| Informativa | 5 |

## 3. Hallazgos

### Alta

#### A-01. Licencia sin decidir (bloquea la redistribución)

- **Tipo:** confirmado (código).
- **Archivos:** `LICENSE`, cabecera de `suiteka-basic-crm.php`, `readme.txt`, `README.md`.
- **Evidencia:** `LICENSE` declara «LICENCIA PENDIENTE DE DECISIÓN DEL MANTENEDOR». La cabecera del plugin y `readme.txt` no tienen `License` ni `License URI`.
- **Impacto:** sin licencia no se concede permiso de uso, copia ni redistribución. El directorio de WordPress.org rechaza plugins sin licencia compatible con GPL v2 o posterior.
- **Solución propuesta:** decisión del mantenedor (ver sección 5). Esta auditoría **no** elige licencia.

### Media

#### M-01. Un único permiso global (`manage_options`)

- **Tipo:** confirmado (ejecutado).
- **Archivo:** `suiteka-basic-crm.php` (constante `CAPABILITY`, `register_menu()`, `handle_request()`).
- **Evidencia:** un Editor recibe `403` tanto en `GET admin.php?page=suiteka-crm` como en un `POST` de borrado. Es el comportamiento documentado, pero obliga a dar permisos de administrador del sitio a cualquier comercial.
- **Impacto:** imposible delegar el CRM sin dar control total de WordPress. No existen reglas de acceso por registro.
- **Solución:** capacidades propias (`suiteka_crm_*`) y meta-capacidades por registro con `map_meta_cap`, concedidas al actualizar a los roles que ya tenían `manage_options` para no cambiar quién accede hoy.

#### M-02. La empresa es texto libre dentro del contacto

- **Tipo:** confirmado (código).
- **Archivo:** `suiteka-basic-crm.php`, columna `company varchar(191)`.
- **Impacto:** «ACME», «Acme» y «Acme S.L.» son empresas distintas; no se pueden guardar datos de la empresa ni ver sus contactos. Base inadecuada para oportunidades.
- **Solución:** tabla de empresas con nombre único y `contacts.company_id`. Migración que crea una empresa por cada valor distinto (comparación sin distinguir mayúsculas ni acentos, según la intercalación de la base de datos) y enlaza los contactos sin modificar la columna original.

#### M-03. Sin detección de duplicados

- **Tipo:** confirmado (código).
- **Archivo:** `suiteka-basic-crm.php`, `handle_save()`.
- **Evidencia:** no hay ninguna comprobación previa a `$wpdb->insert()`; se pueden crear contactos con el mismo correo electrónico.
- **Solución:** aviso antes de guardar si coincide correo, teléfono normalizado o nombre, con confirmación explícita, y una pantalla de posibles duplicados con combinación segura (vista previa, POST con nonce, transacción).

#### M-04. Errores de formulario no asociados a los campos (accesibilidad)

- **Tipo:** confirmado (ejecutado).
- **Archivo:** `suiteka-basic-crm.php`, `render_notices()` y `render_form()`.
- **Evidencia:** al enviar el formulario con nombre vacío, correo `malo` y teléfono `abc`, la respuesta contiene un único `notice-error` con una lista; ningún campo tiene `aria-invalid` ni `aria-describedby`, y el mensaje no enlaza con el campo.
- **Impacto:** quien usa lector de pantalla oye los errores al principio, pero al llegar al campo no sabe que es incorrecto (WCAG 3.3.1 y 1.3.1).
- **Solución:** errores indexados por campo, mensaje junto al campo, `aria-invalid="true"`, `aria-describedby` y resumen con enlaces a cada campo.

#### M-05. Sin pruebas automatizadas ni integración continua

- **Tipo:** confirmado (código).
- **Evidencia:** no existe directorio de pruebas, configuración de PHPUnit ni flujo de CI. Las pruebas de `SKILL.md` son manuales.
- **Impacto:** cualquier cambio de esquema o de permisos puede romper datos o seguridad sin que nadie lo detecte.
- **Solución:** suite PHPUnit de integración con la librería oficial de pruebas de WordPress, prueba de actualización de extremo a extremo desde 1.0.0 y CI con PHPCS.

#### M-06. Requisitos mínimos declarados sin verificar

- **Tipo:** confirmado (código y documentación).
- **Archivos:** cabecera (`Requires at least: 5.2`, `Requires PHP: 7.0`), `readme.txt`, `README.md`, `SKILL.md`.
- **Evidencia:** la documentación justifica los mínimos por las APIs usadas, pero no consta ninguna prueba en WordPress 5.2. `PHPCompatibilityWP` con `testVersion 7.0-` no da avisos (ejecutado), lo que cubre la sintaxis de PHP pero no el comportamiento en WordPress 5.2.
- **Solución:** probar la versión nueva en WordPress 5.2 (imagen `wordpress:5.2-php7.2`) y analizar con PHPCompatibility para PHP 7.0. Mantener los mínimos solo si esas pruebas pasan.

### Baja

#### B-01. Los enlaces de paginación arrastran el aviso de la acción anterior

- **Tipo:** confirmado (ejecutado).
- **Archivo:** `suiteka-basic-crm.php`, `render_list()` (llamada a `paginate_links()`).
- **Evidencia:** tras crear un contacto, el listado (`...&message=created`) genera el enlace a la página 2 como `admin.php?page=suiteka-crm&paged=2&message=created`. `paginate_links()` fusiona los parámetros de la URL actual, así que el aviso «Contacto creado correctamente» vuelve a aparecer al paginar.
- **Solución:** generar la paginación a partir de una lista cerrada de parámetros (búsqueda, filtros, orden), sin `message`.

#### B-02. Alteración silenciosa de la entrada

- **Tipo:** confirmado (ejecutado).
- **Archivo:** `suiteka-basic-crm.php`, `sanitize_contact()`.
- **Evidencia:** el nombre `Juan <CEO> Pérez` se guarda como `Juan Pérez` sin avisar. `sanitize_text_field()` también elimina secuencias como `%A1`.
- **Impacto:** pérdida silenciosa de parte de lo que escribe la persona. No es un problema de seguridad (la salida se escapa).
- **Solución:** si el valor saneado difiere del original (salvo espacios), rechazar el campo con un mensaje claro y conservar lo escrito.

#### B-03. Sin fecha de actualización ni historial

- **Tipo:** confirmado (código).
- **Evidencia:** la tabla solo tiene `created_at`. El borrado es definitivo y no queda rastro.
- **Solución:** `updated_at` y una tabla de actividades con eventos del sistema (alta, cambios, combinación) y notas manuales.

#### B-04. Búsqueda sin filtros ni ordenación

- **Tipo:** confirmado (código).
- **Evidencia:** solo hay búsqueda `LIKE` y orden fijo por fecha de creación.
- **Solución:** filtros por estado, origen, empresa, etiqueta y responsable; ordenación por lista blanca de columnas.

#### B-05. Búsqueda con comodín inicial

- **Tipo:** recomendación.
- **Archivo:** `query_contacts()`.
- **Evidencia:** `LIKE '%texto%'` sobre tres columnas no puede usar índices.
- **Impacto:** aceptable para el tamaño previsto (miles o decenas de miles de contactos). A partir de ahí convendría un índice `FULLTEXT` o una búsqueda por prefijo.

#### B-06. Migraciones futuras sin protección frente a ejecución concurrente

- **Tipo:** recomendación (riesgo para la evolución).
- **Archivo:** `maybe_install()`.
- **Evidencia:** se ejecuta en cada `admin_init`; dos peticiones simultáneas tras actualizar ejecutarían `install()` a la vez. En 1.0.0 es inocuo porque `dbDelta()` es idempotente, pero una migración de datos (crear empresas) podría duplicar registros.
- **Solución:** bloqueo con `add_option()` (atómico por clave única) y pasos de migración idempotentes y reanudables.

#### B-07. Borrado de datos al desinstalar solo mediante WP-CLI o SQL

- **Tipo:** confirmado (código), decisión de diseño documentada.
- **Archivos:** `uninstall.php`, `README.md`.
- **Evidencia:** la opción `suiteka_basic_crm_delete_data_on_uninstall` no tiene interfaz. Se verificó (ejecutado) que la desinstalación por defecto conserva la tabla y la opción de versión.
- **Solución:** mantener el comportamiento conservador; valorar una casilla en una futura pantalla de ajustes, con advertencia y confirmación.

#### B-08. `readme.txt` incompleto para WordPress.org

- **Tipo:** confirmado (código).
- **Evidencia:** faltan `Contributors`, `Tested up to`, `License` y `License URI`. La ausencia de `Tested up to` es deliberada según `SKILL.md` («solo con una versión realmente probada»).
- **Solución:** añadir `Tested up to` cuando haya una prueba real; el resto depende de la decisión de licencia y de la cuenta de WordPress.org del mantenedor.

### Informativa

#### I-01. Seguridad verificada en ejecución

Pruebas reales sobre 1.0.0, todas con el resultado esperado:

| Prueba | Resultado |
| --- | --- |
| `POST` con nonce inválido | `403` |
| Editor: `GET` del listado y `POST` de borrado | `403` en ambos; nada se modifica |
| `full_name[]=x` (array en lugar de texto) | Se trata como vacío: «El nombre completo es obligatorio» |
| Búsqueda `%'"` | Sin errores SQL; «No se encontraron contactos» |
| Nombre `Eve "<script>alert(1)</script>` | Se guarda `Eve "` y se muestra escapado |
| Desinstalar con la opción por defecto | La tabla (26 filas) y la opción de versión se conservan |

#### I-02. Estándares de código

- `php -l` sin errores en ambos archivos PHP.
- `phpcs --standard=WordPress --exclude=WordPress.Files.FileName`: 0 errores y 0 avisos.
- `phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.0-`: 0 errores y 0 avisos.

#### I-03. Coherencia de versiones y requisitos

Coherentes en todos los archivos: versión 1.0.0 en cabecera, `SUITEKA_BASIC_CRM_VERSION`, `Stable tag` y changelog; WordPress 5.2 y PHP 7.0 en cabecera, `readme.txt`, `README.md` y `SKILL.md`. Esquema `SUITEKA_BASIC_CRM_DB_VERSION = '1'`.

#### I-04. Carga de traducciones

No hay `load_plugin_textdomain()` ni directorio `languages/`. Las traducciones publicadas en translate.wordpress.org se cargarían igualmente (carga bajo demanda desde WordPress 4.6). Solo hará falta si el plugin incluye sus propios archivos `.mo`.

#### I-05. `SKILL.md` restringe el desarrollo aprobado

La sección 2 prohíbe oportunidades, tareas, capacidades propias, paneles e importación/exportación, y la sección 8 impide añadir tablas. Esas restricciones contradicen la nueva hoja de ruta y deben sustituirse.

## 4. Aspectos que no pudieron verificarse

| Aspecto | Motivo | Cómo verificarlo |
| --- | --- | --- |
| Multisitio (activación en red y desinstalación con `get_sites()`) | No se montó una red multisitio | `wp core multisite-convert`, activar en red, visitar el escritorio de un subsitio y comprobar que se crea su tabla; desinstalar con y sin la opción de borrado |
| MySQL 5.x y bases de datos con `utf8` (no `utf8mb4`) | Solo había MariaDB 10.11 | Repetir la prueba de actualización en MySQL 5.7 con intercalación `utf8_general_ci` |
| Lectores de pantalla reales | No hay NVDA, JAWS ni VoiceOver en el entorno | Revisión manual con un lector de pantalla |
| Comprobaciones del directorio de WordPress.org (Plugin Check) | `api.wordpress.org` y `wordpress.org` están bloqueados por la red de este entorno | Instalar el plugin «Plugin Check» en un sitio de pruebas |

## 5. Decisión pendiente del mantenedor: licencia

El archivo `LICENSE` debe seguir como está hasta que el mantenedor decida. Antes de publicar o redistribuir, el mantenedor debe decidir:

1. **Licencia del código.** Si el plugin se distribuirá públicamente o en WordPress.org, debe ser **GPL v2 o posterior** o una licencia compatible (por ejemplo GPL v3, MIT o Apache 2.0, con matices de compatibilidad). Un plugin de WordPress que enlaza con el núcleo se considera habitualmente obra derivada de un software GPL.
2. **Titularidad.** Quién figura en el aviso de copyright (actualmente «Suiteka»), y si las contribuciones externas exigirán acuerdo de cesión o bastará con la licencia de entrada igual a la de salida.
3. **Marca.** La licencia del código no cubre el nombre «Suiteka»; si se quiere proteger, debe indicarse aparte.
4. **Tras decidir:** sustituir `LICENSE` por el texto completo; añadir `License` y `License URI` a la cabecera de `suiteka-basic-crm.php` y a `readme.txt`; actualizar la sección «Licencia» de `README.md` y la lista de comprobación de `SKILL.md`.

Hasta entonces, el plugin se puede desarrollar y probar internamente, pero no publicar.

## 6. Correspondencia con el trabajo realizado

| Hallazgo | Estado en la versión 1.1.0 |
| --- | --- |
| A-01 | Pendiente de decisión del mantenedor (documentado) |
| M-01 | Resuelto: capacidades propias y reglas de acceso por registro |
| M-02 | Resuelto: entidad empresa y migración |
| M-03 | Resuelto: aviso previo y pantalla de duplicados con combinación |
| M-04 | Resuelto: errores por campo con atributos ARIA |
| M-05 | Resuelto: PHPUnit, prueba de actualización, PHPCS y CI |
| M-06 | Resuelto: versión nueva probada en WordPress 5.2 con PHP 7.2; PHPCompatibility para PHP 7.0 |
| B-01 | Resuelto: paginación propia con parámetros controlados |
| B-02 | Resuelto: se rechaza el campo y se conserva lo escrito |
| B-03 | Resuelto: `updated_at` e historial de actividades |
| B-04 | Resuelto: filtros y ordenación |
| B-05 | Pendiente (recomendación; documentado en la hoja de ruta) |
| B-06 | Resuelto: bloqueo de migración y pasos idempotentes |
| B-07 | Sin cambios (comportamiento conservador intencionado) |
| B-08 | Parcial: `Tested up to` añadido tras las pruebas; licencia pendiente |
| I-05 | Resuelto: `SKILL.md` reescrito |

El detalle de las pruebas ejecutadas sobre la versión 1.1.0 está en [PRUEBAS.md](PRUEBAS.md).
