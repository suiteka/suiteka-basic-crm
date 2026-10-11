#!/usr/bin/env bash
#
# Prueba de humo por HTTP de las pantallas del plugin en un WordPress real.
#
# Uso:
#   tests/e2e/http-smoke.sh http://localhost:8080 usuario-admin contraseña [usuario-sin-acceso contraseña]
#
# Crea, consulta, combina y borra registros de prueba. Úsalo solo en un sitio
# de pruebas con el plugin activo. Requiere curl.

set -euo pipefail

BASE="${1:?URL del sitio}"
ADMIN_USER="${2:?Usuario administrador}"
ADMIN_PASS="${3:?Contraseña}"
OTHER_USER="${4:-}"
OTHER_PASS="${5:-}"

ADMIN_URL="$BASE/wp-admin/admin.php"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
PASS=0
RUN="$$"

fail() {
	echo "FALLO: $*" >&2
	exit 1
}

ok() {
	PASS=$((PASS + 1))
	echo "ok - $1"
}

login() {
	curl -s -c "$2" -b "$2" -o /dev/null "$BASE/wp-login.php"
	curl -s -c "$2" -b "$2" -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$3" -d "wp-submit=Acceder&testcookie=1" "$BASE/wp-login.php"
}

# GET autenticado: guarda el HTML en $WORK/page.html y devuelve el código HTTP.
get() {
	curl -s -b "$JAR" -o "$WORK/page.html" -w "%{http_code}" "$ADMIN_URL?$1"
}

# POST autenticado: devuelve "código destino".
post() {
	local query="$1"
	shift
	curl -s -b "$JAR" -o "$WORK/page.html" -w "%{http_code} %{redirect_url}" "$@" "$ADMIN_URL?$query"
}

nonce() {
	grep -o 'name="_wpnonce" value="[^"]*"' "$WORK/page.html" | sed -n "${1:-1}p" | sed 's/.*value="//;s/"//'
}

assert_page() {
	local code="$1" label="$2"
	[ "$code" = "200" ] || fail "$label: HTTP $code"
	if grep -qiE "(Fatal error|Warning:|Notice:|Deprecated:)</b>|There has been a critical error|Ha habido un error crítico" "$WORK/page.html"; then
		fail "$label: la página contiene errores de PHP"
	fi
	ok "$label"
}

JAR="$WORK/admin.jar"
login "$ADMIN_USER" "$JAR" "$ADMIN_PASS"

assert_page "$(get 'page=suiteka-crm')" "listado de contactos"
assert_page "$(get 'page=suiteka-crm-new')" "formulario de nuevo contacto"
assert_page "$(get 'page=suiteka-crm-companies')" "listado de empresas"
assert_page "$(get 'page=suiteka-crm-companies&action=new')" "formulario de nueva empresa"
assert_page "$(get 'page=suiteka-crm-duplicates')" "posibles duplicados"
grep -q 'assets/css/admin.css' "$WORK/page.html" && ok "se carga la hoja de estilos" || fail "no se carga admin.css"

echo "# Empresa"
get 'page=suiteka-crm-companies&action=new' >/dev/null
RESULT="$(post 'page=suiteka-crm-companies&action=new' --data-urlencode "_wpnonce=$(nonce)" -d 'suiteka_crm_operation=save_company&company_id=0' --data-urlencode "name=Humo $RUN SL" -d 'website=humo.example&city=Madrid&owner_id=1&notes=')"
[ "${RESULT%% *}" = "302" ] || fail "crear empresa: $RESULT"
COMPANY_ID="$(echo "$RESULT" | grep -o 'id=[0-9]*' | cut -d= -f2)"
ok "crear empresa (#$COMPANY_ID)"
assert_page "$(get "page=suiteka-crm-companies&action=view&id=$COMPANY_ID")" "ficha de empresa"

echo "# Contacto con errores: no se guarda y conserva lo escrito"
get 'page=suiteka-crm-new' >/dev/null
RESULT="$(post 'page=suiteka-crm-new' --data-urlencode "_wpnonce=$(nonce)" -d 'suiteka_crm_operation=save&contact_id=0&first_name=&email=malo&phone=abc')"
[ "${RESULT%% *}" = "200" ] || fail "con errores no debe redirigir: $RESULT"
grep -q 'aria-invalid="true"' "$WORK/page.html" && grep -q 'value="malo"' "$WORK/page.html" && ok "errores accesibles y datos conservados" || fail "errores sin marcar"

echo "# Contacto válido"
get 'page=suiteka-crm-new' >/dev/null
RESULT="$(post 'page=suiteka-crm-new' --data-urlencode "_wpnonce=$(nonce)" -d 'suiteka_crm_operation=save&contact_id=0' --data-urlencode "first_name=Humo$RUN" -d 'last_name=Prueba' --data-urlencode "email=humo$RUN@example.test" --data-urlencode "company_name=Humo $RUN SL" -d 'status=lead&source=web&tags=uno, dos&owner_id=1&notes=Nota')"
[ "${RESULT%% *}" = "302" ] || fail "crear contacto: $RESULT"
CONTACT_ID="$(echo "$RESULT" | grep -o 'id=[0-9]*' | cut -d= -f2)"
ok "crear contacto (#$CONTACT_ID)"
assert_page "$(get "page=suiteka-crm&action=view&id=$CONTACT_ID")" "ficha de contacto"
grep -q "Humo $RUN SL" "$WORK/page.html" && ok "el contacto queda enlazado a la empresa" || fail "sin empresa"

