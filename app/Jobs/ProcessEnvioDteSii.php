<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\ApiFacturaController;
use App\Models\Envio;
use App\Http\Controllers\Api\V1\ApiBoletaController;
use App\Http\Controllers\Api\V1\DteController;
use App\Models\Dte;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\Estado;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\XML;
use Throwable;

class ProcessEnvioDteSii implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
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

        // Set ambiente certificacón y obtener token
        if($this->arr['tipo'] == 'boleta'){
            $controller = new ApiBoletaController();
            $controller->setAmbiente($this->arr['ambiente']);
        } else {
            $controller = new ApiFacturaController();
            $controller->setAmbiente($this->arr['ambiente']);
        }


        $envio = Envio::find($this->arr['id']);
        echo $envio->toJson() . "\n";

        // enviar al SII
        $envio_response = $controller->enviar($caratula['RutEnvia'], $caratula['RutEmisor'], $xml);
        if (!$envio_response) {
            DB::table('envio_pasarela')
                ->where('id', $this->arr['id'])
                ->update([
                    'estado' => 'error',
                    'updated_at' => Carbon::now('America/Santiago')->toDateTimeString(),
                ]);
        }
        else {
            DB::table('envio_pasarela')
                ->where('id', $this->arr['id'])
                ->update([
                    'estado' => 'enviado',
                    'track_id' => $envio_response[0]->trackid ?? null,
                    'updated_at' => Carbon::now('America/Santiago')->toDateTimeString(),
                ]);
        }
    }
}
