<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The staff area is auth-gated (Entra ID SSO), so an unauthenticated
     * request to the root URL is redirected to the login flow rather than
     * returning 200. This is a smoke test that the app boots and the guard
     * is in place.
     */
    public function test_root_redirects_unauthenticated_visitors(): void
    {
        $response = $this->get('/');

        $response->assertRedirect();
    }
}
