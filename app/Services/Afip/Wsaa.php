<?php

namespace App\Services\Afip;

use App\Models\AfipConfig;

/**
 * Autenticacion contra WSAA (ticket de acceso para wsfe). Port de
 * app/Classes/WSAA.php de control-soft: mismo flujo (TRA firmado PKCS#7 ->
 * loginCms -> TA guardado por ambiente), sin archivos temporales dentro de app/.
 */
class Wsaa
{
    private AfipConfig $cfg;

    public function __construct(?AfipConfig $cfg = null, private string $service = 'wsfe')
    {
        $this->cfg = $cfg ?? AfipConfig::actual();
        AfipConfig::asegurarDir();

        if (! extension_loaded('soap')) {
            throw new \RuntimeException('AFIP: la extensión SOAP de PHP no está activa. En Laragon: Menú > PHP > Extensions > soap.');
        }
        foreach ([$this->cfg->rutaCert(), $this->cfg->rutaKey(), $this->cfg->wsaaWsdl()] as $f) {
            if (! is_file($f)) {
                throw new \RuntimeException('AFIP: falta '.basename($f).'. Revisá Configuración AFIP.');
            }
        }
    }

    /** Devuelve un TA vigente (lo reutiliza si no vencio). */
    public function ticket(bool $forzar = false): \SimpleXMLElement
    {
        $ruta = $this->cfg->rutaTA();
        if (! $forzar && is_file($ruta)) {
            $ta = @simplexml_load_file($ruta);
            if ($ta && strtotime((string) $ta->header->expirationTime) > time() + 60) {
                return $ta;
            }
        }

        $xml = $this->login($this->firmar($this->tra()));
        if (! @file_put_contents($ruta, $xml)) {
            throw new \RuntimeException('AFIP: no se pudo guardar el ticket de acceso ('.$ruta.'). Revisá permisos de storage/.');
        }
        @chmod($ruta, 0600);

        $ta = simplexml_load_string($xml);
        if (! $ta) {
            throw new \RuntimeException('AFIP devolvió un ticket de acceso inválido.');
        }

        return $ta;
    }

    private function tra(): string
    {
        $tra = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"></loginTicketRequest>');
        $tra->addChild('header');
        $tra->header->addChild('uniqueId', (string) time());
        $tra->header->addChild('generationTime', date('c', time() - 60));
        $tra->header->addChild('expirationTime', date('c', time() + 600));
        $tra->addChild('service', $this->service);

        return $tra->asXML();
    }

    private function firmar(string $tra): string
    {
        $in = tempnam(sys_get_temp_dir(), 'tra');
        $out = tempnam(sys_get_temp_dir(), 'cms');
        file_put_contents($in, $tra);
        try {
            $ok = openssl_pkcs7_sign($in, $out, 'file://'.$this->cfg->rutaCert(),
                ['file://'.$this->cfg->rutaKey(), $this->cfg->passphrase()], [], ! PKCS7_DETACHED);
            if (! $ok) {
                throw new \RuntimeException('AFIP: no se pudo firmar el pedido de acceso. ¿El certificado y la clave son pareja?');
            }
            // saca los encabezados MIME y deja solo el CMS en base64
            $partes = preg_split("/\r?\n\r?\n/", (string) file_get_contents($out), 2);

            return trim($partes[1] ?? '');
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    private function login(string $cms): string
    {
        $client = new \SoapClient($this->cfg->wsaaWsdl(), [
            'soap_version' => SOAP_1_2,
            'location' => $this->cfg->wsaaUrl(),
            'trace' => 0,
            'exceptions' => true,
            'connection_timeout' => 30,
            'stream_context' => Wsfe::contextoSsl(),
        ]);

        try {
            $r = $client->loginCms(['in0' => $cms]);
        } catch (\SoapFault $e) {
            throw new \RuntimeException('AFIP rechazó el acceso (WSAA): '.$e->getMessage());
        }

        return (string) $r->loginCmsReturn;
    }
}
