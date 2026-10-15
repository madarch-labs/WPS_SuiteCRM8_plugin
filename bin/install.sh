#!/usr/bin/env bash
# Installs (or re-installs) the WPS integration package into SuiteCRM 8.
#
#   bin/install.sh --crm-path /var/www/html [options]            SuiteCRM on this machine
#   bin/install.sh --docker <container> [--crm-path …] [options]  SuiteCRM inside a Docker container
#
# Options:
#   --crm-path PATH      SuiteCRM 8 root, the directory with bin/console (default /var/www/html)
#   --docker NAME        container with SuiteCRM: the package is copied into it with "docker cp"
#   --env-file FILE      WPS_* settings (default: config/wps.env of the package if it exists; variables
#                        already set in the environment win over the file)
#   --web-user USER      web server user that owns the CRM files (default www-data)
#   --apache-conf FILE   Apache vhost file to add the /api/v9 rewrite to (inside the container with --docker);
#                        Apache is reloaded. Without it the snippet is printed and you add it yourself
#   --site-url URL       base URL for the self-check (default http://localhost; with --docker it runs inside the container)
#   --skip-config        do not write $sugar_config['wps'] (keep the current settings)
#   --skip-check         do not call /api/v9 after the installation
#   --force              install even if the CRM version is not in the manifest
set -euo pipefail

CRM_MAJOR=8
CRM_NAME="SuiteCRM 8"
PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

CRM_PATH=/var/www/html
CONTAINER=""
ENV_FILE=""
WEB_USER=www-data
APACHE_CONF=""
SITE_URL=http://localhost
SKIP_CONFIG=0
SKIP_CHECK=0
FORCE=""

die() { echo "ERROR: $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }

while [ $# -gt 0 ]; do
  case "$1" in
    --crm-path) CRM_PATH="${2%/}"; shift 2 ;;
    --docker) CONTAINER="$2"; shift 2 ;;
    --env-file) ENV_FILE="$2"; shift 2 ;;
    --web-user) WEB_USER="$2"; shift 2 ;;
    --apache-conf) APACHE_CONF="$2"; shift 2 ;;
    --site-url) SITE_URL="${2%/}"; shift 2 ;;
    --skip-config) SKIP_CONFIG=1; shift ;;
    --skip-check) SKIP_CHECK=1; shift ;;
    --force) FORCE=--force; shift ;;
    -h|--help) sed -n '2,/^set -e/{/^#/p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown option $1 (see --help)" ;;
  esac
done

if [ "$CRM_MAJOR" = 8 ]; then LEGACY="$CRM_PATH/public/legacy"; else LEGACY="$CRM_PATH"; fi
VERSION="$(cat "$PKG_DIR/VERSION")"

# --- settings: env file, the environment wins
if [ -z "$ENV_FILE" ] && [ -f "$PKG_DIR/config/wps.env" ]; then ENV_FILE="$PKG_DIR/config/wps.env"; fi
WPS_VARS="WPS_ENABLED WPS_REDIS_HOST WPS_REDIS_PORT WPS_REDIS_PASSWORD WPS_REDIS_TIMEOUT WPS_REDIS_STREAM WPS_REDIS_STREAM_MAXLEN WPS_EVENT_MODULES WPS_API_STATIC_KEY WPS_API_USER_ID"
if [ -n "$ENV_FILE" ]; then
  [ -f "$ENV_FILE" ] || die "env file $ENV_FILE not found"
  declare -A PRESET=()
  for v in $WPS_VARS; do if [ -n "${!v+x}" ]; then PRESET[$v]="${!v}"; fi; done
  set -a; . "$ENV_FILE"; set +a
  for v in "${!PRESET[@]}"; do export "$v=${PRESET[$v]}"; done
fi

# --- how to run commands in the CRM (locally or in the container), as the web server user
if [ -n "$CONTAINER" ]; then
  command -v docker >/dev/null || die "docker is not installed"
  docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true || die "container $CONTAINER is not running"
  ENV_ARGS=()
  for v in $WPS_VARS; do if [ -n "${!v+x}" ]; then ENV_ARGS+=(-e "$v=${!v}"); fi; done
  as_web() { docker exec -u "$WEB_USER" "${ENV_ARGS[@]}" -w "$LEGACY" "$CONTAINER" sh -c "$1"; }
  as_root() { docker exec -u root "$CONTAINER" sh -c "$1"; }
