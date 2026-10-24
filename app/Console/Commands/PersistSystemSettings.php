<?php

namespace App\Console\Commands;

use App\Service\SystemSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PersistSystemSettings extends Command
{
    protected $signature = 'system-setting:persist';

    protected $description = 'Copy legacy system-setting cache into admin_settings so cache clears no longer lose it';

    public function handle(): int
    {
        $settings = Cache::get(SystemSettingService::CACHE_KEY);

        if (! is_array($settings) || $settings === []) {
            $this->warn('No system-setting cache found. Open admin -> System Setting and save once, or restore a backup.');

            return self::FAILURE;
        }

        SystemSettingService::put($settings);

        $this->info('System settings persisted to admin_settings (' . count($settings) . ' keys).');

        return self::SUCCESS;
    }
}
