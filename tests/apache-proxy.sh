#!/usr/bin/env bash
# Apache test of the uploads/.htaccess rules of the transparent proxy: a throwaway httpd on a
# high port, in front of the fake storage. Run from the plugin folder:
#
#   FAKE_STORAGE_ROOT=/tmp/fake FAKE_STORAGE_URL=http://127.0.0.1:8899 tests/apache-proxy.sh
#
# 1. With mod_proxy_http and mod_headers the [P] rule streams files from the storage without PHP
#    and never forwards the visitor's cookies or credentials.
# 2. Without mod_headers, or without mod_proxy_http, the same block hands the request to
#    proxy.php (the raw script is returned here, since this httpd has no PHP — that it is
#    returned at all proves the fallback rule fired).
# 3. The direct mode answers a 302 whose Location serves the file, Cyrillic names included.
#
# The rules mirror Simple_Storage_Delivery::htaccess_rules(); tests/integration.php checks that
# the plugin generates exactly these lines. HTTPD (default /usr/sbin/httpd) and its module folder
# (default /usr/libexec/apache2) can be overridden.

set -u
HTTPD="${HTTPD:-/usr/sbin/httpd}"
MODULES="${HTTPD_MODULES:-/usr/libexec/apache2}"
MIME_TYPES="${HTTPD_MIME_TYPES:-/private/etc/apache2/mime.types}"
STORAGE_ROOT="${FAKE_STORAGE_ROOT:?FAKE_STORAGE_ROOT is required}"
STORAGE_URL="${FAKE_STORAGE_URL:-http://127.0.0.1:8899}"
PLUGIN="$(cd "$(dirname "$0")/.." && pwd)"
PORT=8897
SITE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
PREFIX="apache-test-$RANDOM"
FAILED=0
CHECKS=0

cleanup() {
	[ -f "$WORK/httpd.pid" ] && kill "$(cat "$WORK/httpd.pid")" 2>/dev/null
	sleep 0.5
	rm -rf "$WORK" "$STORAGE_ROOT/$PREFIX"
}
trap cleanup EXIT

check() {
	CHECKS=$((CHECKS + 1))
	if [ "$1" = "$2" ]; then
		echo "  ok    $3"
	else
		FAILED=$((FAILED + 1))
		echo "  FAIL  $3 — expected [$2], got [$1]"
	fi
}

start_httpd() {
	local proxy_modules="$1"
	[ -f "$WORK/httpd.pid" ] && kill "$(cat "$WORK/httpd.pid")" 2>/dev/null && sleep 1
	cat > "$WORK/httpd.conf" <<CONF
ServerRoot "/usr"
ServerName 127.0.0.1
Listen 127.0.0.1:$PORT
LoadModule mpm_prefork_module $MODULES/mod_mpm_prefork.so
LoadModule unixd_module $MODULES/mod_unixd.so
LoadModule authz_core_module $MODULES/mod_authz_core.so
LoadModule dir_module $MODULES/mod_dir.so
LoadModule mime_module $MODULES/mod_mime.so
LoadModule log_config_module $MODULES/mod_log_config.so
LoadModule rewrite_module $MODULES/mod_rewrite.so
$proxy_modules
PidFile "$WORK/httpd.pid"
DefaultRuntimeDir "$WORK"
Mutex file:$WORK default
ErrorLog "$WORK/error.log"
TypesConfig "$MIME_TYPES"
DocumentRoot "$WORK/site"
<Directory "$WORK/site">
	AllowOverride All
	Require all granted
</Directory>
CONF
	"$HTTPD" -f "$WORK/httpd.conf" -k start 2>>"$WORK/start.log"
	sleep 1
}

mkdir -p "$WORK/site/wp-content/uploads/2024/05" "$WORK/site/wp-content/plugins/simple-storage" "$STORAGE_ROOT/$PREFIX/2024/05"
cp "$PLUGIN/proxy.php" "$WORK/site/wp-content/plugins/simple-storage/proxy.php"
head -c 524288 /dev/urandom > "$STORAGE_ROOT/$PREFIX/2024/05/remote.bin"
printf 'probe' > "$STORAGE_ROOT/$PREFIX/2024/05/simple storage probe тест.txt"
printf 'local' > "$WORK/site/wp-content/uploads/2024/05/local.jpg"

cat > "$WORK/site/wp-content/uploads/.htaccess" <<HTACCESS
# BEGIN Simple Storage
<IfModule mod_rewrite.c>
RewriteEngine On
<IfModule mod_proxy_http.c>
<IfModule mod_headers.c>
RequestHeader unset Cookie
RequestHeader unset Authorization
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^([0-9]{4}/[0-9]{2}/.+)$ $STORAGE_URL/$PREFIX/\$1 [P,L]
</IfModule>
</IfModule>
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^[0-9]{4}/[0-9]{2}/.+$ /wp-content/plugins/simple-storage/proxy.php [L]
</IfModule>
# END Simple Storage
HTACCESS

