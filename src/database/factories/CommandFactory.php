<?php

namespace Database\Factories;

use App\Models\Command;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommandFactory extends Factory
{
    protected $model = Command::class;

    public function definition()
    {
        return [
            'target_name' => $this->faker->word,
            'target_id' => 1,
            'target_type' => 'user',
            'url' => $this->faker->url,
            'method' => 'GET',
            'body_type' => 'json',
            'headers' => '',
            'parameters' => '',
        ];
    }
}
