<?php

namespace Database\Factories;

use App\Models\ExecResult;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExecResultFactory extends Factory
{
    protected $model = ExecResult::class;

    public function definition()
    {
        return [
            'command_id' => 1,
            'trigger_id' => 1,
            'exec_time' => $this->faker->dateTime()->format('Y-m-d H:i:s'),
            'response_code' => 200,
            'response_header' => '{}',
            'response_body' => '',
        ];
    }
}
