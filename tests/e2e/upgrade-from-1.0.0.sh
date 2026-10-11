#!/usr/bin/env bash
#
# Prueba de extremo a extremo: instalación de 1.0.0, actualización al código
# actual, idempotencia de la migración, desactivación y desinstalación.
#
# Uso:
#   tests/e2e/upgrade-from-1.0.0.sh /ruta/a/wordpress [referencia-git-de-1.0.0]
#
# Si no hay git (p. ej. dentro de un contenedor), define LEGACY_DIR con una
# carpeta que contenga los archivos PHP de 1.0.0.
#
# ATENCIÓN: borra las tablas de ese WordPress. Úsalo solo con una instalación
# desechable de pruebas. Requiere WP-CLI (`wp`); no necesita el cliente mysql.

set -euo pipefail

WP_PATH="${1:?Indica la ruta de una instalación de WordPress de pruebas}"
REF="${2:-6c3e011}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PLUGIN_DIR="$WP_PATH/wp-content/plugins/suiteka-basic-crm"

WP=(wp "--path=$WP_PATH")
if [ "$(id -u)" = "0" ]; then
	WP+=(--allow-root)
fi

PASS=0

fail() {
	echo "FALLO: $*" >&2
	exit 1
}

check() {
	if [ "$2" = "$3" ]; then
		PASS=$((PASS + 1))
		echo "ok - $1"
	else
		fail "$1 (esperado «$3», obtenido «$2»)"
	fi
}

# Ejecuta SQL con $wpdb (conexión utf8mb4 de WordPress). Las SELECT imprimen el primer valor.
sql() {
	SUITEKA_E2E_SQL="$1" "${WP[@]}" eval '
		global $wpdb;
		$query = getenv( "SUITEKA_E2E_SQL" );
		if ( preg_match( "/^\s*(SELECT|SHOW)/i", $query ) ) {
			echo $wpdb->get_var( $query );
		} elseif ( false === $wpdb->query( $query ) ) {
			fwrite( STDERR, $wpdb->last_error );
			exit( 1 );
		}
	'
}

install_current_plugin() {
	rm -rf "$PLUGIN_DIR"
	mkdir -p "$PLUGIN_DIR"
	cp -R "$REPO/suiteka-basic-crm.php" "$REPO/uninstall.php" "$REPO/includes" "$REPO/admin" "$REPO/assets" "$PLUGIN_DIR/"
}

legacy_checksum() {
	"${WP[@]}" eval 'global $wpdb; echo md5( wp_json_encode( $wpdb->get_results( "SELECT id, full_name, email, phone, company, notes, created_at FROM {$wpdb->prefix}suiteka_crm_contacts ORDER BY id", ARRAY_N ) ) );'
}

table_count() {
	sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '${PREFIX}suiteka\\_crm\\_%';"
}

echo "# Instalación limpia de WordPress"
if "${WP[@]}" core is-installed 2>/dev/null; then
	"${WP[@]}" eval 'global $wpdb; foreach ( $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix ) . "%" ) ) as $t ) { $wpdb->query( "DROP TABLE `$t`" ); }'
fi
"${WP[@]}" core install --url=http://localhost --title=Pruebas --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null
"${WP[@]}" user create editor editor@example.test --role=editor --user_pass=editor >/dev/null
PREFIX="$("${WP[@]}" db prefix)"

echo "# Suiteka Basic CRM 1.0.0 (git $REF)"
rm -rf "$PLUGIN_DIR"
mkdir -p "$PLUGIN_DIR"
if [ -n "${LEGACY_DIR:-}" ]; then
	cp "$LEGACY_DIR/suiteka-basic-crm.php" "$LEGACY_DIR/uninstall.php" "$PLUGIN_DIR/"
else
	git -C "$REPO" show "$REF:suiteka-basic-crm.php" >"$PLUGIN_DIR/suiteka-basic-crm.php"
	git -C "$REPO" show "$REF:uninstall.php" >"$PLUGIN_DIR/uninstall.php"
fi
"${WP[@]}" plugin activate suiteka-basic-crm >/dev/null
check "1.0.0 instala el esquema 1" "$("${WP[@]}" option get suiteka_basic_crm_db_version)" "1"

