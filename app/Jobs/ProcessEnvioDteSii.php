<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\ApiFacturaController;
use App\Http\Controllers\Api\V1\ApiPasarelaController;
use App\Models\Envio;
use App\Http\Controllers\Api\V1\ApiBoletaController;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessEnvioDteSii implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        public Envio $envio,
        public array $arr
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $xml = base64_decode($this->arr['xml']);
        $caratula = $this->arr['caratula'];

        if ($this->envio->tipo_dte == 39 || $this->envio->tipo_dte == 41) {
            $controller = new ApiBoletaController();
        } else {
            $controller = new ApiFacturaController();
        }

        // Obtener Firma
        list($cert_path, $Firma) = $controller->importarFirma($tmp_dir, base64_decode($this->arr['request']['firmab64']), base64_decode($this->arr['request']['pswb64']));
        if (is_array($Firma)) {
            throw new Exception($Firma['error']);
        }

        //  Obtener token y setear ambiente
        try {
            $controller->isToken($Firma->getID(), $Firma);
        } catch (Exception $e) {
            throw new Exception("No se pudo obtener Token SII. ". $e->getMessage());
        }
        $controller->setAmbiente($this->envio->ambiente, $caratula['RutEnvia']);

        // Verificar Token
        if ($this->envio->tipo_dte == 39 || $this->envio->tipo_dte == 41) {
            if (!$controller::$token_api || $controller::$token_api == '')
                throw new Exception( "No existe Token BE");
        } else {
            if (!$controller::$token || $controller::$token == '')
                throw new Exception("No existe Token");
        }

        // enviar al SII
        list($envio_response, $filename) = $controller->enviar($xml, $caratula['RutEnvia'], $caratula['RutEmisor'], $this->envio->rut_receptor);
        if (!$envio_response) {
            $this->envio->estado = 'error';
            $this->envio->glosa = \sasco\LibreDTE\Log::read()->msg;
            $this->envio->updated_at = Carbon::now('America/Santiago')->toDateTimeString();
            $this->envio->save();
        }
        else {
            $this->envio->estado = 'enviado';
            $this->envio->glosa = 'Upload OK';
            $this->envio->track_id = $envio_response->trackid ?? $envio_response->TRACKID;
            $this->envio->updated_at = Carbon::now('America/Santiago')->toDateTimeString();
            $this->envio->save();
        }
    }
}
