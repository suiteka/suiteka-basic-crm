# Arquitectura de Suiteka Basic CRM

Este documento describe la arquitectura vigente (versión 1.1.0, esquema 2) y el diseño previsto para los módulos de las siguientes fases. Si el código y este documento no coinciden, manda el código: corrige el documento.

## 1. Principios

1. **Proporcionalidad.** Un CRM para autónomos y pequeñas empresas: decenas de miles de registros como máximo y pocas personas usuarias. Nada de capas que no se necesiten hoy.
2. **Sin dependencias en ejecución.** Solo PHP y las APIs de WordPress. Sin Composer, npm, frameworks JavaScript ni servicios externos en el plugin distribuido. Composer se usa **solo en desarrollo** (pruebas y análisis estático) y nunca se empaqueta.
3. **Los datos existentes son sagrados.** Las actualizaciones añaden columnas y tablas; nunca borran ni renombran columnas con datos. Toda migración es idempotente y reanudable.
4. **Seguridad por defecto.** Cada lectura y cada escritura pasan por una capacidad del CRM; todo lo que escribe usa POST y nonce; nada es público.
5. **Interfaz nativa.** Pantallas del escritorio con las clases de estilo de WordPress, formularios HTML que funcionan sin JavaScript y CSS mínimo en un archivo propio.

## 2. Estructura de archivos

```
suiteka-basic-crm.php            Arranque: cabecera, constantes, carga de clases y fachada Suiteka_Basic_CRM
uninstall.php                    Desinstalación (conserva los datos salvo autorización explícita)
includes/
  class-suiteka-basic-crm-installer.php     Esquema (dbDelta), migraciones versionadas y bloqueo
  class-suiteka-basic-crm-capabilities.php  Capacidades, meta-capacidades por registro y responsables
  class-suiteka-basic-crm-validator.php     Saneado y validación de formularios con errores por campo
  class-suiteka-basic-crm-contacts.php      Repositorio y reglas de negocio de contactos
  class-suiteka-basic-crm-companies.php     Repositorio y reglas de negocio de empresas
  class-suiteka-basic-crm-tags.php          Etiquetas y su relación con los registros
  class-suiteka-basic-crm-activities.php    Historial: eventos del sistema, notas, llamadas y reuniones
  class-suiteka-basic-crm-duplicates.php    Detección de posibles duplicados y combinación
admin/
  class-suiteka-basic-crm-admin.php             Menú, recursos, avisos y utilidades de interfaz comunes
  class-suiteka-basic-crm-admin-contacts.php    Pantallas y envíos de contactos
  class-suiteka-basic-crm-admin-companies.php   Pantallas y envíos de empresas
  class-suiteka-basic-crm-admin-activities.php  Historial y formulario de actividades (compartido)
  class-suiteka-basic-crm-admin-duplicates.php  Pantalla de duplicados y combinación
assets/css/admin.css             Estilos de las pantallas del plugin
tests/                           Pruebas PHPUnit de integración y prueba de actualización de extremo a extremo
docs/                            Auditoría, arquitectura, hoja de ruta y registro de pruebas
```

### Responsabilidades por capa

| Capa | Clases | Puede | No puede |
| --- | --- | --- | --- |
| Arranque y fachada | `Suiteka_Basic_CRM` | Cargar clases, registrar hooks, exponer `table()`, `install()` y `maybe_install()` | Contener lógica de negocio |
| Persistencia y dominio | `*_Contacts`, `*_Companies`, `*_Tags`, `*_Activities`, `*_Duplicates` | Validar reglas de integridad, leer y escribir con `$wpdb` preparado, registrar eventos | Leer `$_GET`/`$_POST`, comprobar nonces, imprimir HTML |
| Seguridad | `*_Capabilities` | Definir capacidades, traducir meta-capacidades, calcular el ámbito de visibilidad | Consultar tablas del CRM |
| Validación | `*_Validator` | Sanear y validar valores de formulario y devolver errores por campo | Conocer tablas o permisos |
| Interfaz | `*_Admin*` | Comprobar permisos y nonces, llamar al dominio, redirigir, imprimir HTML escapado | Construir SQL |
| Instalación | `*_Installer` | Crear y actualizar tablas, migrar datos, conceder capacidades | Ejecutarse sin bloqueo |

La separación permite reutilizar el dominio desde la futura importación CSV, desde WP-CLI o desde las pruebas sin pasar por formularios.

## 3. Modelo de datos

