<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition()
    {
        return [
            'groups_id' => Group::factory(),
            'api_token' => Str::random(60),
            'user_name' => $this->faker->name,
            'country_code' => 'jp',
            'owner_flag' => 1,
        ];
    }
}
