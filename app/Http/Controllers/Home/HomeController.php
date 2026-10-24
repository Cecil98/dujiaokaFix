<?php

namespace App\Http\Controllers\Home;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\BaseController;
use App\Models\Pay;
use App\Service\Geetest\Geetest;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HomeController extends BaseController
{

    /**
     * 商品服务层.
     * @var \App\Service\PayService
     */
    private $goodsService;

    /**
     * 支付服务层
     * @var \App\Service\PayService
     */
    private $payService;

    public function __construct()
    {
        $this->goodsService = app('Service\GoodsService');
        $this->payService = app('Service\PayService');
    }

    /**
     * 首页.
     *
     * @param Request $request
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function index(Request $request)
    {
        $goods = $this->goodsService->withGroup();
        return $this->render('static_pages/home', ['data' => $goods], __('dujiaoka.page-title.home'));
    }

    /**
     * 商品详情
     *
     * @param int $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function buy(int $id)
    {
        try {
            $goods = $this->goodsService->detail($id);
            $this->goodsService->validatorGoodsStatus($goods);
            // 有没有优惠码可以展示
            if (count($goods->coupon)) {
                $goods->open_coupon = 1;
            }
            $formatGoods = $this->goodsService->format($goods);
            // 加载支付方式.
            $client = Pay::PAY_CLIENT_PC;
            if (app('Jenssegers\Agent')->isMobile()) {
                $client = Pay::PAY_CLIENT_MOBILE;
            }
            $formatGoods->payways = $this->payService->pays($client);
            return $this->render('static_pages/buy', $formatGoods, $formatGoods->gd_name);
        } catch (RuleValidationException $ruleValidationException) {
            return $this->err($ruleValidationException->getMessage());
        }

    }

    /**
     * 极验行为验证
     *
     * @param Request $request
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function geetest(Request $request)
    {
        $data = [
            'user_id' => @Auth::user()?@Auth::user()->id:'UnLoginUser',
            'client_type' => 'web',
            'ip_address' => \Illuminate\Support\Facades\Request::ip()
        ];
        $status = Geetest::preProcess($data);
        session()->put('gtserver', $status);
        session()->put('user_id', $data['user_id']);
        return Geetest::getResponseStr();
    }

    /**
     * 安装页面
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function install(Request $request)
    {
        return view('common/install');
    }

    /**
     * 执行安装
     *
     * @param Request $request
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function doInstall(Request $request)
    {
        try {
            $adminUsername = trim((string) $request->input('admin_username', 'admin'));
            $adminPassword = (string) $request->input('admin_password');

            foreach ([
                'db_host', 'db_port', 'db_database', 'db_username', 'db_password',
                'redis_host', 'redis_password', 'redis_port', 'title', 'app_url', 'admin_path',
            ] as $field) {
                if (preg_match('/[\r\n]/', (string) $request->input($field))) {
                    return '输入内容包含非法字符';
                }
            }

            if (! preg_match('/^[A-Za-z0-9_]{3,32}$/', $adminUsername)) {
                return '后台管理员账号只能包含字母、数字、下划线，长度 3-32 位';
            }
            if (strlen($adminPassword) < 10) {
                return '后台管理员密码至少 10 位';
            }
            if (! filter_var((string) $request->input('app_url'), FILTER_VALIDATE_URL)) {
                return '网站 URL 格式不正确';
            }
            if (! preg_match('#^/[A-Za-z0-9_-]+$#', (string) $request->input('admin_path', '/admin'))) {
                return '后台登录路径格式不正确（例如 /admin）';
            }

            $dbConfig = config('database');
            $mysqlDB = [
                'host' => $request->input('db_host'),
                'port' => $request->input('db_port'),
                'database' => $request->input('db_database'),
                'username' => $request->input('db_username'),
                'password' => $request->input('db_password'),
            ];
            $dbConfig['connections']['mysql'] = array_merge($dbConfig['connections']['mysql'], $mysqlDB);
            // Redis
            $redisDB = [
                'host' => $request->input('redis_host'),
                'password' => $request->input('redis_password', 'null'),
                'port' => $request->input('redis_port'),
            ];
            $dbConfig['redis']['default'] = array_merge($dbConfig['redis']['default'], $redisDB);
            config(['database' => $dbConfig]);
            DB::purge();
            // db测试
            DB::connection()->select('select 1 limit 1');
            // redis测试
            Redis::set('dujiaoka_com', 'ok');
            Redis::get('dujiaoka_com');
            // 获得文件模板
            $envExamplePath = base_path() . DIRECTORY_SEPARATOR . '.env.example';
            $envPath =  base_path() . DIRECTORY_SEPARATOR . '.env';
            $installLock = base_path() . DIRECTORY_SEPARATOR . 'install.lock';
            $installSql = database_path() . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'install.sql';
            $envTemp = file_get_contents($envExamplePath);
            $postData = $request->all();
            // 临时写入key
            $postData['app_key'] = 'base64:' . base64_encode(
                    Encrypter::generateKey(config('app.cipher'))
                );
            foreach ($postData as $key => $item) {
                if (is_string($item)) {
                    // Values are written inside double quotes in .env.example.
                    $item = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $item);
                }
                $envTemp = str_replace('{' . $key . '}', $item, $envTemp);
            }
            // 写入配置
            file_put_contents($envPath, $envTemp);
            // 导入sql
            DB::unprepared(file_get_contents($installSql));
            // 初始化后台管理员账号，避免使用默认的 admin/admin
            $updated = DB::table('admin_users')->where('id', 1)->update([
                'username'   => $adminUsername,
                'password'   => bcrypt($adminPassword),
                'name'       => 'Administrator',
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new \RuntimeException('后台管理员账号初始化失败');
            }
            // 写入安装锁
            file_put_contents($installLock, 'install ok');
            return 'success';
        } catch (\RedisException $exception) {
            \Illuminate\Support\Facades\Log::error('Install Redis error: '.$exception->getMessage());
            return 'Redis 配置错误，请检查连接参数后重试';
        } catch (QueryException $exception) {
            \Illuminate\Support\Facades\Log::error('Install database error: '.$exception->getMessage());
            return '数据库配置错误，请检查连接参数后重试';
        } catch (\Exception $exception) {
            \Illuminate\Support\Facades\Log::error('Install error: '.$exception->getMessage());
            return '安装失败，请检查服务器日志后重试';
        }
    }


}
