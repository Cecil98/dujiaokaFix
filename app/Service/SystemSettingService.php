<?php

namespace App\Service;

use Dcat\Admin\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * 系统设置持久化服务。
 *
 * 历史版本只把系统设置放在 cache 中，执行 `php artisan optimize:clear`
 * （内部包含 cache:clear）或 Redis 数据丢失后，Telegram / Bark / SMTP
 * 等配置会全部消失。这里以 admin_settings 表为主存储，cache 只作为
 * 读取加速；cache 被清空时会自动从数据库恢复。
 *
 * 数据库和 cache 中的值都使用 APP_KEY 加密；同时兼容旧版明文 JSON /
 * 明文 cache 数组，首次保存或执行 system-setting:persist 后会自动加密。
 */
class SystemSettingService
{
    public const CACHE_KEY = 'system-setting';

    public const SETTINGS_SLUG = 'system-setting';

    private const ENCRYPTED_PREFIX = 'enc:';

    /**
     * 返回系统设置数组，始终返回数组。
     */
    public static function get(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $decoded = self::decode($cached);
        if ($decoded !== null) {
            return $decoded;
        }

        try {
            $row = Setting::query()
                ->where('slug', self::SETTINGS_SLUG)
                ->first();

            if ($row && is_string($row->value) && $row->value !== '') {
                $decoded = self::decode($row->value);
                if ($decoded !== null) {
                    Cache::put(self::CACHE_KEY, self::encode($decoded));

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

        $encrypted = self::encrypt($json);

        Setting::query()->updateOrCreate(
            ['slug' => self::SETTINGS_SLUG],
            ['value' => $encrypted]
        );

        Cache::put(self::CACHE_KEY, $encrypted);
    }

    /**
     * 兼容旧版明文 cache 数组、旧版明文 JSON 和新版 enc: 密文。
     */
    private static function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            try {
                $value = Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_PREFIX)));
            } catch (Throwable $e) {
                report($e);

                return null;
            }
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function encode(array $settings): string
    {
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode system settings.');
        }

        return self::encrypt($json);
    }

    private static function encrypt(string $json): string
    {
        return self::ENCRYPTED_PREFIX . Crypt::encryptString($json);
    }
}
