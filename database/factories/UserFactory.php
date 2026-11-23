<?php

namespace Database\Factories;

use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
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
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            // Por omisión, el perfil Administrador que crea la migración (las pruebas pueden cambiarlo)
            'profile_id' => fn () => Profile::where('is_admin', true)->value('id'),
        ];
    }

    /**
     * Usuario con el perfil indicado por nombre (por ejemplo "Consulta").
     */
    public function withProfile(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_id' => Profile::where('name', $name)->value('id'),
        ]);
    }

    /**
     * Cuenta desactivada: no puede iniciar sesión.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
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
}
