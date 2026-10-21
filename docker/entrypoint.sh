#!/bin/bash
set -e
# Debe coincidir con working_dir / volumen de docker-compose.yml
BASE="/var/www/stokpos"

# Solo crea lo que falte. NUNCA borra public/uploads ni las credenciales de AFIP.
mkdir -p "$BASE/public/uploads" \
         "$BASE/storage/framework/sessions" "$BASE/storage/framework/views" "$BASE/storage/framework/cache/data" \
         "$BASE/storage/logs" "$BASE/storage/app/private/afip" "$BASE/storage/app/private/listas" \
         "$BASE/bootstrap/cache"

chown -R www-data:www-data "$BASE/storage" "$BASE/bootstrap/cache" "$BASE/public/uploads"
chmod -R ug+rwX "$BASE/storage" "$BASE/bootstrap/cache" "$BASE/public/uploads"
# certificado y clave de AFIP: solo www-data
chmod 700 "$BASE/storage/app/private/afip"
find "$BASE/storage/app/private/afip" -type f -exec chmod 600 {} \;

exec "$@"
