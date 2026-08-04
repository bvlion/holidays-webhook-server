<?php

namespace Database\Factories;

use App\Models\TimeTrigger;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeTriggerFactory extends Factory
{
    protected $model = TimeTrigger::class;

    public function definition()
    {
        return [
            'target_name' => $this->faker->word,
            'target_id' => 1,
            'target_type' => 'user',
            'time_from' => '00:00:00',
            'time_to' => '23:59:00',
            'exec_interval' => 1,
            'target_week' => json_encode([1, 2, 3, 4, 5, 6, 7]),
            'holiday_decision' => 'not_check',
            'command_id' => 0,
            'exec_notify' => 0,
            'timezone' => '+09:00',
            'country_code' => 'jp',
            'exec_flag' => 1,
        ];
    }
}
