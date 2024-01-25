<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCaratulaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('caratula', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emisor_id')->constrained('empresa')->onUpdate('cascade')->onDelete('cascade');
            $table->foreignId('receptor_id')->constrained('empresa')->onUpdate('cascade');
            $table->string('rut_envia');
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
        Schema::dropIfExists('caratula');
    }
}
