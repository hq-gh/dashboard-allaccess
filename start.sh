#!/usr/bin/env bash
# Dispatcher de arranque por servicio. Railway aplica el startCommand de
# railway.json (config-as-code) a TODOS los servicios del repo y pisa el
# startCommand de instancia (bug de "clobber" que ya mordió a ads y al VIP cron).
# Solucion: un unico startCommand (bash start.sh) que ramifica por
# $RAILWAY_SERVICE_NAME, variable built-in de Railway. Asi cada servicio corre
# su comando correcto sin importar el clobber.
set -euo pipefail

SERVICE="${RAILWAY_SERVICE_NAME:-}"
echo "[start.sh] RAILWAY_SERVICE_NAME='${SERVICE}'"

case "${SERVICE}" in
  5t4d10_VIP_CRON)
    echo "[start.sh] -> backfill VIP XTREME BURN"
    exec php /app/bin/backfill-vip-xtreme-burn.php
    ;;
  5t4d10_PERMISSION_CRON)
    echo "[start.sh] -> permission-sync (apply, cron) + reconcile"
    # sin 'exec': corremos sync y LUEGO la conciliacion (guarda resultado en Neon).
    # Los '|| echo' evitan que 'set -e' aborte antes del reconcile si el sync falla.
    php /app/bin/permission-sync.php --apply --cron || echo "[start.sh] permission-sync salio con error (ver logs); sigo a reconcile"
    php /app/bin/permission-reconcile.php || echo "[start.sh] reconcile salio con error"
    ;;
  *)
    # 5t4d10_P001 (web) y cualquier otro: servidor web.
    echo "[start.sh] -> servidor web (php -S)"
    exec php -S 0.0.0.0:"${PORT:-8080}" -t public public/router.php
    ;;
esac
