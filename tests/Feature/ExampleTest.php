<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_root_url_redirects_to_admin_panel(): void
    {
        // Filament v5.6 mount di /admin, root URL redirect ke panel/login.
        // 302 = healthy redirect chain, bukan 500 (server error).
        $response = $this->get('/');
        $response->assertStatus(302);
    }
}
