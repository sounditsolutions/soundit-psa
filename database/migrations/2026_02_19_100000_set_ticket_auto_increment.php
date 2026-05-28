<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE tickets AUTO_INCREMENT = 10001');
        }
        // SQLite: no-op — auto_increment starts after max(id).
        // Prod (MariaDB) gets 10001+. Dev SQLite gets sequential IDs.
    }

    public function down(): void
    {
        // Cannot reliably reverse auto_increment changes
    }
};