Todas las tablas usan el prefijo del sitio (`{$wpdb->prefix}suiteka_crm_`), la intercalación del sitio (`$wpdb->get_charset_collate()`) y fechas en **UTC** (`datetime`), que se muestran en la zona horaria del sitio. No se usan claves foráneas en la base de datos (WordPress no las usa y `dbDelta()` no las gestiona); la integridad se aplica en el dominio, con las reglas de la sección 4.

Convención para relaciones opcionales: `bigint unsigned NOT NULL DEFAULT 0`, donde `0` significa «sin relación».

### 3.1 Implementado (esquema 2)

```
                ┌───────────────┐
                │   companies   │ 1
                └──────┬────────┘
                       │ 0..n (company_id)
┌──────────┐    ┌──────┴────────┐   n ┌─────────────────────┐ n  ┌────────┐
│ wp_users │────│   contacts    │─────│ tag_relationships   │────│  tags  │
└──────────┘    └──────┬────────┘     └─────────────────────┘    └────────┘
  owner_id             │ 0..n (contact_id / company_id)
                ┌──────┴────────┐
                │  activities   │
                └───────────────┘
```

#### `suiteka_crm_contacts` (existente desde 1.0.0, ampliada)

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | bigint unsigned AI | Clave primaria |
| `full_name` | varchar(191) | **Derivada**: `nombre + ' ' + apellidos`, la recalcula el dominio en cada escritura. Se conserva por compatibilidad con 1.0.0, búsqueda y ordenación |
| `first_name` | varchar(191) | Nombre. Obligatorio |
| `last_name` | varchar(191) | Apellidos |
| `email` | varchar(100) | Opcional, validado con `is_email()` |
| `phone` | varchar(50) | Opcional; dígitos, espacios y `+ ( ) - .` |
| `job_title` | varchar(100) | Cargo |
| `company` | varchar(191) | **Heredada de 1.0.0.** Texto original de la empresa; se conserva intacta y no se vuelve a escribir. La relación vigente es `company_id` |
| `company_id` | bigint unsigned | Empresa (`0` = ninguna) |
| `source` | varchar(32) | Clave de origen (`''` = sin especificar) |
| `status` | varchar(32) | Clave de estado (`''` = sin clasificar) |
| `notes` | text | Notas generales |
| `owner_id` | bigint unsigned | Responsable (`wp_users.ID`; `0` = sin responsable) |
| `created_at` | datetime | UTC |
| `updated_at` | datetime NULL | UTC. Admite NULL para poder añadir la columna a tablas existentes en modo SQL estricto; la migración la rellena |

Índices: `full_name`, `email`, `company` (heredados), `created_at`, `company_id`, `owner_id`, `status`, `updated_at`.

Origen (`source`): `web`, `referral` (recomendación), `event`, `social`, `phone` (llamada entrante), `email`, `other`. Estado (`status`): `lead` (potencial), `customer` (cliente), `former` (antiguo cliente), `inactive` (descartado). Ambas listas se pueden ampliar con los filtros `suiteka_basic_crm_contact_sources` y `suiteka_basic_crm_contact_statuses`; las claves desconocidas se rechazan al guardar.

#### `suiteka_crm_companies` (nueva)

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | bigint unsigned AI | |
| `name` | varchar(191) | Obligatorio y **único** (índice `UNIQUE`; sin distinguir mayúsculas ni, según la intercalación, acentos) |
| `website` | varchar(191) | Solo `http` o `https` |
| `email`, `phone` | varchar(100), varchar(50) | Mismas reglas que en contactos |
| `city` | varchar(100) | |
| `notes` | text | |
| `owner_id` | bigint unsigned | Responsable |
| `created_at`, `updated_at` | datetime | UTC |

#### `suiteka_crm_tags` y `suiteka_crm_tag_relationships` (nuevas)

- `tags`: `id`, `name varchar(50)` con índice `UNIQUE` (sin distinguir mayúsculas).
- `tag_relationships`: `tag_id`, `object_type varchar(20)` (`contact`; preparado para `company` y `opportunity`), `object_id`. Clave primaria compuesta `(object_type, object_id, tag_id)` e índice por `tag_id`.

Se eligieron tablas propias en lugar de una taxonomía de WordPress porque las taxonomías están pensadas para entradas: su interfaz de gestión requiere un tipo de contenido y sus contadores y cachés asumen objetos `post`. Las etiquetas huérfanas no se muestran en los filtros (se consultan con `JOIN`).

