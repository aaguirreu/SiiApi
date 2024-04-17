<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEnvioPasarelaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('envio_pasarela', function (Blueprint $table) {
            $table->id();
            $table->string('estado')->nullable();
            $table->string('glosa')->nullable();
            $table->string('rut_emisor');
            $table->string('rut_receptor');
            $table->integer('tipo_dte');
            $table->unsignedInteger('folio');
            $table->integer('track_id')->nullable();
            $table->integer('ambiente');
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
        Schema::dropIfExists('envio_pasarela');
    }
}
