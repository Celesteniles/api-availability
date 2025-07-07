<?php

namespace Database\Seeders;

use App\Models\ApiEndpoint;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Céleste GAKONO',
            'email' => 'celestenilesgakono@gmail.com',
            'phone' => '242067230202'
        ]);

        User::factory()->create([
            'name' => 'Céleste GAKONO',
            'email' => 'celestenilesg@gmail.com',
            'phone' => '242069463954'
        ]);

        ApiEndpoint::create([
            'name' => 'e-MEPPSA',
            'url' => 'https://api.e-meppsa.net',
            'last_status' => 'down',
            'priority' => 'high'
        ]);

        $this->call(AlertRulesSeeder::class);
    }
}
