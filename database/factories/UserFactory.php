<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use App\Support\DefaultRoles;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'username' => fake()->unique()->userName(),
            'email' => null,
            'password' => static::$password ??= Hash::make('password1'),
            'must_change_password' => false,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Rôle livré (voir DefaultRoles) ; les rôles sont créés au besoin.
     */
    public function role(string $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            if (! Role::query()->where('name', $role)->exists()) {
                (new RolesSeeder)->run();
            }

            $user->assignRole($role);
        });
    }

    public function admin(): static
    {
        return $this->role(DefaultRoles::ADMIN);
    }
}