else
  # WPS_* reach the web server user through a private env file (written after the package is copied)
  WEB_ENV=""
  web_cmd() { printf 'cd %q && { [ -z "%s" ] || . "%s"; } && %s' "$LEGACY" "$WEB_ENV" "$WEB_ENV" "$1"; }
  if [ "$(id -un)" = "$WEB_USER" ]; then
    as_web() { bash -c "$(web_cmd "$1")"; }
  elif [ "$(id -u)" = 0 ]; then
    as_web() { su -s /bin/bash "$WEB_USER" -c "$(web_cmd "$1")"; }
  else
    command -v sudo >/dev/null || die "run as root, as $WEB_USER, or install sudo"
    as_web() { sudo -u "$WEB_USER" bash -c "$(web_cmd "$1")"; }
  fi
  as_root() { if [ "$(id -u)" = 0 ]; then sh -c "$1"; else sudo sh -c "$1"; fi; }
fi

step "Checking $CRM_NAME at ${CONTAINER:+$CONTAINER:}$CRM_PATH"
as_web "test -f include/entryPoint.php && test -f suitecrm_version.php" \
  || die "$LEGACY is not the legacy root of SuiteCRM (include/entryPoint.php, suitecrm_version.php not found)"
if [ "$CRM_MAJOR" = 8 ]; then
  as_web "test -f '$CRM_PATH/bin/console'" || die "$CRM_PATH/bin/console not found: is this SuiteCRM 8?"
else
  as_web "test ! -d public/legacy" || die "$CRM_PATH looks like SuiteCRM 8: use the SuiteCRM 8 package"
fi
as_web "grep -q \"'installer_locked' => true\" config.php" || die "SuiteCRM is not installed yet (config.php has no installer_locked)"
CRM_VERSION="$(as_web "php -r 'define(\"sugarEntry\", true); include \"suitecrm_version.php\"; echo \$suitecrm_version;'")"
PHP_VERSION="$(as_web "php -r 'echo PHP_VERSION;'")"
as_web "php -r 'exit(class_exists(\"ZipArchive\") ? 0 : 1);'" || die "PHP extension zip is required"
as_web "test -w custom && test -w cache && test -w upload && test -w config_override.php -o ! -e config_override.php" \
  || die "$WEB_USER cannot write custom/, cache/, upload/ or config_override.php in $LEGACY"
echo "SuiteCRM $CRM_VERSION, PHP $PHP_VERSION, package WPS Integration for $CRM_NAME $VERSION"

step "Copying the package"
if [ -n "$CONTAINER" ]; then
  WORK="/tmp/wps_package_$$"
  as_root "rm -rf $WORK && mkdir -p $WORK"
  docker cp "$PKG_DIR/package" "$CONTAINER:$WORK/package" >/dev/null
  docker cp "$PKG_DIR/bin/wps_setup.php" "$CONTAINER:$WORK/wps_setup.php" >/dev/null
  as_root "chown -R $WEB_USER $WORK"
else
  WORK="$(mktemp -d /tmp/wps_package_XXXXXX)"
  cp -r "$PKG_DIR/package" "$WORK/package"
  cp "$PKG_DIR/bin/wps_setup.php" "$WORK/wps_setup.php"
  for v in $WPS_VARS; do if [ -n "${!v+x}" ]; then printf 'export %s=%q\n' "$v" "${!v}"; fi; done > "$WORK/wps.env"
  chmod -R a+rX "$WORK"
  chmod 600 "$WORK/wps.env"
  as_root "chown -R $WEB_USER '$WORK'"
  WEB_ENV="$WORK/wps.env"
fi
cleanup() { as_root "rm -rf $WORK" >/dev/null 2>&1 || true; }
trap cleanup EXIT

if [ "$SKIP_CONFIG" = 0 ]; then
  step "Writing \$sugar_config['wps'] to config_override.php"
  [ -n "${WPS_API_STATIC_KEY:-}" ] || echo "WARNING: WPS_API_STATIC_KEY is empty: /api/v9 will reject every request"
  as_web "php $WORK/wps_setup.php config"
fi

step "Installing the Module Loader package"
as_web "php $WORK/wps_setup.php install $WORK/package $FORCE"

if [ "$CRM_MAJOR" = 8 ]; then
  step "Clearing the Symfony cache (SuiteCRM 8 caches legacy metadata too)"
  as_web "cd '$CRM_PATH' && php bin/console cache:clear -q"
