=== Suiteka Basic CRM ===
Tags: crm, contactos, clientes, agenda, contacts
Requires at least: 5.2
Requires PHP: 7.0
Stable tag: 1.0.0

Gestor básico de contactos en el panel de administración de WordPress.

== Description ==

Suiteka Basic CRM es un CRM mínimo para guardar y consultar contactos desde el escritorio de WordPress. Mantenido por Suiteka (https://suiteka.com/).

Funciones:

* Menú propio «Suiteka CRM» con las pantallas «Contactos» y «Añadir contacto».
* Alta, edición y eliminación de contactos, con confirmación antes de eliminar.
* Campos: nombre completo (obligatorio), correo electrónico, teléfono, empresa, notas y fecha de creación.
* Búsqueda por nombre, correo electrónico o empresa.
* Listado paginado de 20 en 20.
* Mensajes de éxito y error, y pantalla de bienvenida cuando no hay contactos.

Qué no incluye: oportunidades, embudos de venta, tareas, calendario, facturación, campañas, automatizaciones, integraciones externas ni API pública.

Seguridad: solo los usuarios con la capacidad `manage_options` pueden acceder. Todas las operaciones de escritura usan nonces y comprobación de permisos, los datos se sanitizan al guardar y se escapan al mostrarlos, y las consultas usan `$wpdb->prepare()`. Los contactos no se muestran en la parte pública del sitio.

== Installation ==

1. Copia la carpeta `suiteka-basic-crm` en `wp-content/plugins/` o sube el archivo .zip desde «Plugins → Añadir nuevo → Subir plugin».
2. Activa el plugin en «Plugins».
3. Abre «Suiteka CRM → Añadir contacto» para crear tu primer contacto.

Al activarse se crea la tabla `{prefijo}suiteka_crm_contacts`.

== Frequently Asked Questions ==

= ¿Se borran los contactos si desactivo o elimino el plugin? =

No. Desactivar no borra nada y desinstalar conserva la tabla por defecto. Para borrarla al desinstalar, crea antes la opción `suiteka_basic_crm_delete_data_on_uninstall` con valor `1` (por ejemplo, `wp option update suiteka_basic_crm_delete_data_on_uninstall 1`).

= ¿Quién puede ver los contactos? =

Solo los usuarios con la capacidad `manage_options` (administradores).

== Changelog ==

= 1.0.0 =
* Primera versión: gestión básica de contactos (crear, listar, buscar, editar y eliminar).
