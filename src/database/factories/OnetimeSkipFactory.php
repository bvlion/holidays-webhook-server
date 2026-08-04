<?php

namespace Database\Factories;

use App\Models\OnetimeSkip;
use Illuminate\Database\Eloquent\Factories\Factory;

class OnetimeSkipFactory extends Factory
{
    protected $model = OnetimeSkip::class;

    public function definition()
    {
        return [
            'target_id' => 1,
            'target_type' => 'time',
        ];
    }
}
