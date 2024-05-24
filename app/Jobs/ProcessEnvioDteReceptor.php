<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\ApiPasarelaController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
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
        public array $arr
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $controller = new ApiPasarelaController();
        $controller->generarDteReceptor($this->arr['request'], $this->arr['ambiente']);
    }
}
