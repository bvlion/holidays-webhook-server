<?php

namespace Database\Factories;

use App\Models\Calender;
use Illuminate\Database\Eloquent\Factories\Factory;

class CalenderFactory extends Factory
{
    protected $model = Calender::class;

    public function definition()
    {
        return [
            'target_name' => 'API SET',
            'target_id' => 1,
            'target_type' => 'user',
            'target_date' => $this->faker->date(),
            'is_holiday' => 0,
        ];
    }
}
