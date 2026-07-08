<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

class TechnicianQueueServiceTest extends TestCase
{
    public function test_technician_queue_unit_points_at_the_soundit_psa_deploy_path(): void
    {
        $unit = file_get_contents(base_path('deploy/soundit-psa-technician-queue.service'));

        $this->assertIsString($unit);
        $this->assertStringContainsString('WorkingDirectory=/var/www/soundit-psa', $unit);
        $this->assertStringContainsString('/var/www/soundit-psa/artisan queue:work --queue=technician', $unit);
        $this->assertStringNotContainsString('/var/www/psa/artisan', $unit);
    }
}
