#!/usr/bin/env bash
# Baut eine komplette Testinstanz ohne Docker/MySQL: WordPress + SQLite + WooCommerce + dieses Plugin,
# startet PHP-Server und legt Test-Harness (Mail-Abfang, HTTPS-Simulation) an.
#   Aufruf:  tests/integration/bootstrap-local.sh <Zielverzeichnis> [Port]
#   Danach:  export WP_PATH=<Ziel>/site/wordpress WPCLI="php <Ziel>/wp-cli.phar" WP_URL=http://127.0.0.1:<Port>
#            tests/integration/run-all.sh
set -euo pipefail
DEST="$(mkdir -p "${1:?Zielverzeichnis}" && cd "$1" && pwd)"
PORT="${2:-8099}"
PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$DEST"

[ -f wp-cli.phar ] || curl -sSL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
[ -d site/wordpress ] || { curl -sSL -o wp.zip https://wordpress.org/latest.zip; mkdir -p site; unzip -q -o wp.zip -d site; }
P=site/wordpress/wp-content/plugins
[ -d $P/sqlite-database-integration ] || { curl -sSL -o sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.zip; unzip -q -o sqlite.zip -d $P; }
[ -d $P/woocommerce ] || { curl -sSL -o woo.zip https://downloads.wordpress.org/plugin/woocommerce.zip; unzip -q -o woo.zip -d $P; }

cd site/wordpress
S=wp-content/plugins/sqlite-database-integration
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PWD/$S#g" -e 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g' $S/db.copy > wp-content/db.php
ln -sfn "$PLUGIN_DIR" wp-content/plugins/lindenweg16-legal-suite
cat > wp-config.php <<'PHP'
<?php
define('DB_NAME','wp'); define('DB_USER','x'); define('DB_PASSWORD','x'); define('DB_HOST','localhost');
define('WP_SQLITE_AST_DRIVER', false);
define('AUTH_KEY','a');define('SECURE_AUTH_KEY','b');define('LOGGED_IN_KEY','c');define('NONCE_KEY','d');
define('AUTH_SALT','e');define('SECURE_AUTH_SALT','f');define('LOGGED_IN_SALT','g');define('NONCE_SALT','h');
$table_prefix='wp_';
define('WP_DEBUG',true); define('WP_DEBUG_LOG',true); define('WP_DEBUG_DISPLAY',false);
define('FS_METHOD','direct'); define('DISABLE_WP_CRON', true);
if(!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
require_once ABSPATH.'wp-settings.php';
PHP
mkdir -p wp-content/mu-plugins
cp "$PLUGIN_DIR/tests/integration/harness/ll16-test-harness.php" wp-content/mu-plugins/
cat > router.php <<'PHP'
<?php
$p = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( $p !== '/' && is_file( __DIR__ . $p ) ) { return false; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
PHP

W="php -d memory_limit=512M $DEST/wp-cli.phar --allow-root --path=$PWD"
if ! $W core is-installed 2>/dev/null; then
  $W core install --url=http://127.0.0.1:$PORT --title=Lindenweg16 --admin_user=admin --admin_password=admin --admin_email=sven@lindenweg16.de --skip-email
fi
$W option update home http://127.0.0.1:$PORT >/dev/null; $W option update siteurl http://127.0.0.1:$PORT >/dev/null
$W plugin activate woocommerce lindenweg16-legal-suite
$W language core install de_DE >/dev/null 2>&1 && $W language plugin install woocommerce de_DE >/dev/null 2>&1 && $W site switch-language de_DE >/dev/null 2>&1 || echo 'Hinweis: Deutsche Sprachpakete nicht installiert (Tests laufen dann auf Englisch)'
$W rewrite structure '/%postname%/' >/dev/null
$W option update woocommerce_coming_soon no >/dev/null
$W option update woocommerce_store_pages_only no >/dev/null

if ! curl -s -o /dev/null "http://127.0.0.1:$PORT/"; then
  (PHP_CLI_SERVER_WORKERS=6 nohup php -d memory_limit=512M -S 127.0.0.1:$PORT router.php > "$DEST/server.log" 2>&1 &)
  sleep 3
fi
echo "bereit: WP_PATH=$PWD WP_URL=http://127.0.0.1:$PORT"
