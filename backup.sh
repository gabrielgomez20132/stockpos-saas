#!/usr/bin/env bash
# =============================================================================
# backup.sh — copia de seguridad de StokPOS
# =============================================================================
#   ./backup.sh              base de datos + fotos + credenciales AFIP
#   ./backup.sh --solo-base  solo la base (lo usa deploy.sh antes de migrar)
#
# Deja los archivos en ./backups y borra los de mas de 15 dias.
# Para que corra todas las noches (crontab -e, como root):
#   30 3 * * * /opt/docker/stokpos/backup.sh >> /opt/docker/stokpos/backups/backup.log 2>&1
#
# Restaurar la base (OJO: reemplaza los datos actuales):
#   gunzip -c backups/base_FECHA.sql.gz | docker compose exec -T stokpos_app \
#     sh -c 'mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"'
# =============================================================================
set -euo pipefail
cd "$(cd "$(dirname "$0")" && pwd)"
DIAS=15
FECHA="$(date +%Y-%m-%d_%H%M)"
mkdir -p backups
chmod 700 backups

leer() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST="$(leer DB_HOST)"; DB_PORT="$(leer DB_PORT)"; DB_DATABASE="$(leer DB_DATABASE)"
DB_USERNAME="$(leer DB_USERNAME)"; DB_PASSWORD="$(leer DB_PASSWORD)"

echo "[$(date '+%F %T')] backup de la base ${DB_DATABASE}"
docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" stokpos_app \
  mysqldump -h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" \
  --single-transaction --quick --routines --triggers --no-tablespaces "$DB_DATABASE" \
  | gzip -9 > "backups/base_${FECHA}.sql.gz"
# un dump vacio o cortado no sirve
[[ "$(gunzip -c "backups/base_${FECHA}.sql.gz" | tail -1)" == *"Dump completed"* ]] \
  || { echo "ERROR: el backup de la base salio incompleto"; rm -f "backups/base_${FECHA}.sql.gz"; exit 1; }
chmod 600 "backups/base_${FECHA}.sql.gz"
echo "    -> backups/base_${FECHA}.sql.gz ($(du -h "backups/base_${FECHA}.sql.gz" | cut -f1))"

if [[ "${1:-}" != "--solo-base" ]]; then
  tar -czf "backups/archivos_${FECHA}.tar.gz" public/uploads storage/app/private/afip .env 2>/dev/null || true
  chmod 600 "backups/archivos_${FECHA}.tar.gz"
  echo "    -> backups/archivos_${FECHA}.tar.gz (fotos, AFIP y .env)"
fi

find backups -name 'base_*.sql.gz' -mtime +"$DIAS" -delete
find backups -name 'archivos_*.tar.gz' -mtime +"$DIAS" -delete
echo "[$(date '+%F %T')] listo"
