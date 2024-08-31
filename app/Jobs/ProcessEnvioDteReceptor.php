<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\ApiPasarelaController;
use App\Models\Envio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii\Folios;

class ProcessEnvioDteReceptor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        public Envio $envio,
        public array $arr,
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->envio->estado == 'enviado') {
            $controller = new ApiPasarelaController();

            // Obtener firma
            list($cert_path, $Firma) = $controller->importarFirma($tmp_dir, base64_decode($this->arr['firmab64']), base64_decode($this->arr['pswb64']));

            // Extraer los Caf como Objetos Folios
            $Folios = array_reduce($this->arr['Cafs'], function ($carry, $caf) {
                $caf = base64_decode($caf);
                $folio = new Folios($caf);
                $carry[$folio->getTipo()] = $folio;
                return $carry;
            }, []);

            // Obtener caratula
            $caratula = $controller->obtenerCaratula(json_decode(json_encode($this->arr)), $this->arr['Documentos'], $Firma);

            // Igualar rut receptor de documento al de caratula
            $caratula['RutReceptor'] = $this->arr['Documentos'][0]['Encabezado']['Receptor']['RUTRecep'] ?? '000-0';

            // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
            $envio_dte_xml = $controller->generarEnvioDteXml($this->arr['Documentos'], $Firma, $Folios, $caratula);

            $envio_arr = [
                'correo_receptor' => $this->arr['correo_receptor'],
                'mail' => $this->arr['mail'],
                'password' => $this->arr['password'],
                'host' => $this->arr['host'],
                'port' => $this->arr['port'],
            ];

            $message = [
                'from' => $this->arr['Documentos'][0]['Encabezado']['Receptor']['RznSocRecep'] ?? '',
                'subject' => "RutEmisor: {$caratula['RutEmisor']} RutReceptor: {$caratula['RutReceptor']}",
            ];

            // Verificar y asignar las propiedades como false si no existen
            $pdfb64 = $this->arr['pdfb64'] ?? false;
            $logob64 = $this->arr['logob64'] ?? false;
            $formato_impresion = $this->arr['formato_impresion'] ?? false;
            $observaciones = $this->arr['observaciones'] ?? false;
            $cedible = $this->arr['cedible'] ?? false;
            $footer = $this->arr['footer'] ?? false;
            $tickets = $this->arr['tickets'] ?? false;

            $controller->enviarDteReceptor(
                $envio_dte_xml,
                $message,
                $envio_arr,
                $pdfb64,
                $formato_impresion,
                $observaciones,
                $logob64,
                $cedible,
                $footer,
                $tickets
            );
        }
    }
}