fi

if [ "$CRM_MAJOR" = 8 ]; then REWRITE_TARGET=/legacy/index.php; else REWRITE_TARGET=/index.php; fi
SNIPPET="    # BEGIN WPS integration: /api/v9/{module}[/{id}] -> wpsApi entry point (query string is kept)
    RewriteEngine On
    RewriteRule ^/api/v9/(.*)\$ $REWRITE_TARGET?entryPoint=wpsApi&wps_path=\$1 [QSA,PT,L]
    # END WPS integration"
step "Web server rewrite /api/v9"
if [ -n "$APACHE_CONF" ]; then
  as_root "test -f '$APACHE_CONF'" || die "$APACHE_CONF not found"
  if as_root "grep -q 'BEGIN WPS integration' '$APACHE_CONF'"; then
    echo "$APACHE_CONF already contains the WPS rewrite"
  elif as_root "grep -q 'entryPoint=wpsApi' '$APACHE_CONF'"; then
    echo "$APACHE_CONF already has a wpsApi rewrite rule (added by hand), left as is"
  else
    printf '%s\n' "$SNIPPET" > "/tmp/wps_rewrite_$$.conf"
    if [ -n "$CONTAINER" ]; then docker cp "/tmp/wps_rewrite_$$.conf" "$CONTAINER:/tmp/wps_rewrite.conf" >/dev/null; SNIP=/tmp/wps_rewrite.conf; else SNIP="/tmp/wps_rewrite_$$.conf"; fi
    as_root "grep -q '</VirtualHost>' '$APACHE_CONF'" || die "$APACHE_CONF has no </VirtualHost>: add the snippet from config/apache-wps.conf by hand"
    # the snippet goes right before the first </VirtualHost>
    as_root "cp '$APACHE_CONF' '$APACHE_CONF.wps-backup' \
      && awk -v f='$SNIP' 'BEGIN { while ((getline l < f) > 0) s = s l \"\\n\" } /<\\/VirtualHost>/ && !done { printf \"%s\", s; done = 1 } { print }' '$APACHE_CONF' > /tmp/wps_vhost \
      && cat /tmp/wps_vhost > '$APACHE_CONF' && rm -f /tmp/wps_vhost '$SNIP'"
    rm -f "/tmp/wps_rewrite_$$.conf"
    echo "Rewrite added to $APACHE_CONF (backup: $APACHE_CONF.wps-backup)"
  fi
  as_root "apachectl -t" || die "Apache configuration test failed; restore $APACHE_CONF.wps-backup"
  as_root "apachectl -k graceful" && echo "Apache reloaded"
else
  echo "Add this to the <VirtualHost> of SuiteCRM (see config/apache-wps.conf, config/nginx-wps.conf), then reload the web server:"
  echo "$SNIPPET"
fi

step "Status"
as_web "php $WORK/wps_setup.php status" || true

if [ "$SKIP_CHECK" = 0 ]; then
  step "Self-check of $SITE_URL/api/v9"
  KEY="${WPS_API_STATIC_KEY:-}$(date -u '+%Y.%m.%d %H')"
  http() { # $1 = auth key → prints the HTTP status of GET /api/v9/modules
    local cmd="curl -s -o /dev/null -w '%{http_code}' -H 'X-Auth-Key: $1' '$SITE_URL/api/v9/modules'"
    if [ -n "$CONTAINER" ]; then docker exec "$CONTAINER" sh -c "$cmd"; else sh -c "$cmd"; fi
  }
  GOOD="$(http "$KEY" || true)"
  BAD="$(http "wrong-key" || true)"
  echo "GET /api/v9/modules: valid key → $GOOD, wrong key → $BAD"
  if [ "$GOOD" = 200 ] && [ "$BAD" = 401 ]; then
    echo "OK: the WPS API of the CRM answers"
  else
    echo "WARNING: expected 200 / 401. Check the rewrite rule, WPS_API_STATIC_KEY and $SITE_URL (see README → Troubleshooting)" >&2
    exit 3
  fi
fi

echo
echo "Done: WPS Integration for $CRM_NAME $VERSION is installed. Point WPS to this CRM: CRM_API_BASE_URL=<crm url>/api/v9, CRM_API_STATIC_KEY=<WPS_API_STATIC_KEY>."