# Datos heredados con casos límite: duplicados, variantes de mayúsculas,
# acentos y espacios en la empresa, campos vacíos, emojis, comillas y saltos de línea.
sql "INSERT INTO ${PREFIX}suiteka_crm_contacts (id, full_name, email, phone, company, notes, created_at) VALUES
(1, 'Ana García', 'ana@example.test', '600 111 222', 'ACME', 'Nota con \"comillas\" y apóstrofo \\'', '2025-01-01 10:00:00'),
(2, 'Ana García', 'ANA@example.test', '+34 600-111-222', 'Acme', '', '2025-02-01 10:00:00'),
(3, 'Bruno Díaz', '', '', '', '', '2025-03-01 10:00:00'),
(4, 'Carla 😀 López', 'carla@example.test', '(91) 555.12.34', 'Ácme ', 'Línea 1\nLínea 2', '2025-04-01 10:00:00'),
(5, 'Dani', 'dani@example.test', '', 'Beta S.L.', '', '2025-05-01 10:00:00'),
(6, 'Eva', 'eva@example.test', '', 'beta s.l.', '', '2025-06-01 10:00:00'),
(7, 'Fran', '', '600111222', 'Gamma', '', '2025-07-01 10:00:00');"
for i in $(seq 8 260); do
	VALUES+="($i, 'Persona $i', 'p$i@example.test', '', 'Empresa $((i % 230))', '', '2025-08-01 10:00:00'),"
done
sql "INSERT INTO ${PREFIX}suiteka_crm_contacts (id, full_name, email, phone, company, notes, created_at) VALUES ${VALUES%,};"

BEFORE="$(legacy_checksum)"
TOTAL="$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_contacts;")"
DISTINCT="$(sql "SELECT COUNT(DISTINCT company) FROM ${PREFIX}suiteka_crm_contacts WHERE company <> '';")"

echo "# Actualización al código actual"
install_current_plugin
"${WP[@]}" eval 'Suiteka_Basic_CRM::maybe_install();'

check "esquema actualizado a 2" "$("${WP[@]}" option get suiteka_basic_crm_db_version)" "2"
check "bloqueo de migración liberado" "$(sql "SELECT COUNT(*) FROM ${PREFIX}options WHERE option_name = 'suiteka_basic_crm_upgrade_lock';")" "0"
check "no se pierde ningún contacto" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_contacts;")" "$TOTAL"
check "columnas heredadas sin cambios (id, nombre, correo, teléfono, empresa, notas, fecha)" "$(legacy_checksum)" "$BEFORE"
check "cinco tablas del plugin" "$(table_count)" "5"
check "nombre completo copiado a first_name" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_contacts WHERE BINARY first_name <> BINARY full_name OR last_name <> '';")" "0"
check "updated_at = created_at" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_contacts WHERE updated_at IS NULL OR updated_at <> created_at;")" "0"
check "una empresa por cada valor distinto" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_companies;")" "$DISTINCT"
check "todos los contactos con empresa quedan enlazados" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_contacts WHERE company <> '' AND company_id = 0;")" "0"
check "los contactos sin empresa no se enlazan" "$(sql "SELECT company_id FROM ${PREFIX}suiteka_crm_contacts WHERE id = 3;")" "0"
check "ACME, Acme y «Ácme » son la misma empresa" "$(sql "SELECT COUNT(DISTINCT company_id) FROM ${PREFIX}suiteka_crm_contacts WHERE id IN (1, 2, 4);")" "1"
check "«Beta S.L.» y «beta s.l.» son la misma empresa" "$(sql "SELECT COUNT(DISTINCT company_id) FROM ${PREFIX}suiteka_crm_contacts WHERE id IN (5, 6);")" "1"
check "la empresa enlazada coincide con el texto heredado" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_contacts c JOIN ${PREFIX}suiteka_crm_companies co ON co.id = c.company_id WHERE c.company <> co.name;")" "0"
check "los contactos migrados quedan sin responsable" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_contacts WHERE owner_id <> 0;")" "0"
check "no se inventa historial" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_activities;")" "0"
check "el emoji se conserva" "$(sql "SELECT full_name FROM ${PREFIX}suiteka_crm_contacts WHERE id = 4;")" "Carla 😀 López"
check "el administrador recibe suiteka_crm_view" "$("${WP[@]}" eval 'echo (int) get_role( "administrator" )->has_cap( "suiteka_crm_view" );')" "1"
check "el administrador recibe suiteka_crm_delete_others" "$("${WP[@]}" eval 'echo (int) get_role( "administrator" )->has_cap( "suiteka_crm_delete_others" );')" "1"
check "el editor no recibe capacidades" "$("${WP[@]}" eval 'echo (int) get_role( "editor" )->has_cap( "suiteka_crm_view" );')" "0"
check "los duplicados heredados se detectan, no se combinan" "$("${WP[@]}" eval 'echo count( Suiteka_Basic_CRM_Duplicates::groups( null ) ) >= 3 ? "si" : "no";')" "si"

