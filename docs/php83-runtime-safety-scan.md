# PHP 8.3 / Laravel 12 运行时类型安全扫描

> 范围：`app/Http/Controllers`、`app/Service`、`app/Jobs`、`app/Listeners`、`app/Rules`、`app/Helpers`、`app/Admin/Forms`。
> 目标：找出 PHP 7.4 → PHP 8.3 / Carbon 3 升级后，旧数据或旧 cache 仍可能传入
> `int|float|string` 强类型参数而导致的 500。

## 已确认并修复

| # | 位置 | 触发条件 | 结果 |
|---|---|---|---|
| 1 | `OrderProcessService::createOrder()` | `order_expire_time` 从旧 cache 恢复为字符串 `"5"`，传给 Carbon 3 `addMinutes()` | TypeError，下单 500 |
| 2 | `Admin\Forms\ImportCarmis::handle()` | 未上传 txt 时 `carmis_txt=null`，传给 Flysystem `delete()` | TypeError，卡密已插入但页面 500 |
| 3 | `Home\OrderController::queueCookie()` | `dujiaoka_orders` cookie 不是合法 JSON，`json_decode()` 返回 null，`array_push(null, ...)` | TypeError，订单已创建但响应 500 |
| 4 | `GoodsService::validatorGoodsStatus()` | 商品 id 不存在，`detail()` 返回 null，却传给 `Goods $goods` | TypeError，购买页 500 |
| 5 | `OrderService::validatorCreateOrder()` | `gid` / `coupon_code` 提交数组，未做标量/整数校验 | TypeError |
| 6 | `OrderService::validatorChargeInput()` / `calculateTheWholesalePrice()` | 旧商品配置格式错误，`format_*()` 返回 null 后 `foreach(null)` | ErrorException / 500 |
| 7 | `EmailtplService::detailByToken()` | 邮件模板被删除，`first()` 返回 null，却声明返回 `Emailtpl` | TypeError |
| 8 | `OrderUpdated` 邮件监听 | 邮件模板缺失时直接 `first()->toArray()` | 500，订单状态更新失败 |
| 9 | `DujiaoBoot` / `BaseController` / `Handler` | 旧系统设置中的 `language` / `template` 为 null 或数组 | 类型错误或错误模板路径 |
| 10 | `YipayController::returnUrl()` / `PayjsController::notifyUrl()` | 回调 query/body 参数为数组 | 类型错误 |

## 修复方式

- 对系统设置中的数字先 `is_numeric()` + `(int)` 归一化，并设置下限。
- 对 `null` 可选对象使用 `?Type`，并在进入业务前抛 `RuleValidationException`。
- 对请求输入使用 `is_scalar()` / 框架 `integer|string` 规则。
- 对 `json_decode()`、`format_*()`、`first()` 的返回值先判断类型。
- 对缺失邮件模板/收件人采用“跳过通知，不回滚订单”。
- 新增回归测试：`tests/Unit/RuntimeSafetyTest.php`、`tests/Unit/OrderExpiryTest.php`、`tests/Unit/SecurityRegressionSourceTest.php`。

## 未纳入本轮

- 默认禁用的支付渠道（Coinbase / PAYJS / Epusdt / TokenPay / Vpay / Mapay / Paysapi）只做代码保留；启用前需单独做真实回调回归。
- Dcat 后台自定义表单的恶意数组输入不是本扫描重点；已修 `ImportCarmis` / `EmailTest` 的空值路径。
- PHPStan 在无 Larastan 配置下会把 Eloquent 动态属性报为 undefined；本次只用它辅助定位类型问题，未把 PHPStan 作为门禁。

## 验证

```bash
docker run --rm -v "$PWD":/app -w /app webdevops/php:8.3 \
  bash -lc 'vendor/bin/phpunit'
# 51 tests, 183 assertions
```
