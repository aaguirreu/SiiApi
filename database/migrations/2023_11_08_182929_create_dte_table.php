<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDteTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('dte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->nullable()->constrained('envio_dte')->onUpdate('cascade')->onDelete('cascade');
            $table->foreignId('caratula_id')->constrained('caratula');
            $table->integer('resumen_id')->nullable()->constrained('resumen_ventas_diarias');
            $table->string('estado')->nullable();
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
        Schema::dropIfExists('dte');
    }
}
