# Suiteka Basic CRM

CRM ligero para el escritorio de WordPress, pensado para autónomos y pequeñas empresas. Mantenido por [Suiteka](https://suiteka.com/).

Gestiona contactos y empresas, con etiquetas, responsables, historial de actividades y detección de duplicados, sin dependencias externas y sin mostrar nada en la parte pública del sitio. Las oportunidades, las tareas, el panel y la importación/exportación están planificados por fases en la [hoja de ruta](docs/ROADMAP.md).

> **Estado:** versión 1.1.0 en desarrollo, pendiente de revisión. La licencia aún no está decidida: no se puede redistribuir (ver [Licencia](#licencia)).

## Funciones

**Contactos**
- Nombre y apellidos, correo electrónico, teléfono, cargo, empresa, origen, estado, etiquetas, responsable y notas, con fechas de creación y actualización.
- Ficha con todos los datos y su historial.
- Búsqueda por nombre, correo, teléfono o empresa; filtros por estado, origen, etiqueta, empresa y responsable; ordenación por columnas; paginación de 20 en 20.

**Empresas**
- Nombre (único), web, correo, teléfono, ciudad, responsable y notas.
- Ficha con sus contactos y el historial de la empresa y de sus contactos.
- Al escribir una empresa nueva en un contacto, se crea automáticamente.

**Historial**
- Eventos automáticos (alta, campos modificados, combinación) y notas, llamadas y reuniones registradas a mano, con fecha opcional.

**Duplicados**
- Aviso antes de guardar si coincide el correo, el teléfono (comparando solo dígitos) o el nombre, con la opción «Guardar de todos modos».
- Pantalla «Posibles duplicados» con vista previa y combinación segura: no se pierde ningún dato.

**Accesibilidad y uso**
- Formularios que funcionan sin JavaScript, con errores junto a cada campo (`aria-invalid`, `aria-describedby`) y un resumen enlazado; lo escrito se conserva si hay errores.
- Pantallas adaptadas a móvil y navegables con teclado.

## Requisitos

- WordPress 5.2 o superior (probado en 5.2.4 y 7.1.3).
- PHP 7.0 o superior (probado en 7.2 y 8.3; sintaxis verificada en 7.0).
- MySQL o MariaDB (la base de datos estándar de WordPress).

## Instalación

1. Copia la carpeta `suiteka-basic-crm` en `wp-content/plugins/` (o sube un `.zip` desde **Plugins → Añadir nuevo → Subir plugin**). Para generar el `.zip` sin archivos de desarrollo: `git archive --format=zip --prefix=suiteka-basic-crm/ -o suiteka-basic-crm.zip HEAD`.
2. Activa **Suiteka Basic CRM** en **Plugins**.
3. Se crean las tablas del plugin y los roles con `manage_options` (administradores) reciben las capacidades del CRM.

No hay pantalla de ajustes: el plugin funciona al activarlo.

## Actualizar desde 1.0.0

1. **Haz una copia de seguridad** de la base de datos (por ejemplo, `wp db export copia.sql`).
2. Sustituye los archivos del plugin y entra en el escritorio: la migración se ejecuta sola, una única vez.
3. Qué hace la migración (ver [ARQUITECTURA.md](docs/ARQUITECTURA.md) §7):
   - El nombre completo pasa al campo «Nombre» sin dividirlo; los apellidos quedan vacíos para que los completes si quieres.
   - Se crea una empresa por cada valor distinto del antiguo campo «Empresa» (sin distinguir mayúsculas ni acentos) y se enlaza con sus contactos.
   - Los contactos migrados quedan «sin responsable»; estado y origen quedan sin especificar.
   - Ningún contacto se borra ni se combina: los posibles duplicados aparecen en **Suiteka CRM → Posibles duplicados**.
   - Las columnas originales no se modifican.
4. No se admite volver a 1.0.0 después de migrar; si lo necesitas, restaura la copia de seguridad.

## Permisos

El CRM usa capacidades propias; no exige ser administrador de WordPress:

| Capacidad | Permite |
| --- | --- |
| `suiteka_crm_view` | Entrar en el CRM y ver los registros propios |
| `suiteka_crm_view_others` | Ver los registros de otros responsables y los que no tienen responsable |
| `suiteka_crm_edit` | Crear registros y editar los propios |
| `suiteka_crm_edit_others` | Editar registros ajenos y cambiar el responsable |
| `suiteka_crm_delete` / `suiteka_crm_delete_others` | Borrar registros propios / ajenos |
| `suiteka_crm_import`, `suiteka_crm_export`, `suiteka_crm_manage` | Reservadas para importación, exportación y ajustes (fases siguientes) |

Al activar o actualizar, **todas** se conceden a los roles que tienen `manage_options`. Para que, por ejemplo, un comercial con rol Editor trabaje solo con sus propios registros:

```sh
wp cap add editor suiteka_crm_view suiteka_crm_edit suiteka_crm_delete
```

Un usuario sin `suiteka_crm_view_others` solo ve sus registros en listados, búsquedas, filtros, sugerencias y duplicados. Las reglas completas están en [ARQUITECTURA.md](docs/ARQUITECTURA.md) §5.

## Seguridad

- Comprobación de capacidad en cada pantalla y de permisos por registro en cada operación.
- Toda escritura por POST con un nonce ligado a la operación y al registro.
- Validación y saneado de todas las entradas; escape de todas las salidas.
- Consultas con `$wpdb->prepare()`, `LIKE` escapado y ordenación por lista blanca.
- Sin páginas públicas, shortcodes, bloques ni endpoints REST: los datos solo son visibles en el escritorio, para usuarios autorizados.

## Base de datos

| Tabla (`{prefijo}suiteka_crm_…`) | Contenido |
| --- | --- |
| `contacts` | Contactos (incluye las columnas de 1.0.0) |
| `companies` | Empresas |
| `tags`, `tag_relationships` | Etiquetas y su asignación |
| `activities` | Historial |

Opciones: `suiteka_basic_crm_db_version` (versión del esquema), `suiteka_basic_crm_upgrade_lock` (temporal durante la migración) y `suiteka_basic_crm_delete_data_on_uninstall` (solo si la creas). Esquema completo en [ARQUITECTURA.md](docs/ARQUITECTURA.md) §3.

## Copias de seguridad y conservación de datos

- Los datos están en tablas propias de la base de datos de WordPress: cualquier copia completa de la base de datos (WP-CLI, phpMyAdmin o el plugin de copias que uses) los incluye.
- **Desactivar** el plugin no borra nada.
- **Desinstalar** (eliminar el plugin desde WordPress) también conserva tablas, opciones y capacidades.
- Para borrar todo al desinstalar, crea antes la opción de borrado:

  ```sh
  wp option update suiteka_basic_crm_delete_data_on_uninstall 1
  ```

  Al desinstalar se eliminan las cinco tablas, las opciones y las capacidades del CRM de todos los roles. No se puede deshacer: haz una copia antes.

## Limitaciones conocidas

- Los teléfonos se comparan por sus dígitos: `+34 600 000 000` y `600 000 000` no se consideran duplicados.
- El nombre de empresa es único en todo el CRM; si un comercial escribe una empresa que existe pero no puede ver, se le indica que pida acceso.
- La búsqueda usa `LIKE '%texto%'`, adecuada para decenas de miles de registros.
- El historial muestra las 50 actividades más recientes de cada ficha.
- No se ha probado en multisitio ni con MySQL 5.7 (ver [PRUEBAS.md](docs/PRUEBAS.md)).

## Licencia

**Pendiente de decisión del mantenedor.** Consulta [LICENSE](LICENSE) y la sección 5 de la [auditoría](docs/AUDITORIA-TECNICA.md). Hasta que se publique una licencia, no se concede ningún permiso de uso, copia o redistribución más allá de lo que permita la ley.

## Desarrollo

Las reglas de desarrollo, pruebas y seguridad están en [SKILL.md](SKILL.md). Resumen:

```sh
composer install
vendor/bin/phpcs
vendor/bin/phpunit                                  # requiere variables WP_CORE_DIR y WP_TESTS_DB_*
tests/e2e/upgrade-from-1.0.0.sh /ruta/a/wordpress   # ¡borra las tablas de ese WordPress!
```
