<?php

namespace App\Services\Afip;

use App\Models\AfipConfig;

/** Cliente de WSFEv1 (factura electronica). Port de app/Classes/WSFEV1.php. */
class Wsfe
{
    private AfipConfig $cfg;

    private \SoapClient $client;

    private \SimpleXMLElement $ta;

    public string $code = '';

    public string $msg = '';

    public string $obs = '';

    public function __construct(?AfipConfig $cfg = null, ?\SimpleXMLElement $ta = null)
    {
        $this->cfg = $cfg ?? AfipConfig::actual();
        $this->ta = $ta ?? (new Wsaa($this->cfg))->ticket();

        if (! is_file($this->cfg->wsfeWsdl())) {
            throw new \RuntimeException('AFIP: falta '.basename($this->cfg->wsfeWsdl()).'.');
        }
        if (strlen($this->cfg->cuitDigitos()) !== 11) {
            throw new \RuntimeException('AFIP: no hay CUIT configurado.');
        }

        $this->client = new \SoapClient($this->cfg->wsfeWsdl(), [
            'soap_version' => SOAP_1_2,
            'location' => $this->cfg->wsfeUrl(),
            'trace' => 0,
            'exceptions' => true,
            'connection_timeout' => 30,
            'stream_context' => self::contextoSsl(),
        ]);
    }

    /** AFIP todavia usa claves DH chicas: hace falta bajar el nivel de OpenSSL. */
    public static function contextoSsl()
    {
        return stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'ciphers' => 'DEFAULT@SECLEVEL=1',
        ]]);
    }

    private function auth(): array
    {
        return [
            'Token' => (string) $this->ta->credentials->token,
            'Sign' => (string) $this->ta->credentials->sign,
            'Cuit' => (int) $this->cfg->cuitDigitos(),
        ];
    }

    private function llamar(string $metodo, array $params): object
    {
        try {
            $r = $this->client->{$metodo}($params);
        } catch (\SoapFault $e) {
            throw new \RuntimeException('AFIP rechazó el pedido (WSFE): '.$e->getMessage());
        }
        $res = $r->{$metodo.'Result'};

        $this->code = $this->msg = $this->obs = '';
        if (isset($res->Errors)) {
            $errs = is_array($res->Errors->Err) ? $res->Errors->Err : [$res->Errors->Err];
            $this->code = (string) $errs[0]->Code;
            $this->msg = implode(' | ', array_map(fn ($e) => $e->Code.': '.$e->Msg, $errs));
        }

        return $res;
    }

    public function ultimoAutorizado(int $ptoVta, int $tipo): int
    {
        $r = $this->llamar('FECompUltimoAutorizado', ['Auth' => $this->auth(), 'PtoVta' => $ptoVta, 'CbteTipo' => $tipo]);
        if ($this->msg !== '') {
            throw new \RuntimeException('AFIP: '.$this->msg);
        }

        return (int) $r->CbteNro;
    }

    /**
     * Pide el CAE. $det es el FECAEDetRequest (sin CbteDesde/Hasta).
     *
     * @return array{cae:string, vto:string, numero:int}
     */
    public function solicitarCae(int $ptoVta, int $tipo, array $det): array
    {
        $numero = $this->ultimoAutorizado($ptoVta, $tipo) + 1;
        $det['CbteDesde'] = $numero;
        $det['CbteHasta'] = $numero;

        $r = $this->llamar('FECAESolicitar', ['Auth' => $this->auth(), 'FeCAEReq' => [
            'FeCabReq' => ['CantReg' => 1, 'PtoVta' => $ptoVta, 'CbteTipo' => $tipo],
            'FeDetReq' => ['FECAEDetRequest' => $det],
        ]]);

        $resp = $r->FeDetResp->FECAEDetResponse ?? null;
        if (is_array($resp)) {
            $resp = $resp[0];
        }
        if ($resp && isset($resp->Observaciones)) {
            $obs = is_array($resp->Observaciones->Obs) ? $resp->Observaciones->Obs : [$resp->Observaciones->Obs];
            $this->obs = implode(' | ', array_map(fn ($o) => $o->Code.': '.$o->Msg, $obs));
        }

        if (! $resp || ($resp->Resultado ?? '') !== 'A' || empty($resp->CAE)) {
            throw new \RuntimeException('AFIP no autorizó el comprobante. '.trim($this->msg.' '.$this->obs));
        }

        return ['cae' => (string) $resp->CAE, 'vto' => (string) $resp->CAEFchVto, 'numero' => $numero];
    }
}
