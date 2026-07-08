<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

class ImportEnvSettings extends Command
{
    protected $signature = 'settings:import-env {--force : Overwrite existing Settings values}';

    protected $description = 'Import tenant-specific config from .env/config into the Settings table';

    public function handle(): int
    {
        $force = $this->option('force');
        $imported = 0;

        // Level
        $imported += $this->importSetting('level_api_key', config('services.level.api_key'), true, $force);

        // Plivo
        $imported += $this->importSetting('plivo_auth_id', config('services.plivo.auth_id'), false, $force);
        $imported += $this->importSetting('plivo_auth_token', config('services.plivo.auth_token'), true, $force);
        $imported += $this->importSetting('plivo_webhook_secret', config('services.plivo.webhook_secret'), true, $force);
        $imported += $this->importSetting('plivo_did_number', config('services.plivo.did_number'), false, $force);
        $imported += $this->importSetting('plivo_app_id', config('services.plivo.app_id'), false, $force);

        $this->info("Imported {$imported} settings.");

        return self::SUCCESS;
    }

    private function importSetting(string $key, mixed $value, bool $encrypt, bool $force): int
    {
        if ($value === null || $value === '') {
            $this->line("  <comment>skip</comment> {$key} — no value in config");

            return 0;
        }

        $existing = Setting::getValue($key);
        if ($existing !== null && ! $force) {
            $this->line("  <comment>skip</comment> {$key} — already set (use --force to overwrite)");

            return 0;
        }

        if ($encrypt) {
            Setting::setEncrypted($key, $value);
        } else {
            Setting::setValue($key, $value);
        }

        $display = $encrypt ? str_repeat('*', 8) : $value;
        $this->line("  <info>saved</info> {$key} = {$display}");

        return 1;
    }
}
