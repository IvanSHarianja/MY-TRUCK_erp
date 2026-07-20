<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = 'PT ' . fake()->unique()->company();

        return [
            'name'         => $name,
            'slug'         => Str::slug($name) . '-' . Str::random(4),
            'owner_name'   => fake()->name(),
            'fiscal_year'  => (int) date('Y'),
            'fiscal_start' => now()->startOfYear()->toDateString(),
            'fiscal_end'   => now()->endOfYear()->toDateString(),
            'address'      => fake()->address(),
            'phone'        => fake()->phoneNumber(),
            'email'        => fake()->companyEmail(),
            'is_active'    => true,
        ];
    }
}
