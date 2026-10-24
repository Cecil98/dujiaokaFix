<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Pay extends BaseModel
{

    use SoftDeletes;

    protected $table = 'pays';

    protected $casts = [
        'merchant_id' => \App\Casts\EncryptedOrPlaintext::class,
        'merchant_key' => \App\Casts\EncryptedOrPlaintext::class,
        'merchant_pem' => \App\Casts\EncryptedOrPlaintext::class,
    ];

    /**
     * 跳转
     */
    const METHOD_JUMP = 1;

    /**
     * 扫码
     */
    const METHOD_SCAN = 2;

    /**
     * 电脑
     */
    const PAY_CLIENT_PC = 1;

    /**
     * 手机
     */
    const PAY_CLIENT_MOBILE = 2;

    /**
     * 通用
     */
    const PAY_CLIENT_ALL = 3;

    /**
     * 易支付渠道标识
     */
    const YIPAY_HANDLEROUTE = '/pay/yipay';

    /**
     * install.sql 出厂占位字面量；这些值不得被当作真实凭据使用。
     */
    const PLACEHOLDER_MERCHANT_ID = '商户号';
    const PLACEHOLDER_MERCHANT_PEM = '密钥';

    /**
     * 归一化支付渠道路由（忽略首尾斜杠）。
     *
     * @return string
     */
    public function normalizedHandleroute(): string
    {
        return trim((string) $this->pay_handleroute, "/ \t\n\r\0\x0B");
    }

    /**
     * 是否为易支付渠道。
     *
     * @return bool
     */
    public function isYipayGateway(): bool
    {
        return $this->normalizedHandleroute() === trim(self::YIPAY_HANDLEROUTE, '/');
    }

    /**
     * 易支付凭据是否可用（非空、非出厂占位符、网关地址为 http(s) URL）。
     *
     * 只有返回 true 的易支付渠道才允许发起支付或接受异步通知，避免
     * install.sql 里的公开占位符被当作签名密钥使用（见审计 PQ-01）。
     *
     * 使用模型访问器读取，因此 EncryptedOrPlaintext 加密仍正常工作。
     *
     * @return bool
     */
    public function hasUsableYipayCredentials(): bool
    {
        if (!$this->isYipayGateway()) {
            return false;
        }

        $merchantId = trim((string) $this->merchant_id);
        if ($merchantId === '' || $merchantId === self::PLACEHOLDER_MERCHANT_ID) {
            return false;
        }

        if (!self::isHttpUrl((string) $this->merchant_key)) {
            return false;
        }

        $merchantPem = trim((string) $this->merchant_pem);
        if ($merchantPem === '' || $merchantPem === self::PLACEHOLDER_MERCHANT_PEM) {
            return false;
        }

        return true;
    }

    /**
     * 校验字符串是否为 http(s) 绝对地址。
     *
     * @param string $url
     * @return bool
     */
    public static function isHttpUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    public static function getMethodMap()
    {
        return [
            self::METHOD_JUMP => admin_trans('pay.fields.method_jump'),
            self::METHOD_SCAN => admin_trans('pay.fields.method_scan'),
        ];
    }

    public static function getClientMap()
    {
        return [
            self::PAY_CLIENT_PC => admin_trans('pay.fields.pay_client_pc'),
            self::PAY_CLIENT_MOBILE => admin_trans('pay.fields.pay_client_mobile'),
            self::PAY_CLIENT_ALL => admin_trans('pay.fields.pay_client_all'),
        ];
    }

}
