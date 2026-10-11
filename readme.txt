=== Suiteka Basic CRM ===
Tags: crm, contactos, empresas, clientes, contacts
Requires at least: 5.2
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.1.0

CRM ligero en el escritorio de WordPress: contactos, empresas, etiquetas, historial y duplicados.

== Description ==

Suiteka Basic CRM es un CRM ligero para autónomos y pequeñas empresas que funciona dentro del escritorio de WordPress. Mantenido por Suiteka (https://suiteka.com/).

Funciones:

* Contactos con nombre y apellidos, correo electrónico, teléfono, cargo, empresa, origen, estado, etiquetas, responsable, notas y fechas de creación y actualización.
* Empresas con sus contactos; se crean automáticamente al escribir una empresa nueva en un contacto.
* Historial de cada contacto y empresa: eventos automáticos y notas, llamadas y reuniones.
* Búsqueda, filtros (estado, origen, etiqueta, empresa, responsable), ordenación y paginación.
* Aviso de posibles duplicados al guardar y combinación segura con vista previa.
* Permisos propios del CRM: se puede dar acceso a comerciales sin hacerlos administradores, y cada uno puede ver solo sus registros.
* Formularios accesibles que funcionan sin JavaScript y conservan lo escrito si hay errores.

Qué no incluye todavía: oportunidades y embudos, tareas, panel de métricas, importación y exportación (planificados). No envía correos ni se conecta a servicios externos.

Seguridad: cada pantalla y cada operación comprueban permisos; toda escritura usa POST y nonces; las entradas se validan y sanean, las salidas se escapan y las consultas usan `$wpdb->prepare()`. Los datos no se muestran en la parte pública del sitio.

== Installation ==

1. Copia la carpeta `suiteka-basic-crm` en `wp-content/plugins/` o sube el archivo .zip desde «Plugins → Añadir nuevo → Subir plugin».
2. Activa el plugin en «Plugins».
3. Abre «Suiteka CRM → Añadir contacto» para crear tu primer contacto.

Al activarse se crean las tablas `{prefijo}suiteka_crm_contacts`, `_companies`, `_tags`, `_tag_relationships` y `_activities`, y los roles con `manage_options` reciben las capacidades del CRM.

== Frequently Asked Questions ==

= ¿Se borran los datos si desactivo o elimino el plugin? =

No. Desactivar no borra nada y desinstalar conserva tablas, opciones y capacidades. Para borrarlo todo al desinstalar, crea antes la opción `suiteka_basic_crm_delete_data_on_uninstall` con valor `1` (por ejemplo, `wp option update suiteka_basic_crm_delete_data_on_uninstall 1`). Haz una copia de seguridad antes.

= ¿Quién puede ver los contactos? =

Quien tenga la capacidad `suiteka_crm_view` ve sus propios registros; con `suiteka_crm_view_others` ve también los de los demás. Al activar el plugin, los administradores reciben todas las capacidades. Ejemplo para que los editores trabajen solo con lo suyo: `wp cap add editor suiteka_crm_view suiteka_crm_edit suiteka_crm_delete`.

= ¿Qué pasa con mis contactos al actualizar desde 1.0.0? =

Se conservan todos sin cambios en sus columnas originales. El nombre completo pasa al campo «Nombre», se crea una empresa por cada valor distinto del antiguo campo «Empresa» y se enlaza. No se combina ningún duplicado automáticamente: aparecen en «Posibles duplicados».

= ¿Cómo hago una copia de seguridad? =

Los datos están en la base de datos de WordPress; cualquier copia completa de la base de datos los incluye (por ejemplo, `wp db export copia.sql`).

== Upgrade Notice ==

= 1.1.0 =
Añade empresas, etiquetas, historial, duplicados y permisos propios. Migra los contactos de 1.0.0 sin borrar nada. Haz una copia de seguridad antes de actualizar: no se admite volver a 1.0.0.

== Changelog ==

= 1.1.0 =
* Nuevo: empresas como entidad propia, con migración automática del campo de texto «Empresa» de 1.0.0.
* Nuevo: nombre y apellidos, cargo, origen, estado, etiquetas, responsable y fecha de actualización en los contactos.
* Nuevo: historial de actividades (eventos automáticos, notas, llamadas y reuniones) en contactos y empresas.
* Nuevo: búsqueda ampliada, filtros, ordenación por columnas y ficha de cada registro.
* Nuevo: aviso de posibles duplicados y pantalla para combinarlos sin perder datos.
* Nuevo: capacidades propias del CRM (`suiteka_crm_*`) y reglas de acceso por responsable; se conceden a los roles con `manage_options`.
* Mejora: errores de formulario junto a cada campo con atributos ARIA; los valores con etiquetas HTML ya no se recortan en silencio, se pide corregirlos.
* Mejora: migraciones idempotentes con bloqueo frente a ejecuciones simultáneas.
* Corrección: los enlaces de paginación ya no repiten el aviso de la acción anterior.
* Desinstalación: con la opción de borrado activa también elimina las tablas nuevas y las capacidades.
* Desarrollo: pruebas PHPUnit de integración, prueba de actualización desde 1.0.0, PHPCS e integración continua.

= 1.0.0 =
* Primera versión: gestión básica de contactos (crear, listar, buscar, editar y eliminar).
