<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reference data for the donor classification system. Idempotent
        // (updateOrCreate on each code column). Can also be run
        // individually in production via
        //   php artisan db:seed --class=CoalitionBlocSeeder
        //   php artisan db:seed --class=CoalitionRelationshipTypeSeeder
        // to avoid the test-user create below, which is dev-only.
        $this->call([
            CoalitionBlocSeeder::class,
            CoalitionRelationshipTypeSeeder::class,
        ]);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