#### `suiteka_crm_activities` (nueva)

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | bigint unsigned AI | |
| `type` | varchar(20) | `note`, `call`, `meeting` o `system` |
| `event` | varchar(32) | Solo en `system`: `created`, `updated`, `merged`… Se traduce al mostrarlo |
| `content` | text | Texto escrito por la persona usuaria (vacío en eventos del sistema) |
| `meta` | text | JSON pequeño con datos del evento, p. ej. los **nombres** de los campos modificados (nunca sus valores antiguos) |
| `contact_id`, `company_id` | bigint unsigned | Registros relacionados |
| `opportunity_id` | bigint unsigned | Reservada para la fase de oportunidades (siempre `0` por ahora) |
| `user_id` | bigint unsigned | Autor (`0` = sistema o migración) |
| `occurred_at` | datetime | Cuándo ocurrió (UTC); en llamadas y reuniones lo indica la persona |
| `created_at` | datetime | Cuándo se registró (UTC) |

Índices: `(contact_id, occurred_at)`, `(company_id, occurred_at)`, `opportunity_id`.

#### Opciones

| Opción | Uso |
| --- | --- |
| `suiteka_basic_crm_db_version` | Versión del esquema instalada (`2`) |
| `suiteka_basic_crm_upgrade_lock` | Bloqueo temporal durante la migración (no se carga automáticamente; caduca a los 10 minutos) |
| `suiteka_basic_crm_delete_data_on_uninstall` | Solo si vale `1`, desinstalar borra tablas, opciones y capacidades |

### 3.2 Diseño previsto (no implementado)

Se documenta para que las fases siguientes encajen sin rehacer lo anterior. Puede ajustarse al implementarlas.

```
pipelines 1──n pipeline_stages 1──n opportunities n──1 contacts / companies
                                         │ 1
                                         ├──n opportunity_stage_history
                                         ├──n activities (opportunity_id)
                                         └──n tasks (opportunity_id)
```

- **`pipelines`**: `id`, `name`, `is_default`, `position`, fechas.
- **`pipeline_stages`**: `id`, `pipeline_id`, `name`, `position`, `probability` (opcional, 0–100). Las etapas son solo las **abiertas**; ganado y perdido son estados de la oportunidad, no etapas.
- **`opportunities`**: `id`, `title`, `contact_id`, `company_id`, `pipeline_id`, `stage_id`, `amount decimal(15,2)`, `currency char(3)` (ISO 4217), `probability tinyint NULL` (si es NULL se usa la de la etapa), `expected_close_date date NULL`, `status` (`open`, `won`, `lost`), `lost_reason`, `closed_at`, `owner_id`, fechas.
- **`opportunity_stage_history`**: `id`, `opportunity_id`, `from_stage_id`, `to_stage_id`, `from_status`, `to_status`, `user_id`, `changed_at`. Se escribe en el dominio en cada cambio; nunca se edita.
- **`tasks`**: `id`, `title`, `description`, `due_at datetime NULL`, `priority` (`low`, `normal`, `high`), `status` (`open`, `done`, `cancelled`), `owner_id` (responsable), `created_by`, `contact_id`, `company_id`, `opportunity_id`, `completed_at`, fechas.

Las métricas del panel se calcularán **solo** con estas columnas (recuentos, sumas de `amount` por estado y moneda, tareas vencidas por `due_at`). No se mezclan importes de monedas distintas.

## 4. Reglas de integridad

| Regla | Dónde se aplica |
| --- | --- |
| `first_name` obligatorio; `full_name` = nombre + apellidos, máximo 191 caracteres | `Contacts::prepare()` |
| La empresa escrita se enlaza si existe y el usuario puede verla; si no existe, se crea con el mismo responsable; si existe pero no es visible, se rechaza (salvo que ya estuviera enlazada) | `Contacts::resolve_company()` y `Admin_Contacts` |
| El nombre de empresa es único | Índice `UNIQUE` y `Companies::prepare()` |
| `source` y `status` deben ser claves conocidas | `Contacts::prepare()` |
| `owner_id` debe ser `0` o un usuario con `suiteka_crm_view` | `Capabilities::is_assignable_owner()` |
| Borrar un contacto borra sus etiquetas y las actividades que solo le pertenecen a él; las actividades compartidas con una empresa quedan en la empresa | `Contacts::delete()` |
| Borrar una empresa desvincula sus contactos (`company_id = 0`), no los borra | `Companies::delete()` |
| Borrar una empresa con contactos de otros responsables exige `suiteka_crm_edit_others` | `Admin_Companies` |
| Combinar dos contactos conserva todos los datos (ver sección 6) | `Duplicates::merge()` |
| Los eventos del sistema no se pueden borrar desde la interfaz | `Admin_Activities` |

