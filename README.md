# Suiteka Basic CRM

Gestor de contactos sencillo para el panel de administración de WordPress, mantenido por [Suiteka](https://suiteka.com/).

Es un CRM mínimo: guarda personas de contacto y permite consultarlas, buscarlas, editarlas y eliminarlas. No incluye oportunidades, embudos, tareas, integraciones ni automatizaciones.

## Funciones

- Menú propio **Suiteka CRM** en el escritorio de WordPress, con las pantallas **Contactos** y **Añadir contacto**.
- Alta, edición y eliminación de contactos (la eliminación pide confirmación y se hace por POST con nonce).
- Campos: nombre completo (obligatorio), correo electrónico, teléfono, empresa, notas y fecha de creación (automática).
- Búsqueda por nombre, correo electrónico o empresa.
- Listado paginado de 20 en 20, del más reciente al más antiguo.
- Mensajes de éxito y error, y una pantalla de bienvenida cuando aún no hay contactos.

## Requisitos

- WordPress 5.2 o superior.
- PHP 7.0 o superior.
- MySQL o MariaDB (la base de datos estándar de WordPress).

## Instalación manual

1. Descarga el repositorio y copia la carpeta `suiteka-basic-crm` en `wp-content/plugins/` (o sube un `.zip` de esa carpeta desde **Plugins → Añadir nuevo → Subir plugin**).
2. Activa **Suiteka Basic CRM** en **Plugins**.
3. Al activarlo se crea la tabla `{prefijo}suiteka_crm_contacts` (por ejemplo, `wp_suiteka_crm_contacts`).

## Uso básico

1. Ve a **Suiteka CRM → Añadir contacto**, rellena al menos el nombre completo y pulsa **Crear contacto**.
2. En **Suiteka CRM → Contactos** verás el listado. Usa el buscador para filtrar por nombre, correo o empresa.
3. Bajo el nombre de cada contacto tienes los enlaces **Editar** y **Eliminar**. Eliminar muestra una pantalla de confirmación antes de borrar.

## Seguridad

- Solo los usuarios con la capacidad `manage_options` (administradores) pueden ver o modificar contactos.
- Todas las operaciones de escritura usan nonces ligados al contacto concreto y se comprueban los permisos en cada petición.
- Los datos se validan y sanitizan al guardar y se escapan al mostrarlos.
- Las consultas con datos variables usan `$wpdb->prepare()`.
- No hay páginas públicas, shortcodes ni endpoints REST: los contactos solo son visibles en el escritorio.

## Conservación de datos

- **Desactivar** el plugin no borra nada.
- **Desinstalar** (eliminar el plugin desde WordPress) también conserva la tabla de contactos por defecto.
- Para borrar la tabla al desinstalar, crea antes la opción `suiteka_basic_crm_delete_data_on_uninstall` con valor `1`, por ejemplo con WP-CLI:

  ```sh
  wp option update suiteka_basic_crm_delete_data_on_uninstall 1
  ```

  Después, al desinstalar, se eliminan la tabla y las opciones del plugin. Esta acción no se puede deshacer: haz una copia de seguridad antes.

## Licencia

Pendiente de decisión del mantenedor. Consulta el archivo [LICENSE](LICENSE). Hasta que se publique una licencia, no se concede ningún permiso de uso, copia o redistribución más allá de lo que permita la ley.

## Desarrollo

Las reglas de desarrollo y mantenimiento están en [SKILL.md](SKILL.md).
