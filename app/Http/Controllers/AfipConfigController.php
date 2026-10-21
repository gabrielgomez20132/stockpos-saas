<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AfipConfig;
use App\Services\Afip\Wsaa;
use App\Services\Afip\Wsfe;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Configuracion AFIP: datos fiscales + credenciales por ambiente.
 * La clave privada nunca se muestra ni se descarga; lo mas seguro es
 * generarla aca (CSR) para que nunca salga del servidor.
 */
class AfipConfigController extends Controller
{
    private function nombre(string $a): string
    {
        return $a === 'produccion' ? 'producción' : 'homologación';
    }

    public function show()
    {
        $cfg = AfipConfig::obtener();

        return Inertia::render('Afip/Config', [
            'cfg' => $cfg->only(['ambiente', 'cuit', 'punto_venta', 'razon_social', 'domicilio', 'localidad', 'condicion_iva', 'iibb'])
                + ['inicio_actividades' => $cfg->inicio_actividades?->toDateString()],
            'estados' => ['homologacion' => $cfg->estado('homologacion'), 'produccion' => $cfg->estado('produccion')],
            'soap' => extension_loaded('soap'),
            'listo' => $cfg->listo(),
            'logo' => HandleInertiaRequests::logoNegocio(),
        ]);
    }

    public function guardar(Request $request)
    {
        $request->merge(['cuit' => preg_replace('/\D/', '', (string) $request->input('cuit'))]);
        $d = $request->validate([
            'ambiente' => ['required', 'in:homologacion,produccion'],
            'cuit' => ['required', 'digits:11'],
            'punto_venta' => ['required', 'integer', 'min:1', 'max:99999'],
            'razon_social' => ['required', 'string', 'max:120'],
            'domicilio' => ['nullable', 'string', 'max:150'],
            'localidad' => ['nullable', 'string', 'max:100'],
            'condicion_iva' => ['nullable', 'string', 'max:40'],
            'iibb' => ['nullable', 'string', 'max:40'],
            'inicio_actividades' => ['nullable', 'date'],
        ], [
            'cuit.digits' => 'El CUIT tiene que tener 11 números (con o sin guiones).',
            'razon_social.required' => 'La razón social es obligatoria: sale impresa en cada comprobante.',
        ]);

        $cfg = AfipConfig::obtener();
        $aProd = $cfg->ambiente !== 'produccion' && $d['ambiente'] === 'produccion';
        $cfg->fill($d)->save();

        return $this->ok('Datos fiscales guardados.'.($aProd ? ' ATENCIÓN: el ambiente quedó en PRODUCCIÓN — las facturas que emitas desde ahora son reales.' : ''));
    }

    public function logo(Request $request)
    {
        $request->validate(['logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'], 'quitar' => ['boolean']]);

        foreach (['png', 'jpg', 'webp'] as $ext) {
            @unlink(public_path('uploads/logo-negocio.'.$ext));
        }
        if ($request->hasFile('logo') && ! $request->boolean('quitar')) {
            $info = @getimagesize($request->file('logo')->getRealPath());
            $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
            if (! $ext) {
                return back()->with('error', 'El logo tiene que ser una imagen PNG, JPG o WEBP.');
            }
            $request->file('logo')->move(public_path('uploads'), 'logo-negocio.'.$ext);
        }

