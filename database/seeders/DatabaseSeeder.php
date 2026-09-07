<?php

namespace Database\Seeders;

use App\Support\DemoWorld;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DemoWorld::reseed();
    }
}