URL="$SITE/wp-content/uploads/2024/05/remote.bin"
EXPECTED_SHA="$(shasum -a 256 "$STORAGE_ROOT/$PREFIX/2024/05/remote.bin" | cut -d' ' -f1)"

PROXY_MODULES="LoadModule proxy_module $MODULES/mod_proxy.so
LoadModule proxy_http_module $MODULES/mod_proxy_http.so"
HEADERS_MODULE="LoadModule headers_module $MODULES/mod_headers.so"

proxied_to_php() {
	case "$(curl -s "$URL")" in
		*Simple_Storage_Proxy::handle_request*) echo yes ;;
		*) echo no ;;
	esac
}

echo "== Apache with mod_proxy_http and mod_headers"
start_httpd "$PROXY_MODULES
$HEADERS_MODULE"

curl -s -D "$WORK/h1" -o "$WORK/body" "$URL"
check "$(head -1 "$WORK/h1" | cut -d' ' -f2)" "200" "missing file answers 200 at its original address"
check "$(shasum -a 256 "$WORK/body" | cut -d' ' -f1)" "$EXPECTED_SHA" "bytes come from the storage unchanged"
check "$(grep -ci '^x-simple-storage:' "$WORK/h1")" "0" "no PHP involved"
check "$(grep -ci '^location:' "$WORK/h1")" "0" "no redirect"
check "$(curl -s "$SITE/wp-content/uploads/2024/05/simple%20storage%20probe%20%D1%82%D0%B5%D1%81%D1%82.txt")" "probe" "space and Cyrillic in the name survive [P]"
check "$(curl -s -o /dev/null -w '%{http_code}' -H 'Range: bytes=0-99' "$URL")" "206" "Range passes through"
check "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/wp-content/uploads/2024/05/missing.jpg")" "404" "a file missing everywhere is 404"
check "$(curl -s "$SITE/wp-content/uploads/2024/05/local.jpg")" "local" "a local file is served locally"
curl -s -D "$WORK/h2" -o /dev/null -H 'Cookie: wordpress_logged_in_x=secret' -H 'Authorization: Basic c2VjcmV0OnNlY3JldA==' "$URL"
check "$(head -1 "$WORK/h2" | cut -d' ' -f2)" "200" "a request with cookies and credentials is served"
check "$(grep -ci -e '^x-fake-cookie:' -e '^x-fake-authorization:' "$WORK/h2")" "0" "cookies and credentials never reach the storage"
curl -s -D "$WORK/h3" -o /dev/null -H 'Cookie: a=b' -H 'Authorization: Basic eDp5' "$STORAGE_URL/$PREFIX/2024/05/remote.bin"
check "$(grep -ci -e '^x-fake-cookie:' -e '^x-fake-authorization:' "$WORK/h3")" "2" "the fake storage reports such headers when it gets them"

echo "== Apache with mod_proxy_http but without mod_headers"
start_httpd "$PROXY_MODULES"
check "$(proxied_to_php)" "yes" "without mod_headers the request goes to proxy.php instead"

echo "== Apache without mod_proxy"
start_httpd "$HEADERS_MODULE"
check "$(proxied_to_php)" "yes" "the request falls through to proxy.php"
check "$(curl -s "$SITE/wp-content/uploads/2024/05/local.jpg")" "local" "a local file is still served locally"

echo "== Direct mode"
cat > "$WORK/site/wp-content/uploads/.htaccess" <<HTACCESS
# BEGIN Simple Storage
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^([0-9]{4}/[0-9]{2}/.+)$ $STORAGE_URL/$PREFIX/\$1 [R=302,L]
</IfModule>
# END Simple Storage
HTACCESS
PROBE_URL="$SITE/wp-content/uploads/2024/05/simple%20storage%20probe%20%D1%82%D0%B5%D1%81%D1%82.txt"
check "$(curl -s -o /dev/null -w '%{http_code}' "$PROBE_URL")" "302" "a missing file is redirected"
check "$(curl -s -o /dev/null -w '%{redirect_url}' "$PROBE_URL" | cut -c1-${#STORAGE_URL})" "$STORAGE_URL" "to the storage"
check "$(curl -s -L "$PROBE_URL")" "probe" "the redirect target serves a name with a space and Cyrillic"
check "$(curl -s "$SITE/wp-content/uploads/2024/05/local.jpg")" "local" "a local file is not redirected"

if grep -q -i 'error' "$WORK/start.log" 2>/dev/null; then
	echo "httpd start log:"; cat "$WORK/start.log"
fi

echo
if [ "$FAILED" -gt 0 ]; then
	echo "$FAILED of $CHECKS checks failed."
	[ -f "$WORK/error.log" ] && tail -20 "$WORK/error.log"
	exit 1
fi
echo "All $CHECKS checks passed."
