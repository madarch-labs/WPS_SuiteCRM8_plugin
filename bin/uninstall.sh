#!/usr/bin/env bash
# Uninstalls the WPS integration package from SuiteCRM 8 (same as Admin → Module Loader → Uninstall).
#
#   bin/uninstall.sh --crm-path /var/www/html [options]
#   bin/uninstall.sh --docker <container> [--crm-path …] [options]
#
# Options:
#   --crm-path PATH      SuiteCRM 8 root, the directory with bin/console (default /var/www/html)
#   --docker NAME        container with SuiteCRM
#   --web-user USER      web server user (default www-data)
#   --keep-config        keep $sugar_config['wps'] in config_override.php (default: removed)
#   --remove-fields      also drop the custom field Leads.wps_processed_c WITH ITS DATA (default: kept)
#   --apache-conf FILE   remove the WPS rewrite block added by install.sh from this vhost file and reload Apache
set -euo pipefail

CRM_MAJOR=8
CRM_NAME="SuiteCRM 8"
PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

CRM_PATH=/var/www/html
CONTAINER=""
WEB_USER=www-data
FLAGS=""
APACHE_CONF=""

die() { echo "ERROR: $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }

while [ $# -gt 0 ]; do
  case "$1" in
    --crm-path) CRM_PATH="${2%/}"; shift 2 ;;
    --docker) CONTAINER="$2"; shift 2 ;;
    --web-user) WEB_USER="$2"; shift 2 ;;
    --keep-config) FLAGS="$FLAGS --keep-config"; shift ;;
    --remove-fields) FLAGS="$FLAGS --remove-fields"; shift ;;
    --apache-conf) APACHE_CONF="$2"; shift 2 ;;
    -h|--help) sed -n '2,/^set -e/{/^#/p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown option $1 (see --help)" ;;
  esac
done

if [ "$CRM_MAJOR" = 8 ]; then LEGACY="$CRM_PATH/public/legacy"; else LEGACY="$CRM_PATH"; fi

if [ -n "$CONTAINER" ]; then
  docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true || die "container $CONTAINER is not running"
  as_web() { docker exec -u "$WEB_USER" -w "$LEGACY" "$CONTAINER" sh -c "$1"; }
  as_root() { docker exec -u root "$CONTAINER" sh -c "$1"; }
  WORK="/tmp/wps_setup_$$"
  as_root "mkdir -p $WORK"
  docker cp "$PKG_DIR/bin/wps_setup.php" "$CONTAINER:$WORK/wps_setup.php" >/dev/null
  as_root "chown -R $WEB_USER $WORK"
else
  if [ "$(id -un)" = "$WEB_USER" ]; then as_web() { bash -c "cd '$LEGACY' && $1"; }
  elif [ "$(id -u)" = 0 ]; then as_web() { su -s /bin/bash "$WEB_USER" -c "cd '$LEGACY' && $1"; }
  else as_web() { sudo -u "$WEB_USER" bash -c "cd '$LEGACY' && $1"; }
  fi
  as_root() { if [ "$(id -u)" = 0 ]; then sh -c "$1"; else sudo sh -c "$1"; fi; }
  WORK="$(mktemp -d /tmp/wps_setup_XXXXXX)"
  cp "$PKG_DIR/bin/wps_setup.php" "$WORK/"
  chmod -R a+rX "$WORK"
fi
trap 'as_root "rm -rf $WORK" >/dev/null 2>&1 || true' EXIT

as_web "test -f include/entryPoint.php" || die "$LEGACY is not the legacy root of $CRM_NAME"

step "Uninstalling the WPS integration package from $CRM_NAME"
as_web "php $WORK/wps_setup.php uninstall $FLAGS"

if [ "$CRM_MAJOR" = 8 ]; then
  step "Clearing the Symfony cache"
  as_web "cd '$CRM_PATH' && php bin/console cache:clear -q"
fi

if [ -n "$APACHE_CONF" ]; then
  step "Removing the WPS rewrite from $APACHE_CONF"
  if as_root "grep -q 'BEGIN WPS integration' '$APACHE_CONF'"; then
    as_root "cp '$APACHE_CONF' '$APACHE_CONF.wps-backup' && sed -i '/# BEGIN WPS integration/,/# END WPS integration/d' '$APACHE_CONF'"
    as_root "apachectl -t && apachectl -k graceful" && echo "Apache reloaded"
  else
    echo "no block between '# BEGIN/END WPS integration' markers, nothing to remove (a rule added by hand stays)"
  fi
fi

step "Status"
as_web "php $WORK/wps_setup.php status" || true
