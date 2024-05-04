<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\ApiFacturaController;
use App\Models\Envio;
use App\Http\Controllers\Api\V1\ApiBoletaController;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

        // Set ambiente y obtener token
        if ($this->envio->tipo_dte == 39 || $this->envio->tipo_dte == 41) {
            $controller = new ApiBoletaController();
        } else {
            $controller = new ApiFacturaController();
        }
        $controller->setAmbiente($this->envio->ambiente, $caratula['RutEnvia']);

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
