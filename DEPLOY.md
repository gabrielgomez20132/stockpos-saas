# StokPOS — subir a producción (VPS con Docker)

Mismo esquema que **control-soft (kiosco)** en el VPS:

```
Internet ──HTTPS──> nginx-proxy (central, /opt/docker/nginx-proxy)
                       │  proxy_pass http://172.17.0.1:9013
                       ▼
                 stokpos_nginx (nginx:1.27-alpine)  ──fastcgi──>  stokpos_app (PHP 8.3-FPM)
                                                                       │ red mysql_default
                                                                       ▼
                                                              MySQL compartido ("mysql")
```

| | kiosco (control-soft) | **stokpos (este)** |
|---|---|---|
| Carpeta | `/opt/docker/kiosco` | `/opt/docker/stokpos` |
| Contenedores | `kiosco_app`, `kiosco_nginx` | `stokpos_app`, `stokpos_nginx` |
| Puerto interno | `9010` | `9013` (solo en `172.17.0.1`) |
| PHP | 7.4 + Composer 1 | 8.3 + Composer 2 |
| Base | `kiosco` | `stokpos` (nueva; los datos se copian con un comando) |

Los dos sistemas pueden convivir: se prueba StokPOS en su subdominio y, cuando esté todo bien, se apaga kiosco.

## Archivos
- `Dockerfile` — PHP 8.3-FPM con pdo_mysql, gd, soap (AFIP), zip, intl, opcache y cliente MySQL (backups).
- `docker-compose.yml` — app + nginx + un contenedor de Node solo para compilar el frontend.
- `docker/nginx.conf` — nginx interno: `/uploads` nunca ejecuta PHP, `/sw.js` sin caché (venta sin conexión), `/build` con caché larga, gzip.
- `docker/entrypoint.sh` — crea carpetas y permisos (nunca borra fotos ni credenciales AFIP).
- `deploy/nginx-proxy/stokpos.conf` — el bloque para el nginx-proxy central.
- `deploy.sh` — despliegue y actualización (backup automático antes de migrar).
- `backup.sh` — copia de la base, fotos y AFIP (15 días).
- `.env.production.example` — `.env` de producción.

## Primera vez

### 1. Subir el código a un repositorio (desde la PC, una sola vez)
```bash
cd C:\laragon\www\controlstock-v13
git init -b main
git add .
git commit -m "StokPOS Laravel 13"
git remote add origin https://github.com/gabrielgomez20132/stockpos-saas.git
git push -u origin main
```
`vendor/`, `node_modules/`, `public/build/`, `.env`, fotos y certificados AFIP **no** se suben (están en `.gitignore`). El VPS los arma solo.

### 2. Base de datos en el MySQL compartido
```bash
docker exec -it mysql mysql -uroot -p
```
```sql
CREATE DATABASE stokpos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'stokpos'@'%' IDENTIFIED BY 'UNA_CLAVE_LARGA';
GRANT ALL PRIVILEGES ON stokpos.* TO 'stokpos'@'%';
-- solo si vas a copiar los datos de kiosco:
GRANT SELECT ON kiosco.* TO 'stokpos'@'%';
FLUSH PRIVILEGES;
```

### 3. Clonar y configurar
```bash
cd /opt/docker
git clone https://github.com/gabrielgomez20132/stockpos-saas.git stokpos
cd stokpos
chmod +x deploy.sh backup.sh docker/entrypoint.sh
./deploy.sh            # la primera vez solo crea el .env y se detiene
nano .env              # APP_URL=https://... y DB_PASSWORD
./deploy.sh            # ahora sí: build, composer, frontend, migraciones, caches
```

### 4. Dominio con HTTPS en el nginx-proxy
```bash
cp deploy/nginx-proxy/stokpos.conf /opt/docker/nginx-proxy/conf.d/stokpos.conf
nano /opt/docker/nginx-proxy/conf.d/stokpos.conf      # server_name = tu subdominio
cd /opt/docker/nginx-proxy
docker compose exec nginx-proxy nginx -t && docker compose exec nginx-proxy nginx -s reload
```
El certificado se saca igual que el de kiosco (certbot en el proxy o Cloudflare). **Para vender sin conexión el sitio tiene que abrir con https y candado válido.**

### 5. Pasar los datos de control-soft (opcional)
```bash
# copia de seguridad antes (por las dudas)
./backup.sh
# ventas, productos, clientes, caja, usuarios... (la base kiosco NO se toca)
docker compose exec stokpos_app php artisan stock:importar-control-soft --base=kiosco
# fotos de los productos
cp -a /opt/docker/kiosco/public/uploads/. /opt/docker/stokpos/public/uploads/
chown -R www-data:www-data public/uploads
```
Los usuarios entran con su DNI/email y contraseña de siempre.

### 6. AFIP
Entrar a **Configuración AFIP** y subir el certificado `.crt` y la clave `.key` (o generar el pedido desde ahí). Quedan en `storage/app/private/afip`, fuera de la carpeta pública.

### 7. Backups todas las noches
```bash
crontab -e
30 3 * * * /opt/docker/stokpos/backup.sh >> /opt/docker/stokpos/backups/backup.log 2>&1
```
Conviene copiar `backups/` a otro lado (otro servidor, Google Drive con rclone, etc.).

### 8. Probar la venta sin conexión en el local
1. Entrar al sistema en cada PC/tablet con internet y la caja abierta.
2. Cortar internet → recargar la página: abre igual, con el aviso rojo.
3. Vender en "Vender sin conexión".
4. Volver a conectar: en menos de un minuto la venta aparece en *Ventas*, con la hora real.

## Actualizar
```bash
cd /opt/docker/stokpos && ./deploy.sh
```
Trae el código, recompila y vuelve a armar los caches. Las **migraciones** corren solo si hay nuevas (antes hace un backup de la base). Los **seeders** (formas de pago, roles, permisos y el usuario `1234` / `admin`) corren **solo la primera vez**, cuando la base está vacía; para volver a correrlos: `./deploy.sh --seed` (no borran ni pisan nada). Mientras migra, el sitio muestra "en mantenimiento" unos segundos.

## Comandos útiles
```bash
docker compose ps                         # estado
docker compose logs -f --tail=100         # logs de nginx y php
tail -f storage/logs/laravel-*.log        # errores de la aplicación
docker compose exec stokpos_app php artisan tinker
./backup.sh                               # backup a mano
```

## Nunca
- `docker compose down -v` ni `docker volume prune` (no borran fotos porque están en el host, pero es mala costumbre en este VPS).
- `php artisan migrate:fresh` / `db:wipe` en producción (borran todo).
- `APP_DEBUG=true` en producción.
