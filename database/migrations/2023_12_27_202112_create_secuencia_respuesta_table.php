<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSecuenciaRespuestaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('secuencia_respuesta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dte_id')->constrained('dte')->onDelete('cascade')->onUpdate('cascade');
            $table->integer('cod_envio');
            $table->string('xml_filename');
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
        Schema::dropIfExists('secuencia_respuesta');
    }
}
