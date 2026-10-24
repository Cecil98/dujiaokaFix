<?php

namespace App\Service;

use Dcat\Admin\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 系统设置持久化服务。
 *
 * 历史版本只把系统设置放在 cache 中，执行 `php artisan optimize:clear`
 * （内部包含 cache:clear）或 Redis 数据丢失后，Telegram / Bark / SMTP
 * 等配置会全部消失。这里以 admin_settings 表为主存储，cache 只作为
 * 读取加速；cache 被清空时会自动从数据库恢复。
 */
class SystemSettingService
{
    public const CACHE_KEY = 'system-setting';

    public const SETTINGS_SLUG = 'system-setting';

    /**
     * 返回系统设置数组，始终返回数组。
     */
    public static function get(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $row = Setting::query()
                ->where('slug', self::SETTINGS_SLUG)
                ->first();

            if ($row && is_string($row->value) && $row->value !== '') {
                $decoded = json_decode($row->value, true);
                if (is_array($decoded)) {
                    Cache::put(self::CACHE_KEY, $decoded);

                    return $decoded;
                }
            }
        } catch (Throwable $e) {
            // 数据库尚未初始化或表不存在时，保持前台可用。
            report($e);
        }

        return [];
    }

    /**
     * 保存系统设置到数据库，并同步缓存。
     */
    public static function put(array $settings): void
    {
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode system settings.');
        }

        Setting::query()->updateOrCreate(
            ['slug' => self::SETTINGS_SLUG],
            ['value' => $json]
        );

        Cache::put(self::CACHE_KEY, $settings);
    }
}
