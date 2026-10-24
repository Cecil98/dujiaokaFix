<p align="center"><img src="https://i.loli.net/2020/04/07/nAzjDJlX7oc5qEw.png" width="400"></p>
<p align="center">
<a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/license-MIT-blue" alt="license MIT"></a>
<a href="#"><img src="https://img.shields.io/badge/version-2.1.0--php83-red" alt="version 2.1.0-php83"></a>
<a href="https://www.php.net/releases/8.3/en.php"><img src="https://img.shields.io/badge/PHP-8.3-lightgrey" alt="php83"></a>
<a href="https://laravel.com/docs/12.x"><img src="https://img.shields.io/badge/Laravel-12.x-red" alt="Laravel 12"></a>
</p>

<div align="center">
  <a href="https://www.vmrack.net/?ref_code=5iXmGUMf5f5">
    <img src="https://files.seeusercontent.com/2026/03/12/2mXf/photo_2026-03-12_10-13-12.jpg" width="400">
  </a>
  <p align="center"><a href="https://www.vmrack.net/?ref_code=5iXmGUMf5f5">vmrack.net - 全球自动化云基础设施服务商
    提供先进的云服务器、裸金属、CDN、媒体处理、对象存储和网络解决方案，助力企业轻松上云</a></p>
</div>

## 📢停更通知2026/02/12

### 项目已经停止更新和维护，请前往新版[Dujiao-Next(dujiao-next.com)](https://dujiao-next.com)

## PHP 8.3 / Laravel 12 升级说明（本 fork）

- 运行时：PHP **8.3**、Laravel **12.x**、dcat-admin 自持 fork **2.2.5**。
- 支付：默认只启用**易支付（yipay）**；其他渠道代码保留但 `pays.is_open=0`。
- 数据库：全新安装使用 `database/sql/install.sql`；旧库升级执行 `database/sql/upgrade-php83.sql`，只增不删。
- 升级后执行一次支付密钥加密（旧库明文会自动兼容读取）：

  ```bash
  php artisan pay:encrypt-secrets
  ```

  回滚旧代码前执行 `php artisan pay:decrypt-secrets`。
- Docker 首次安装需显式 `INSTALL=true`；安装完成后改成 `INSTALL=false`，容器会写 `install.lock`。
- HTTPS 生产环境设置 `SESSION_SECURE_COOKIE=true`，并保留 `APP_DEBUG=false`。
- 安全审计记录见 `docs/security-audit/php83-upgrade/审计报告.md`。
