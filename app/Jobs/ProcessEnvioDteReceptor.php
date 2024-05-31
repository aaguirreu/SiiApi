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
        public string $ambiente
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
            $request = new Request();
            $request->json()->add($this->arr);
            $controller->generarDteReceptor($request, $this->ambiente);
        }
    }
}
