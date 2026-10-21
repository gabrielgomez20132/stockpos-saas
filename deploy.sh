#!/usr/bin/env bash
# =============================================================================
# deploy.sh — StokPOS (Laravel 13) en el VPS
# =============================================================================
# Uso (desde /opt/docker/stokpos, como root):
#   ./deploy.sh                 # git pull + build + migraciones + caches
#   ./deploy.sh --no-pull       # despliega lo que ya hay en la carpeta
#   ./deploy.sh --branch dev    # otra rama (por defecto: main)
#
# Que hace, en orden:
#   1. trae el codigo (git pull --ff-only, aborta si hay cambios locales)
#   2. crea el .env la primera vez (desde .env.production.example)
#   3. levanta los contenedores (SIN down -v: public/uploads y AFIP a salvo)
#   4. composer install --no-dev
#   5. compila el frontend con un contenedor de Node (public/build)
#   6. modo mantenimiento -> backup de la base -> migraciones -> datos base
#   7. caches de config/rutas/vistas y salida del modo mantenimiento
#   8. prueba que /up responda
#
# Los seeders que corre (BaseSeeder y RolesSeeder) son idempotentes: completan
# formas de pago, roles y permisos que falten SIN borrar ni pisar nada.
# El catalogo de ejemplo NUNCA se carga en produccion.
# =============================================================================
set -euo pipefail

COMPOSE_DIR="$(cd "$(dirname "$0")" && pwd)"
HOST_PORT="9013"
DO_PULL=1
BRANCH="main"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --no-pull) DO_PULL=0 ;;
    --branch) shift; [[ $# -gt 0 ]] || { echo "ERROR: --branch necesita una rama" >&2; exit 1; }; BRANCH="$1" ;;
    -h|--help) echo "Uso: ./deploy.sh [--no-pull] [--branch <rama>]"; exit 0 ;;
    *) echo "ERROR: opcion desconocida: $1" >&2; exit 1 ;;
  esac
  shift
done

