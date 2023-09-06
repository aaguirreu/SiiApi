<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateFolioTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('folio', function (Blueprint $table) {

            $table->integer('id')->unique();
            $table->integer('cant_folios')->default(1);
            $table->timestamps();
        });

        // Inicializar folios
        // Folio BE
        $folios = [39, 41];
        foreach ($folios as $folio) {
            DB::table('folio')->insert([
                'id' => $folio,
                'cant_folios' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
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
        Schema::dropIfExists('folio');
    }
}
