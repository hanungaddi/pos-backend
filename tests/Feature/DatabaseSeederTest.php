<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_can_run_more_than_once(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('products', 3);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@pos.com',
        ]);

        $this->assertDatabaseHas('products', [
            'nama' => 'Kopi Sachet',
            'merek' => 'Kapal Api',
            'stok' => 40,
            'harga' => 1500,
        ]);
    }
}
