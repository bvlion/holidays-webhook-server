<?php

namespace Database\Factories;

use App\Models\SummarizeCommand;
use Illuminate\Database\Eloquent\Factories\Factory;

class SummarizeCommandFactory extends Factory
{
    protected $model = SummarizeCommand::class;

    public function definition()
    {
        return [
            'target_name' => $this->faker->word,
            'target_id' => 1,
            'target_type' => 'user',
            'commands' => json_encode([]),
        ];
    }
}
