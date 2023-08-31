<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEnviodteCafTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('enviodte_caf', function (Blueprint $table) {
            $table->foreignId('caf_id')->constrained('caf');
            $table->foreignId('enviodte_id')->constrained('envio_dte');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('enviodte_caf');
    }
}
