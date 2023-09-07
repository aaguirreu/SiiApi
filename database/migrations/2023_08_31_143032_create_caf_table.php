<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->integer('folio_id');
            $table->integer('folio_inicial');
            $table->integer('folio_final');
            $table->string('xml_filename');
            $table->timestamps();
        });

        // Inicializar cafs
        $cafs = [39, 41];
        foreach ($cafs as $caf) {
            DB::table('caf')->insert([
                'folio_id' => $caf,
                'folio_inicial' => 0,
                'folio_final' => 0,
                'xml_filename' => "",
                'created_at' => Carbon::now('America/Santiago'),
                'updated_at' => Carbon::now('America/Santiago')
            ]);
        }
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
