<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        // Sample people, ads and chats for development. Skips itself in production.
        $this->call(DemoDataSeeder::class);
        $this->call(DemoProposalsSeeder::class);
    }
}