log() { echo -e "\n==> $*"; }
die() { echo "ERROR: $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "Corre este script como root (o con sudo)."
cd "$COMPOSE_DIR"
command -v docker >/dev/null || die "No esta instalado docker."

app()     { docker compose exec -T stokpos_app "$@"; }
artisan() { app php artisan "$@"; }
wait_app() {
  local i=0
  until docker compose exec -T stokpos_app php -v >/dev/null 2>&1; do
    i=$((i + 1)); [[ $i -lt 30 ]] || die "stokpos_app no responde"; sleep 2
  done
}

# ----------------------------------------------------------------------------- 1
log "1/8  Codigo"
if [[ "$DO_PULL" -eq 0 ]]; then
  echo "     git pull salteado (--no-pull)"
elif [[ ! -d .git ]]; then
  echo "     ${COMPOSE_DIR} no es un repo git: se despliega lo que hay"
else
  GIT="git -c safe.directory=${COMPOSE_DIR} -c core.fileMode=false"
  SUCIO="$($GIT status --porcelain --untracked-files=no)"
  [[ -z "$SUCIO" ]] || { echo "$SUCIO"; die "Hay cambios sin commitear en el server. Resolvelos o usa --no-pull."; }
  ANTES="$($GIT rev-parse --short HEAD)"
  $GIT fetch --prune origin "$BRANCH" || die "No pude hacer fetch de origin/${BRANCH}"
  $GIT checkout "$BRANCH" >/dev/null 2>&1 || die "No pude pararme en ${BRANCH}"
  $GIT merge --ff-only "origin/${BRANCH}" || die "La rama del server divergio de origin/${BRANCH}. Revisa a mano."
  DESPUES="$($GIT rev-parse --short HEAD)"
  if [[ "$ANTES" == "$DESPUES" ]]; then echo "     al dia en ${DESPUES}"; else echo "     ${ANTES} -> ${DESPUES}"; $GIT --no-pager log --oneline "${ANTES}..${DESPUES}" | sed 's/^/     /'; fi
fi

# ----------------------------------------------------------------------------- 2
log "2/8  .env"
if [[ ! -f .env ]]; then
  [[ -f .env.production.example ]] || die "Falta .env.production.example"
  cp .env.production.example .env
  chmod 640 .env
  echo "     Se creo .env desde .env.production.example."
  echo "     Completa APP_URL y DB_* y volve a correr ./deploy.sh :"
  echo "       nano ${COMPOSE_DIR}/.env"
  exit 0
fi
grep -q '^APP_ENV=production' .env || echo "     AVISO: APP_ENV no es production en .env"
grep -q '^APP_DEBUG=false' .env    || echo "     AVISO: APP_DEBUG deberia ser false en produccion"
grep -q '^APP_URL=https://' .env   || echo "     AVISO: APP_URL deberia empezar con https:// (necesario para vender sin conexion)"

# ----------------------------------------------------------------------------- 3
log "3/8  Contenedores (build + up, sin down -v)"
docker network inspect mysql_default >/dev/null 2>&1 || die "No existe la red mysql_default (MySQL compartido del VPS)."
docker compose up -d --build stokpos_app stokpos_nginx
wait_app
if docker compose exec -T stokpos_nginx nginx -t >/dev/null 2>&1; then
  docker compose exec -T stokpos_nginx nginx -s reload
else
  docker compose exec -T stokpos_nginx nginx -t || true
  die "docker/nginx.conf tiene un error: no recargo para no tirar el sitio."
fi

# ----------------------------------------------------------------------------- 4
log "4/8  Composer"
app composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ----------------------------------------------------------------------------- 5
log "5/8  Frontend (Vite)"
docker compose --profile build run --rm stokpos_node
[[ -f public/build/manifest.json ]] || die "No se genero public/build/manifest.json"

# ----------------------------------------------------------------------------- 6
log "6/8  Base de datos"
grep -q '^APP_KEY=base64:' .env || artisan key:generate --force
artisan config:clear >/dev/null
artisan down --retry=15 --refresh=15 || true
trap 'artisan up >/dev/null 2>&1 || true' EXIT

ESTADO="$(artisan migrate:status 2>&1 | tr -d '\r' || true)"
if echo "$ESTADO" | grep -qE 'Pending|Pendiente'; then
  echo "     hay migraciones pendientes: backup antes de migrar"
  ./backup.sh --solo-base || die "Fallo el backup: no migro."
fi
artisan migrate --force
artisan db:seed --class='Database\Seeders\BaseSeeder' --force
artisan db:seed --class='Database\Seeders\RolesSeeder' --force

# ----------------------------------------------------------------------------- 7
log "7/8  Caches"
artisan optimize:clear >/dev/null
artisan config:cache
artisan route:cache
artisan view:cache
artisan event:cache
chown -R www-data:www-data storage bootstrap/cache public/uploads 2>/dev/null || true
artisan up
trap - EXIT

# ----------------------------------------------------------------------------- 8
log "8/8  Prueba"
if curl -fsS -o /dev/null "http://172.17.0.1:${HOST_PORT}/up"; then
  echo "     /up responde OK"
else
  die "El sitio no responde en http://172.17.0.1:${HOST_PORT}/up — mira: docker compose logs --tail=80"
fi

APP_URL="$(grep '^APP_URL=' .env | cut -d= -f2-)"
echo
echo "=============================================="
echo " Deploy OK  ->  ${APP_URL}"
echo " Puerto interno: 172.17.0.1:${HOST_PORT} (nginx-proxy)"
echo " Fotos:  ${COMPOSE_DIR}/public/uploads"
echo " AFIP:   ${COMPOSE_DIR}/storage/app/private/afip"
echo " Backups: ${COMPOSE_DIR}/backups  (./backup.sh)"
echo "=============================================="
