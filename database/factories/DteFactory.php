<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'envio_id' => $this->faker->numberBetween(1, 100),
            'caratula_id' => $this->faker->numberBetween(1, 100),
            'resumen_id' => $this->faker->numberBetween(1, 100),
            'estado' => $this->faker->randomElement(['aceptado', 'rechazado']),
            'glosa' => $this->faker->text(),
            'xml_filename' => $this->faker->text(),
            'timestamp' => $this->faker->dateTime(),
        ];
    }
}
