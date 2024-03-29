<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateEmpresaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('empresa', function (Blueprint $table) {
            $table->id();
            $table->string('rut');
            $table->string('fecha_resolucion')->nullable();
            $table->string('razon_social')->nullable();
            $table->string('giro')->nullable();
            $table->json('acteco')->nullable();
            $table->string('direccion')->nullable();
            $table->string('comuna')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('codigo_vendedor')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono')->nullable();
            $table->timestamps();
        });

        DB::table('empresa')->insert([
            'rut' => '60803000-K',
            'razon_social' => 'Servicio de Impuestos Internos',
            'created_at' => Carbon::now('America/Santiago'),
            'updated_at' => Carbon::now('America/Santiago'),
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('empresa');
    }
}