echo "# Idempotencia: repetir la migración no duplica nada"
COMPANIES="$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_companies;")"
"${WP[@]}" option update suiteka_basic_crm_db_version 1 >/dev/null
"${WP[@]}" eval 'Suiteka_Basic_CRM::maybe_install(); Suiteka_Basic_CRM::install();'
check "mismo número de empresas" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_companies;")" "$COMPANIES"
check "columnas heredadas siguen intactas" "$(legacy_checksum)" "$BEFORE"

echo "# Un bloqueo activo impide migrar a la vez"
"${WP[@]}" option update suiteka_basic_crm_db_version 1 >/dev/null
"${WP[@]}" option add suiteka_basic_crm_upgrade_lock "$(date +%s)" --autoload=no >/dev/null
"${WP[@]}" eval 'Suiteka_Basic_CRM::maybe_install();'
check "con bloqueo no se migra" "$("${WP[@]}" option get suiteka_basic_crm_db_version)" "1"
"${WP[@]}" option update suiteka_basic_crm_upgrade_lock "$(($(date +%s) - 3600))" >/dev/null
"${WP[@]}" eval 'Suiteka_Basic_CRM::maybe_install();'
check "un bloqueo caducado se reemplaza" "$("${WP[@]}" option get suiteka_basic_crm_db_version)" "2"

echo "# Desactivar y reactivar"
"${WP[@]}" plugin deactivate suiteka-basic-crm >/dev/null
"${WP[@]}" plugin activate suiteka-basic-crm >/dev/null
check "los contactos se conservan" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_contacts;")" "$TOTAL"
check "las empresas se conservan" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_companies;")" "$COMPANIES"

echo "# Desinstalar con la opción por defecto"
"${WP[@]}" plugin uninstall suiteka-basic-crm --deactivate >/dev/null
check "las tablas se conservan" "$(table_count)" "5"
check "los contactos se conservan" "$(sql "SELECT COUNT(*) FROM ${PREFIX}suiteka_crm_contacts;")" "$TOTAL"
check "la versión del esquema se conserva" "$("${WP[@]}" option get suiteka_basic_crm_db_version)" "2"

echo "# Reinstalar y desinstalar con borrado autorizado"
install_current_plugin
"${WP[@]}" plugin activate suiteka-basic-crm >/dev/null
check "reinstalar no altera los datos" "$(legacy_checksum)" "$BEFORE"
"${WP[@]}" option update suiteka_basic_crm_delete_data_on_uninstall 1 >/dev/null
"${WP[@]}" plugin uninstall suiteka-basic-crm --deactivate >/dev/null
check "se eliminan las tablas" "$(table_count)" "0"
check "se eliminan las opciones" "$(sql "SELECT COUNT(*) FROM ${PREFIX}options WHERE option_name LIKE 'suiteka\\_basic\\_crm\\_%';")" "0"
check "se retiran las capacidades" "$("${WP[@]}" eval 'echo (int) get_role( "administrator" )->has_cap( "suiteka_crm_view" );')" "0"

echo "# Instalación limpia del código actual"
install_current_plugin
"${WP[@]}" plugin activate suiteka-basic-crm >/dev/null
check "esquema 2 desde cero" "$("${WP[@]}" option get suiteka_basic_crm_db_version)" "2"
check "cinco tablas desde cero" "$(table_count)" "5"
check "capacidades concedidas desde cero" "$("${WP[@]}" eval 'echo (int) get_role( "administrator" )->has_cap( "suiteka_crm_edit" );')" "1"

echo "# Resultado: $PASS comprobaciones correctas"