## 5. Seguridad y permisos

### 5.1 Capacidades del CRM

| Capacidad | Permite |
| --- | --- |
| `suiteka_crm_view` | Entrar en el CRM y ver los registros **propios** |
| `suiteka_crm_view_others` | Ver registros de otros responsables y sin responsable |
| `suiteka_crm_edit` | Crear registros y editar los propios; añadir notas |
| `suiteka_crm_edit_others` | Editar registros ajenos y **cambiar el responsable** |
| `suiteka_crm_delete` | Borrar registros propios |
| `suiteka_crm_delete_others` | Borrar registros ajenos |
| `suiteka_crm_import` | Importar CSV (fase 7) |
| `suiteka_crm_export` | Exportar CSV (fase 7) |
| `suiteka_crm_manage` | Configurar el CRM: embudos, etapas, ajustes (fases 4 y siguientes) |

Al actualizar al esquema 2 (o al instalar desde cero) **todas** se conceden a cada rol que tenga `manage_options`, para que quien accedía en 1.0.0 siga accediendo igual. Después, el plugin no vuelve a concederlas: si un administrador quita una capacidad, se respeta. En multisitio, los superadministradores las tienen todas por definición de WordPress.

Un futuro rol «Comercial» tendría `view`, `edit` y `delete` (solo lo suyo). Mientras no exista, se pueden conceder las capacidades a cualquier rol con WP-CLI, p. ej. `wp cap add editor suiteka_crm_view suiteka_crm_edit`.

### 5.2 Reglas de acceso a registros

Meta-capacidades traducidas en el filtro `map_meta_cap`. Reciben el **ID del responsable** del registro:

| Meta-capacidad | Registro propio (`owner_id` = usuario) | Registro ajeno o sin responsable |
| --- | --- | --- |
| `suiteka_crm_view_record` | `view` | `view` + `view_others` |
| `suiteka_crm_edit_record` | `edit` | `edit` + `view_others` + `edit_others` |
| `suiteka_crm_delete_record` | `delete` | `delete` + `view_others` + `delete_others` |

- Los listados, búsquedas, filtros, contadores, la lista de empresas sugeridas y la pantalla de duplicados se restringen **en la consulta SQL** a `owner_id = usuario` cuando falta `view_others`.
- Los registros sin responsable (`owner_id = 0`, por ejemplo los migrados desde 1.0.0) se tratan como ajenos: solo los ve quien tenga `view_others`.
- Al crear, el responsable por defecto es el usuario actual. Sin `edit_others` no se puede elegir otro responsable.
- El aviso de duplicados solo muestra los registros que el usuario puede ver; de los demás indica únicamente cuántos hay.

### 5.3 Reglas aplicadas a cada petición

1. Comprobación de capacidad en el menú, en `load-{pantalla}` y antes de cada operación; la meta-capacidad del registro concreto antes de mostrarlo o modificarlo.
2. Escrituras solo por POST con `check_admin_referer()` y un nonce que incluye la operación y el ID (`suiteka_crm_save_contact_{id}`, `suiteka_crm_merge_contacts_{a}_{b}`…).
3. IDs con `absint()` y comprobación de existencia; valores con `wp_unslash()`, verificación de tipo (`is_string`), saneado y validación de formato, longitud y lista blanca.
4. Salidas escapadas según contexto; mensajes de la URL desde lista cerrada.
5. SQL con `$wpdb->prepare()` y `$wpdb->esc_like()`; nombres de tabla solo desde `Suiteka_Basic_CRM::table()`; `ORDER BY` desde lista blanca.
6. Sin REST, sin `admin-ajax`, sin shortcodes, sin bloques, sin salida pública.
7. Errores internos al registro de depuración, nunca en pantalla.

## 6. Duplicados y combinación

**Criterios** (cualquiera basta): mismo correo electrónico (sin distinguir mayúsculas), mismo teléfono normalizado (solo dígitos, al menos 6) o mismo nombre completo (según la intercalación de la base de datos). Limitación conocida: `+34 600 000 000` y `600 000 000` no se consideran iguales.

**Al guardar:** al crear, o al editar si cambia el nombre, el correo o el teléfono, se buscan coincidencias. Si las hay, no se guarda: se muestra la lista y un botón «Guardar de todos modos», y el formulario conserva lo escrito.