        return $this->ok('Logo actualizado.');
    }

    /** Sube certificado y/o clave. Antes de guardar verifica que sean pareja. */
    public function credenciales(Request $request, string $ambiente)
    {
        $cfg = AfipConfig::obtener();
        $request->validate([
            'certificado' => ['nullable', 'file', 'max:20'],
            'clave' => ['nullable', 'file', 'max:20'],
            'passphrase' => ['nullable', 'string', 'max:200'],
        ]);
        if (! $request->hasFile('certificado') && ! $request->hasFile('clave')) {
            return back()->with('error', 'Elegí al menos un archivo: el certificado (.crt) y/o la clave privada (.key).');
        }

        $pass = (string) $request->input('passphrase', '');
        $certPem = $request->hasFile('certificado') ? (string) file_get_contents($request->file('certificado')->getRealPath())
            : (is_file($cfg->rutaCert($ambiente)) ? (string) file_get_contents($cfg->rutaCert($ambiente)) : null);
        $keyPem = $request->hasFile('clave') ? (string) file_get_contents($request->file('clave')->getRealPath())
            : (is_file($cfg->rutaKey($ambiente)) ? (string) file_get_contents($cfg->rutaKey($ambiente)) : null);
        $passUsada = $request->hasFile('clave') ? $pass : $cfg->passphrase($ambiente);

        $x509 = $certPem ? @openssl_x509_read($certPem) : null;
        $pkey = $keyPem ? @openssl_pkey_get_private($keyPem, $passUsada) : null;

        if ($request->hasFile('certificado') && ! $x509) {
            return back()->with('error', 'El certificado no es válido. Tiene que ser el .crt que descargás de AFIP.');
        }
        if ($request->hasFile('clave') && ! $pkey) {
            return back()->with('error', 'La clave privada no se pudo abrir. Revisá que sea el .key correcto y la contraseña.');
        }
        if ($x509 && $pkey && ! openssl_x509_check_private_key($x509, $pkey)) {
            return back()->with('error', 'El certificado y la clave no son pareja (ese certificado se pidió con otra clave). No se guardó nada.');
        }

        AfipConfig::asegurarDir();
        if ($request->hasFile('certificado')) {
            $this->escribir($cfg->rutaCert($ambiente), $certPem);
        }
        if ($request->hasFile('clave')) {
            $this->escribir($cfg->rutaKey($ambiente), $keyPem);
            $cfg->setPassphrase($ambiente, $pass);
            $cfg->save();
        }
        @unlink($cfg->rutaTA($ambiente));

        return $this->ok('Credenciales de '.$this->nombre($ambiente).' guardadas.');
    }

    /** Genera la clave privada EN EL SERVIDOR y descarga el pedido (CSR) para subir a AFIP. */
    public function csr(Request $request, string $ambiente)
    {
        $cfg = AfipConfig::obtener();
        $cuit = $cfg->cuitDigitos();
        if (strlen($cuit) !== 11 || ! trim((string) $cfg->razon_social)) {
            return back()->with('error', 'Primero guardá el CUIT y la razón social: van adentro del pedido.');
        }
        if (is_file($cfg->rutaKey($ambiente)) && ! $request->boolean('reemplazar')) {
            return back()->with('error', 'Ya hay una clave de '.$this->nombre($ambiente).'. Si generás otra, el certificado actual deja de servir. Marcá la confirmación para reemplazarla.');
        }

        $alias = preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('alias', 'stokpos')) ?: 'stokpos';
        $dn = ['countryName' => 'AR', 'organizationName' => mb_substr($cfg->razon_social, 0, 64), 'commonName' => $alias, 'serialNumber' => 'CUIT '.$cuit];

        $cnf = tempnam(sys_get_temp_dir(), 'afipcnf');
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\n");
        $opts = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $pkey = openssl_pkey_new($opts);
        $csr = $pkey ? openssl_csr_new($dn, $pkey, $opts) : false;
        $keyPem = $csrPem = null;
        if ($pkey && $csr) {
            openssl_pkey_export($pkey, $keyPem, null, $opts);
            openssl_csr_export($csr, $csrPem);
        }
        @unlink($cnf);
        if (! $keyPem || ! $csrPem) {
            return back()->with('error', 'No se pudo generar la clave en el servidor (OpenSSL).');
        }

        AfipConfig::asegurarDir();
        $this->escribir($cfg->rutaKey($ambiente), $keyPem);
        $cfg->setPassphrase($ambiente, null);
        $cfg->save();
        @unlink($cfg->rutaCert($ambiente));
        @unlink($cfg->rutaTA($ambiente));

        return response($csrPem, 200, [
            'Content-Type' => 'application/pkcs10',
            'Content-Disposition' => 'attachment; filename="pedido-afip-'.$ambiente.'-'.$cuit.'.csr"',
        ]);
    }

    /** Pide un ticket de acceso a AFIP y consulta el ultimo comprobante autorizado. */
    public function probar()
    {
        $cfg = AfipConfig::obtener();
        if (! $cfg->datosCompletos() || ! $cfg->estado()['ok']) {
            return back()->with('error', 'Antes de probar, completá los datos fiscales y cargá certificado y clave de '.$this->nombre($cfg->ambiente).'.');
        }

        try {
            $ta = (new Wsaa($cfg))->ticket(true);
            $tipo = $cfg->esMonotributo() ? 11 : 6;
            $ultimo = (new Wsfe($cfg, $ta))->ultimoAutorizado($cfg->punto_venta, $tipo);
        } catch (\Throwable $e) {
            return back()->with('error', 'La prueba falló: '.$e->getMessage());
        }

        return $this->ok('Conexión OK con AFIP ('.$this->nombre($cfg->ambiente).'). Último comprobante '.($tipo === 11 ? 'C' : 'B')
            .' del punto de venta '.$cfg->punto_venta.': '.$ultimo.'.');
    }

    private function escribir(string $ruta, string $contenido): void
    {
        $tmp = $ruta.'.tmp-'.bin2hex(random_bytes(4));
        file_put_contents($tmp, $contenido);
        @chmod($tmp, 0600);
        rename($tmp, $ruta);
    }
}
