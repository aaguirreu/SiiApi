<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateResumenVentasDiariasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resumen_ventas_diarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->constrained('envio_dte');
            $table->integer('secuencia');
            $table->foreignId('empresa_id')->constrained('empresa')->onUpdate('cascade')->onDelete('cascade');;
            $table->integer('monto_total');
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
        Schema::dropIfExists('resumen_ventas_diarias');
    }
}
