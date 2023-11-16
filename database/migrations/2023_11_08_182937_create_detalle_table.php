<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDetalleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documento_id')->constrained('documento')->onUpdate('cascade')->onDelete('cascade');
            $table->integer('secuencia');
            $table->text('nombre');
            $table->text('descripcion')->nullable();
            $table->string('cantidad');
            $table->string('unidad_medida');
            $table->string('precio');
            $table->string('monto');
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
        Schema::dropIfExists('detalle');
    }
}
