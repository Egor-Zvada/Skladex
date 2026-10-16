<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DeploymentRoutingTest extends TestCase
{
    public function test_application_get_routes_cannot_be_shadowed_by_public_files_or_directories(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = trim($route->uri(), '/');
            if ($uri === '' || str_contains($uri, '{')) {
                continue;
            }
            $this->assertFalse(file_exists(public_path($uri)), 'Nginx try_files shadows application route /'.$uri.' with public/'.$uri);
        }
    }
}
