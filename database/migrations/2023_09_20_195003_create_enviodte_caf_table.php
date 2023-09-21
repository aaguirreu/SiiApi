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
            $table->id();
            $table->foreignId('enviodte_id')->references('id')->on('envio_dte');
            $table->foreignId('caf_id')->references('id')->on('caf');
            $table->timestamps();
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