echo "# Historial"
RESULT="$(post "page=suiteka-crm&action=view&id=$CONTACT_ID" --data-urlencode "_wpnonce=$(nonce)" -d "suiteka_crm_operation=add_activity&object_id=$CONTACT_ID&activity_type=call&activity_date=" --data-urlencode 'activity_content=Llamada de humo')"
[ "${RESULT%% *}" = "302" ] || fail "añadir actividad: $RESULT"
get "page=suiteka-crm&action=view&id=$CONTACT_ID" >/dev/null
grep -q "Llamada de humo" "$WORK/page.html" && ok "la actividad aparece en el historial" || fail "actividad ausente"

echo "# Duplicado: aviso y confirmación"
get 'page=suiteka-crm-new' >/dev/null
N="$(nonce)"
FIELDS=(-d 'suiteka_crm_operation=save&contact_id=0&first_name=Otra&owner_id=1' --data-urlencode "email=HUMO$RUN@example.test")
RESULT="$(post 'page=suiteka-crm-new' --data-urlencode "_wpnonce=$N" "${FIELDS[@]}")"
[ "${RESULT%% *}" = "200" ] && grep -q 'name="confirm_duplicates"' "$WORK/page.html" && ok "aviso de posible duplicado" || fail "sin aviso de duplicado: $RESULT"
RESULT="$(post 'page=suiteka-crm-new' --data-urlencode "_wpnonce=$N" "${FIELDS[@]}" -d 'confirm_duplicates=1')"
[ "${RESULT%% *}" = "302" ] || fail "guardar de todos modos: $RESULT"
DUP_ID="$(echo "$RESULT" | grep -o 'id=[0-9]*' | cut -d= -f2)"
ok "guardar de todos modos (#$DUP_ID)"

echo "# Búsqueda, filtros, orden y paginación"
assert_page "$(get "page=suiteka-crm&s=humo$RUN")" "búsqueda"
grep -q "Humo$RUN" "$WORK/page.html" && ok "la búsqueda encuentra el contacto" || fail "búsqueda sin resultados"
assert_page "$(get 'page=suiteka-crm&s=%25%27%22')" "búsqueda con % y comillas"
assert_page "$(get "page=suiteka-crm&status=lead&source=web&company_id=$COMPANY_ID&orderby=name&order=asc")" "filtros y orden"
grep -q 'aria-sort="ascending"' "$WORK/page.html" && ok "aria-sort en la columna ordenada" || fail "sin aria-sort"

echo "# Combinar"
assert_page "$(get 'page=suiteka-crm-duplicates')" "duplicados detectados"
assert_page "$(get "page=suiteka-crm-duplicates&action=merge&a=$CONTACT_ID&b=$DUP_ID")" "vista previa de combinación"
RESULT="$(post "page=suiteka-crm-duplicates&action=merge&a=$CONTACT_ID&b=$DUP_ID" --data-urlencode "_wpnonce=$(nonce)" -d "suiteka_crm_operation=merge&contact_a=$CONTACT_ID&contact_b=$DUP_ID&keep=$CONTACT_ID")"
echo "$RESULT" | grep -q "message=merged" && ok "combinar contactos" || fail "combinar: $RESULT"
[ "$(get "page=suiteka-crm&action=view&id=$DUP_ID")" = "200" ] && grep -q "no existe" "$WORK/page.html" && ok "el duplicado desaparece" || fail "el duplicado sigue existiendo"

echo "# Borrar mediante GET no borra"
assert_page "$(get "page=suiteka-crm&action=delete&id=$CONTACT_ID")" "confirmación de borrado"
assert_page "$(get "page=suiteka-crm&action=view&id=$CONTACT_ID")" "sigue existiendo tras el GET"
grep -q "Humo$RUN" "$WORK/page.html" || fail "el GET ha borrado el contacto"

echo "# Nonce inválido"
CODE="$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" -d "_wpnonce=invalido&suiteka_crm_operation=delete&contact_id=$CONTACT_ID" "$ADMIN_URL?page=suiteka-crm")"
[ "$CODE" = "403" ] && ok "nonce inválido: 403" || fail "nonce inválido devolvió $CODE"

echo "# Borrar por POST"
get "page=suiteka-crm&action=delete&id=$CONTACT_ID" >/dev/null
RESULT="$(post 'page=suiteka-crm' --data-urlencode "_wpnonce=$(nonce)" -d "suiteka_crm_operation=delete&contact_id=$CONTACT_ID")"
echo "$RESULT" | grep -q "message=deleted" && ok "borrar contacto" || fail "borrar contacto: $RESULT"
get "page=suiteka-crm-companies&action=delete&id=$COMPANY_ID" >/dev/null
RESULT="$(post 'page=suiteka-crm-companies' --data-urlencode "_wpnonce=$(nonce)" -d "suiteka_crm_operation=delete_company&company_id=$COMPANY_ID")"
echo "$RESULT" | grep -q "message=deleted" && ok "borrar empresa" || fail "borrar empresa: $RESULT"

if [ -n "$OTHER_USER" ]; then
	echo "# Usuario sin acceso al CRM"
	JAR="$WORK/other.jar"
	login "$OTHER_USER" "$JAR" "$OTHER_PASS"
	for page in suiteka-crm suiteka-crm-new suiteka-crm-companies suiteka-crm-duplicates; do
		CODE="$(get "page=$page")"
		[ "$CODE" = "403" ] && ok "$page: 403" || fail "$page devolvió $CODE"
	done
fi

echo "# Resultado: $PASS comprobaciones correctas"
