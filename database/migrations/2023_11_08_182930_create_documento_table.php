<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDocumentoTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('documento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caf_id')->constrained('caf')->onUpdate('cascade')->onDelete('cascade');;
            $table->foreignId('dte_id')->constrained('dte')->onUpdate('cascade')->onDelete('cascade');
            $table->foreignId('receptor_id')->constrained('empresa'); // null en boletas
            $table->integer('ref_id')->nullable();
            $table->integer('folio');
            $table->integer('tipo_transaccion');
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
        Schema::dropIfExists('documento');
    }
}
