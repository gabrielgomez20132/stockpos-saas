# StokPOS — Control de stock y punto de venta

Reescritura de **control-soft** (Laravel 5.5 + Blade + jQuery) en:

- **Laravel 13** (PHP 8.3+) · **Inertia 3** · **Vue 3.5** · **Tailwind CSS 4** · **Vite 8**
- **MySQL / MariaDB** con **las mismas tablas y columnas** que control-soft (se puede usar la base vieja).
- Interfaz pensada para **celular, tablet y PC**: barra inferior con botón *Vender* en el celular, menú lateral en la compu, modo oscuro.

## Puesta en marcha con Laragon (PHP 8.3)

El proyecto ya viene con `vendor/` y `public/build/` armados: **no hace falta** correr `composer install` ni `npm`.

1. **Extensiones de PHP** (Laragon › clic derecho › PHP › Extensions): activá `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `gd` y **`soap`** (soap solo hace falta para facturar con AFIP). Reiniciá Laragon.
2. **Base de datos**: en Laragon › Database (HeidiSQL) creá una base `controlstock_v13` (utf8mb4).
   - ¿Querés seguir con los datos de control-soft? Hacé una **copia** de `control_soft` (exportar / importar) y poné ese nombre en `DB_DATABASE`. Nunca la uses directamente sin backup.
3. **`.env`**: ya está creado. Revisá `DB_DATABASE`, `DB_USERNAME` (Laragon: `root`) y `DB_PASSWORD` (Laragon: vacío).
4. Abrí la **Terminal de Laragon** en la carpeta del proyecto y corré:
   ```
   php artisan migrate --seed
   ```
   - Base nueva: crea todo y, si no hay ningún producto, carga categorías y productos de ejemplo de kiosco.
   - Base copiada de control-soft: **solo agrega lo que falta** (no borra ni pisa nada). Los seeders son idempotentes.
5. Entrá a **http://controlstock-v13.test** (Laragon crea el host solo; si no, menú › Apache › Reload).
   - Usuario: **DNI `1234`** o `admin@stokpos.local`, contraseña **`admin`** → **cambiala** en *Mi perfil* (el sistema te avisa).
   - Con la base vieja, entrás con tu usuario y contraseña de siempre.

### Fotos de productos
Están en `public/uploads/` (misma carpeta que control-soft). Ya se copiaron las de control-soft; si agregás más allá, copialas acá.

### Si modificás el frontend
```
npm install
npm run build      # o: npm run dev  (necesita Node 20.19+)
```

### Tests
```
php vendor/bin/phpunit
```
31 tests: ventas, cobros (efectivo, mixto, fiado, vale), stock, caja y cierre, devoluciones, proveedores, AFIP (con un AFIP simulado), permisos y seguridad.

## Qué hace (igual que control-soft)

| Sección | Qué hace |
|---|---|
| **Caja** | Caja inicial (abre el turno), retiros, pagos a proveedores, servicios, gastos varios, movimientos del turno o por fechas, **cierre de caja** con foto del turno imprimible e historial de cierres. |
| **Ventas** | Botón *Vender*, carga por lector de código de barras o búsqueda por nombre con sugerencias, cantidades por unidad o por peso (Kg con decimales), "otro concepto", descuento % y $, cliente, canal, **efectivo / débito / crédito / QR / transferencia / cheque**, pago con dos medios, vuelto, fiado parcial o cuenta corriente, pago con **vale**. Ticket, factura y nota de crédito. Exportación a Excel y CSV. |
| **Devoluciones** | Devolución de productos de una venta cobrada → vuelve el stock y se emite un **vale** (30 días) con código de barras. |
| **Clientes** | ABM, papelera, saldo de cuenta corriente, cobro de deudas, historial de compras y pagos. |
| **Proveedores** | ABM, cuenta corriente (compras y pagos, del cajón o por otro medio). |
| **Productos / Categorías** | ABM con foto (también desde la cámara del celular), stock, aviso de reposición, ingresos de mercadería, actualización masiva de precios por %, valorización del stock. |
| **Reportes** | Semana, mes o rango: vendido, costo, ganancia por día, medios de pago, 15 más vendidos. |
| **Facturación AFIP** | Factura A/B/C y nota de crédito por WSFEv1, libro de comprobantes, **QR obligatorio de AFIP**, configuración con subida de certificado o **generación de la clave y el pedido (CSR) en el servidor**, prueba de conexión. Homologación y producción. |
| **Encargados / Roles** | Usuarios que entran con DNI o email, roles (Super admin, Dueño, Encargado, Empleado) y permisos por sección. |

## Bugs de control-soft que se corrigieron

- La venta confiaba en lo que mandaba el navegador (monto, pagos, "completada"): ahora **todo importe se calcula en el servidor**.
- Una venta cobrada se podía seguir modificando y cobrar dos veces; borrar una venta cobrada la hacía desaparecer de la caja.
- La **cuenta corriente fiaba el total sin restar el descuento**.
- Borrar una venta "fiada" restaba su monto de **todas** las deudas del cliente.
- Una de las rutas de carga no guardaba el costo del producto → **ganancias infladas**.
- `orders.fiado` era INTEGER: los centavos del fiado se perdían (se convierte a decimal al migrar).
- Al cerrar la caja, las ventas sin cobrar quedaban invisibles **con el stock ya descontado** (ahora pasan al turno siguiente; las vacías se borran).
- Se podían borrar movimientos de caja de turnos ya cerrados (descuadraba los cierres).
- El vale de una devolución salía por el precio de lista aunque la venta hubiese tenido descuento.
- La nota de crédito tomaba los importes del formulario (editables) en lugar de la factura; si AFIP fallaba se mostraba un `echo` en pantalla.
- Borrar un producto con ventas rompía el detalle de las ventas viejas (ahora se da de baja).
- El cobro de deudas no exigía caja abierta ni controlaba cobrar más de lo adeudado.
- Los seeders hacían `TRUNCATE` de usuarios, roles y formas de pago.
- Se podían vender fracciones de productos por unidad (ej. 0,5 gaseosa) y cantidades negativas.

## Seguridad

- Login con **límite de intentos** (5 por minuto), regeneración de sesión, los usuarios dados de baja ya no pueden entrar (antes sí).
- Protección **CSRF** en todo, validación de cada formulario del lado del servidor, sin asignación masiva de datos del request.
- **Escalada de privilegios bloqueada**: solo un Super admin asigna/quita Super admin; nadie puede editar o cambiarle la contraseña a un usuario con más permisos que él; nadie se queda sin acceso quitándose el permiso de roles.
- Facturación (emitir) y generar comprobantes requieren sesión de encargado; libro y configuración AFIP requieren el permiso `afip`.
- Certificado y clave de AFIP en `storage/app/private/afip` (fuera de la carpeta pública, permisos 0600); la contraseña de la clave se guarda cifrada con `APP_KEY`.
- Fotos: se valida el contenido real (no la extensión), nombre aleatorio, máximo 4 MB.
- Exportaciones protegidas contra inyección de fórmulas de Excel.
- Encabezados de seguridad (`X-Frame-Options`, `nosniff`, `Referrer-Policy`), páginas de error propias sin datos internos.

### Para producción
- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS y `SESSION_SECURE_COOKIE=true`.
- `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
- Si reutilizás la base de control-soft y tenías una contraseña en la clave de AFIP, copiá el `APP_KEY` del `.env` viejo (o volvé a subir la clave).
- AFIP: el servidor necesita la extensión `soap` y salida a internet hacia `afip.gov.ar`.
