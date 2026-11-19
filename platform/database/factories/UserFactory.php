<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'role_id' => fn () => self::roleId(Role::EMPLOYEE),
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
        ];
    }

    public function role(string $slug): static
    {
        return $this->state(fn () => ['role_id' => self::roleId($slug)]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false, 'deactivated_at' => now()]);
    }

    private static function roleId(string $slug): int
    {
        return Role::firstOrCreate(['slug' => $slug], ['name' => Role::ALL[$slug]])->id;
    }
}
