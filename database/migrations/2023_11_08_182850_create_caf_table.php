<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCafTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('caf', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresa')->onUpdate('cascade')->onDelete('cascade');
            $table->foreignId('secuencia_id')->constrained('secuencia_folio');
            $table->integer('tipo');
            $table->integer('folio_inicial');
            $table->integer('folio_final');
            $table->timestamp('fecha_vencimiento');
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
        Schema::dropIfExists('caf');
    }
}