**Combinación** (pantalla «Posibles duplicados»):

1. Vista previa con ambos registros y el resultado; la persona elige cuál se conserva.
2. Requiere poder editar el que se conserva y borrar el otro.
3. En una transacción (si el motor la admite): los campos vacíos del conservado se rellenan con los del otro, sin sobrescribir; las etiquetas se unen; las actividades se reasignan; las notas del otro se guardan como una nota en el historial del conservado; se conserva la fecha de creación más antigua; se registra un evento `merged`; se borra el otro.
4. Si algo falla, se revierte y no se borra nada.

Los duplicados heredados de 1.0.0 **no** se combinan automáticamente: aparecen en la pantalla de posibles duplicados para que una persona decida.

## 7. Migraciones

`Suiteka_Basic_CRM::maybe_install()` se ejecuta en `admin_init` y en la activación. Si `suiteka_basic_crm_db_version` es distinta de `SUITEKA_BASIC_CRM_DB_VERSION`:

1. Toma el bloqueo con `add_option()` (atómico por la clave única de `option_name`). Si otra petición lo tiene, no hace nada.
2. Ejecuta `dbDelta()` con todas las tablas (añade columnas, índices y tablas; nunca borra).
3. Ejecuta en orden los pasos pendientes. Cada paso es idempotente y solo toca filas que aún no estén migradas, así que si se interrumpe, la siguiente petición lo continúa.
4. Actualiza la versión **solo** si todos los pasos terminan bien, y libera el bloqueo.

### Paso 1 → 2 (versión 1.1.0)

| Dato de 1.0.0 | Resultado |
| --- | --- |
| `full_name` | Se copia a `first_name`; `last_name` queda vacío. No se intenta separar nombre y apellidos automáticamente (los apellidos compuestos lo hacen poco fiable). `full_name` no cambia |
| `company` (texto) | Se crea una empresa por cada valor distinto y no vacío (las variantes de mayúsculas o acentos se agrupan según la intercalación) y se enlaza con `company_id`. La columna original no se modifica |
| `company` vacío | `company_id = 0` |
| Correo o teléfono vacíos | Siguen vacíos |
| `created_at` | Sin cambios; `updated_at` = `created_at` |
| Responsable | `owner_id = 0` (sin responsable) |
| Estado y origen | Vacíos: «Sin clasificar» y «Sin especificar» |
| Duplicados | Se conservan todos; aparecen en «Posibles duplicados» |
| Historial | Empieza vacío: no se inventan eventos pasados |
| Capacidades | Se conceden a los roles con `manage_options` |

**No se admite volver a 1.0.0** tras la migración: 1.0.0 seguiría funcionando con las columnas originales, pero no vería empresas, etiquetas ni actividades, y los contactos creados después no tendrían el texto de empresa. Haz una copia de seguridad antes de actualizar.

## 8. Interfaz

- Menú «Suiteka CRM»: Contactos, Añadir contacto, Empresas y Posibles duplicados. Cada entrada exige su capacidad.
- Listados con búsqueda, filtros, ordenación (encabezados con `aria-sort`) y paginación propia, que solo conserva parámetros de una lista cerrada.
- Fichas de contacto y empresa con datos, relaciones e historial; formularios separados para editar.
- El campo «Empresa» del contacto es un texto con sugerencias (`<datalist>`, sin JavaScript): si el nombre coincide con una empresa visible se enlaza; si no existe, se crea con el mismo responsable.
- Errores junto a cada campo con `aria-invalid` y `aria-describedby`, más un resumen enlazado al principio. Lo escrito se conserva tal cual.
- Diseño adaptable: tablas con desplazamiento horizontal propio y filtros apilados en pantallas estrechas.

## 9. Pruebas

- `tests/phpunit/`: integración con la librería oficial de pruebas de WordPress (`wp-phpunit/wp-phpunit`) sobre una base de datos real. Cada prueba se ejecuta en una transacción que se revierte.
- `tests/e2e/upgrade-from-1.0.0.sh`: instala 1.0.0 desde Git en un WordPress real, crea datos heredados, actualiza al código actual y comprueba que no se pierde ni altera nada; después prueba desactivación y desinstalación (con y sin borrado).
- `phpcs.xml.dist`: WordPress Coding Standards y PHPCompatibilityWP (PHP 7.0+).
- `.github/workflows/ci.yml`: ejecuta todo lo anterior en cada push.

El procedimiento detallado está en `SKILL.md` y los resultados reales en `docs/PRUEBAS.md`.
