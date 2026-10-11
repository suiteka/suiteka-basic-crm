# Hoja de ruta

Cada fase se entrega en una rama propia creada desde `main`, con sus migraciones, pruebas y documentación, y se integra solo después de revisarla. No se desarrolla más de una fase de producto a la vez. Ninguna fase se publica como versión de producción sin autorización del mantenedor.

Estado: ✅ hecho · 🔶 en curso · ⏳ pendiente · ⛔ bloqueado por una decisión.

## Fase 0 — Base técnica ✅ (1.1.0)

- ✅ Auditoría técnica (`docs/AUDITORIA-TECNICA.md`).
- ✅ Arquitectura modular, modelo de datos y reglas de integridad (`docs/ARQUITECTURA.md`).
- ✅ Capacidades propias y reglas de acceso por registro.
- ✅ Migraciones versionadas, idempotentes y con bloqueo.
- ✅ PHPUnit de integración, prueba de actualización desde 1.0.0, PHPCS y CI.

## Fase 1 — Contactos y empresas ✅ (1.1.0)

- ✅ Nombre y apellidos, cargo, origen, estado, etiquetas, responsable, fechas de creación y actualización.
- ✅ Empresas como entidad, con relación empresa → contactos.
- ✅ Búsqueda, filtros, ordenación y paginación.
- ✅ Aviso de posibles duplicados y combinación segura.
- ✅ Historial básico (eventos, notas, llamadas y reuniones) en contactos y empresas.
- ✅ Formularios accesibles con errores por campo.
- ✅ Migración de los contactos de 1.0.0 sin pérdida de datos.

Queda fuera de esta fase, a propósito: acciones masivas, edición de etiquetas (renombrar o fusionar) y etiquetas en empresas.

## Fase 2 — Oportunidades y ventas ⏳ (1.2.0)

- Tablas `pipelines`, `pipeline_stages`, `opportunities` y `opportunity_stage_history` (diseño en `ARQUITECTURA.md` §3.2).
- Embudo predeterminado con etapas editables (`suiteka_crm_manage`); estados ganado y perdido con motivo de pérdida obligatorio.
- Vista de lista con filtros (embudo, etapa, estado, responsable, fecha prevista) y tablero Kanban **accesible**: columnas por etapa como listas HTML; mover una oportunidad es un formulario POST con un selector de etapa por tarjeta, que funciona con teclado y sin JavaScript. Arrastrar y soltar sería solo una mejora opcional.
- Registro de cada cambio de etapa y estado en el historial de la oportunidad y en `activities`.
- Relación con contacto y empresa validada (que existan y que el usuario pueda verlos).
- Moneda por oportunidad (ISO 4217) con moneda predeterminada en una opción; no se suman monedas distintas.
- Pruebas: permisos por registro, cambios de etapa, validación de relaciones, migración 2 → 3.

## Fase 3 — Tareas y actividades ⏳ (1.3.0)

- Tabla `tasks` con título, descripción, fecha límite, prioridad, estado y responsable, relacionable con contacto, empresa y oportunidad.
- Vistas «Mis tareas», «Pendientes» y «Vencidas»; aviso en el escritorio de WordPress con el número de tareas vencidas del usuario.
- Recordatorios **solo dentro del escritorio**. El envío de correos queda desactivado por defecto y solo se activaría con una opción explícita y consentimiento de cada usuario.
- Edición de actividades por su autor y filtros del historial por tipo.

## Fase 4 — Panel e informes ⏳ (1.4.0)

- Panel con: contactos, empresas, oportunidades abiertas, valor potencial por moneda, ventas ganadas en el periodo y tareas pendientes y vencidas.
- Filtro temporal (este mes, trimestre, año, rango) sobre fechas reales (`closed_at`, `created_at`, `due_at`).
- Todas las cifras respetan el ámbito de visibilidad del usuario.
- Consultas agregadas indexadas; caché con transitorios de vida corta solo si las mediciones lo justifican. Sin gráficos decorativos.

## Fase 5 — Importación y exportación ⏳ (1.5.0)

- CSV UTF-8 (con o sin BOM), separador `,` o `;`, máximo 2 MB y 5 000 filas por importación.
- Validación de encabezados contra una lista conocida, vista previa de las primeras filas, informe de errores por fila y detección de duplicados reutilizando `Duplicates`.
- Importación en lotes con la misma validación que los formularios (`Contacts::prepare()`).
- Exportación de contactos y empresas con `suiteka_crm_export`, limitada al ámbito de visibilidad del usuario, por POST con nonce y protección contra inyección de fórmulas (prefijo `'` en celdas que empiezan por `=`, `+`, `-`, `@`).
- Integración con las herramientas de privacidad de WordPress (exportar y borrar datos personales por correo electrónico).

## Fase 6 — Equipo comercial ⏳

- Rol «Comercial» con `view`, `edit` y `delete` sobre lo propio, creado bajo demanda desde una pantalla de ajustes.
- Reasignación masiva de responsables.
- Pantalla de ajustes (`suiteka_crm_manage`) para la moneda, las listas de origen y estado y la opción de borrar datos al desinstalar (con confirmación).

## Mejoras técnicas pendientes

- ⏳ Búsqueda más eficiente para volúmenes grandes (índice `FULLTEXT` o búsqueda por prefijo) — solo si se mide un problema real.
- ⏳ Prueba en multisitio y en MySQL 5.7 con `utf8` (no disponibles en el entorno de la auditoría).
- ⏳ Revisión con lector de pantalla real.
- ⏳ Revisar los requisitos mínimos. WordPress 5.2 y PHP 7.0 se mantienen porque se han probado (WordPress 5.2 con PHP 7.2) y no impiden nada de lo previsto. Elevarlos (por ejemplo, a WordPress 6.2 y PHP 7.4) simplificaría las pruebas; es una decisión del mantenedor que obligaría a actualizar a la vez la cabecera, `readme.txt`, `README.md` y `SKILL.md`.

## Decisiones pendientes del mantenedor

- ⛔ **Licencia** (ver `docs/AUDITORIA-TECNICA.md` §5). Bloquea cualquier publicación o redistribución.
- ⏳ Cuenta y slug de WordPress.org (`Contributors` en `readme.txt`), si se publica allí.
- ⏳ Aprobación de cada fase antes de integrarla en `main`.
