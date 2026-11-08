<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Glacier login memakai username (bukan email) — kolomnya wajib & unik.
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'admin_area',
            'wajib_ganti_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /** Akun dengan password sementara — wajib diganti saat login. */
    public function wajibGantiPassword(): static
    {
        return $this->state(fn () => ['wajib_ganti_password' => true]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => ['role' => 'super_admin']);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => 'manager']);
    }

    public function adminArea(): static
    {
        return $this->state(fn () => ['role' => 'admin_area']);
    }

    public function supervisor(): static
    {
        return $this->state(fn () => ['role' => 'supervisor']);
    }
}
