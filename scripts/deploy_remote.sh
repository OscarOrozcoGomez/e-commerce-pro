#!/usr/bin/env bash
# Se ejecuta EN EL VPS como el usuario del sitio, invocado por
# .github/workflows/deploy.yml despues del rsync. Idempotente.
#
#   ssh <site_user>@<vps> "DEPLOY_PATH='/home/.../htdocs/<dominio>' bash -s" < scripts/deploy_remote.sh
set -euo pipefail

cd "${DEPLOY_PATH:?DEPLOY_PATH no definido}"

# El usuario del sitio tiene 'php' = 8.4 por defecto; la app corre en 8.2.
PHP=/usr/bin/php8.2

echo ">> Verificando archivo de secretos..."
if [ ! -f core/app_secrets.php ]; then
  echo "FATAL: no existe $(pwd)/core/app_secrets.php en el servidor."
  echo "       Crealo (chmod 600, dueno = usuario del sitio) antes de desplegar."
  exit 1
fi
chmod 600 core/app_secrets.php || true

echo ">> Asegurando directorios de runtime..."
mkdir -p core/uploads core/cache logs \
         assets/img/products assets/img/entregas \
         vendor/dompdf/dompdf/lib/fonts
chmod -R u+rwX core/uploads core/cache logs assets/img 2>/dev/null || true

echo ">> Corriendo migraciones (idempotente, con lock + checksum)..."
APP_ENV=production "$PHP" scripts/migrate.php

echo ">> Recargando PHP-FPM (si hay regla sudo; si no, opcache se revalida solo)..."
sudo -n systemctl reload php8.2-fpm 2>/dev/null || echo "   (sin sudo para reload; ok)"

echo ">> deploy_remote.sh OK"
