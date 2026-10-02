<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'type' => Category::TYPE_PROJECT,
            'slug' => str($name)->slug()->toString(),
            'name_en' => ucfirst($name),
            'name_ar' => 'فئة '.fake()->unique()->numerify('###'),
            'sort_order' => 0,
        ];
    }

    public function influencer(): static
    {
        return $this->state(['type' => Category::TYPE_INFLUENCER]);
    }
}
