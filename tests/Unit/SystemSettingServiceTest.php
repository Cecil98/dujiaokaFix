<?php

namespace Tests\Unit;

use App\Service\SystemSettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemSettingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('admin_settings');
        Schema::create('admin_settings', function (Blueprint $table) {
            $table->string('slug', 100)->primary();
            $table->longText('value');
            $table->timestamps();
        });

        Cache::flush();
    }

    public function testSettingsSurviveCacheFlush(): void
    {
        $settings = [
            'title' => '测试站点',
            'is_open_telegram_push' => 1,
            'telegram_bot_token' => '123:abc',
            'telegram_userid' => '456',
        ];

        SystemSettingService::put($settings);
        Cache::flush();

        $this->assertSame($settings, SystemSettingService::get());
    }

    public function testEmptyCacheWithoutDatabaseRowReturnsArray(): void
    {
        $this->assertSame([], SystemSettingService::get());
    }
}
