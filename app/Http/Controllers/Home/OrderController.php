<?php

namespace App\Http\Controllers\Home;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\BaseController;
use App\Models\Order;
use App\Service\OrderProcessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;


/**
 * 订单控制器
 *
 * Class OrderController
 * @package App\Http\Controllers\Home
 * @author: Assimon
 * @email: Ashang@utf8.hk
 * @blog: https://utf8.hk
 * Date: 2021/5/30
 */
class OrderController extends BaseController
{


    /**
     * 订单服务层
     * @var \App\Service\OrderService
     */
    private $orderService;

    /**
     * 订单处理层.
     * @var OrderProcessService
     */
    private $orderProcessService;

    public function __construct()
    {
        $this->orderService = app('Service\OrderService');
        $this->orderProcessService = app('Service\OrderProcessService');
    }

    /**
     * 创建订单
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Illuminate\Validation\ValidationException
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function createOrder(Request $request)
    {
        DB::beginTransaction();
        try {
            $this->orderService->validatorCreateOrder($request);
            $goods = $this->orderService->validatorGoods($request);
            $this->orderService->validatorLoopCarmis($request);
            // 设置商品
            $this->orderProcessService->setGoods($goods);
            // 优惠码
            $coupon = $this->orderService->validatorCoupon($request);
            // 设置优惠码
            $this->orderProcessService->setCoupon($coupon);
            $otherIpt = $this->orderService->validatorChargeInput($goods, $request);
            $this->orderProcessService->setOtherIpt($otherIpt);
            // 数量
            $this->orderProcessService->setBuyAmount((int) $request->input('by_amount'));
            // 支付方式
            $this->orderProcessService->setPayID((int) $request->input('payway'));
            // 下单邮箱
            $this->orderProcessService->setEmail((string) $request->input('email'));
            // ip地址
            $this->orderProcessService->setBuyIP($request->getClientIp());
            // 查询密码
            $searchPwd = $request->input('search_pwd', '');
            $this->orderProcessService->setSearchPwd(
                is_scalar($searchPwd) ? trim((string) $searchPwd) : ''
            );
            // 创建订单
            $order = $this->orderProcessService->createOrder();
            DB::commit();
            // 设置订单cookie
            $this->queueCookie($order->order_sn);
            return redirect(url('/bill', ['orderSN' => $order->order_sn]));
        } catch (RuleValidationException $exception) {
            DB::rollBack();
            return $this->err($exception->getMessage());
        }
    }

    /**
     * 设置订单cookie.
     * @param string $orderSN 订单号.
     */
    private function queueCookie(string $orderSN) : void
    {
        // 设置订单cookie
        $cookies = Cookie::get('dujiaoka_orders');
        if (empty($cookies)) {
            Cookie::queue('dujiaoka_orders', json_encode([$orderSN]));
        } else {
            // 旧 cookie 可能不是合法 JSON；json_decode() 会返回 null，
            // PHP 8 下 array_push(null, ...) 会抛 TypeError，导致订单已
            // 创建但响应 500。这里始终回退为数组。
            $cookies = json_decode($cookies, true);
            if (! is_array($cookies)) {
                $cookies = [];
            }
            $cookies[] = $orderSN;
            Cookie::queue('dujiaoka_orders', json_encode(array_values(array_unique($cookies))));
        }
    }

    /**
     * 结账
     *
     * @param string $orderSN
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function bill(string $orderSN)
    {
        $order = $this->orderService->detailOrderSN($orderSN);
        if (empty($order)) {
            return $this->err(__('dujiaoka.prompt.order_does_not_exist'));
        }
        if ($order->status == Order::STATUS_EXPIRED) {
            return $this->err(__('dujiaoka.prompt.order_is_expired'));
        }
        return $this->render('static_pages/bill', $order, __('dujiaoka.page-title.bill'));
    }


    /**
     * 订单状态监测
     *
     * @param string $orderSN 订单号
     * @return \Illuminate\Http\JsonResponse
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function checkOrderStatus(string $orderSN)
    {
        $order = $this->orderService->detailOrderSN($orderSN);
        // 订单不存在或者已经过期
        if (!$order || $order->status == Order::STATUS_EXPIRED) {
            return response()->json(['msg' => 'expired', 'code' => 400001]);
        }
        // 订单已经支付
        if ($order->status == Order::STATUS_WAIT_PAY) {
            return response()->json(['msg' => 'wait....', 'code' => 400000]);
        }
        // 成功
        if ($order->status > Order::STATUS_WAIT_PAY) {
            return response()->json(['msg' => 'success', 'code' => 200]);
        }
    }

    /**
     * 通过订单号展示订单详情
     *
     * @param string $orderSN 订单号.
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function detailOrderSN(string $orderSN)
    {
        $order = $this->orderService->detailOrderSN($orderSN);
        // 订单不存在或者已经过期
        if (!$order) {
            return $this->err(__('dujiaoka.prompt.order_does_not_exist'));
        }
        return $this->render('static_pages/orderinfo', ['orders' => [$order]], __('dujiaoka.page-title.order-detail'));
    }

    /**
     * 订单号查询
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function searchOrderBySN(Request $request)
    {
        // input() 可能返回 null/数组，强转为字符串避免 PHP 8 的 TypeError
        $orderSN = $request->input('order_sn', '');
        return $this->detailOrderSN(is_scalar($orderSN) ? trim((string) $orderSN) : '');
    }

    /**
     * 通过邮箱查询
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function searchOrderByEmail(Request $request)
    {
        $email = (string) $request->input('email', '');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->err(__('dujiaoka.prompt.server_illegal_request'));
        }
        $searchPwd = trim((string) $request->input('search_pwd', ''));
        $searchPwdRequired = dujiaoka_config_get(
            'is_open_search_pwd',
            \App\Models\BaseModel::STATUS_CLOSE
        ) == \App\Models\BaseModel::STATUS_OPEN;
        if ($searchPwdRequired && $searchPwd === '') {
            return $this->err(__('dujiaoka.prompt.server_illegal_request'));
        }
        // 浏览器 cookie 里记录的订单号属于归属证明（PQ-04）
        $orderSNS = $this->cookieOrderSNS();
        if ($searchPwd === '' && empty($orderSNS)) {
            return $this->err(__('dujiaoka.prompt.no_related_order_found'));
        }
        $orders = $this->orderService->withEmailAndPassword($email, $searchPwd, $orderSNS);
        if ($orders->isEmpty()) {
            return $this->err(__('dujiaoka.prompt.no_related_order_found'));
        }
        return $this->render('static_pages/orderinfo', ['orders' => $orders], __('dujiaoka.page-title.order-detail'));
    }

    /**
     * 读取并净化浏览器 cookie 中的订单号集合。
     *
     * @return array
     */
    private function cookieOrderSNS(): array
    {
        $cookies = Cookie::get('dujiaoka_orders');
        if (!is_string($cookies) || $cookies === '') {
            return [];
        }
        $decoded = json_decode($cookies, true);
        if (!is_array($decoded)) {
            return [];
        }
        $orderSNS = [];
        foreach ($decoded as $orderSN) {
            if (!is_scalar($orderSN)) {
                continue;
            }
            $orderSN = trim((string) $orderSN);
            if ($orderSN !== '' && strlen($orderSN) <= 64) {
                $orderSNS[] = $orderSN;
            }
        }
        return array_values(array_unique($orderSNS));
    }

    /**
     * 通过浏览器缓存查询
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function searchOrderByBrowser(Request $request)
    {
        $orderSNS = $this->cookieOrderSNS();
        if (empty($orderSNS)) {
            return $this->err(__('dujiaoka.prompt.no_related_order_found_for_cache'));
        }
        $orders = $this->orderService->byOrderSNS($orderSNS);
        return $this->render('static_pages/orderinfo', ['orders' => $orders], __('dujiaoka.page-title.order-detail'));
    }

    /**
     * 订单查询页
     *
     * @param Request $request
     * @return mixed
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function orderSearch(Request $request)
    {
        return $this->render('static_pages/searchOrder', [], __('dujiaoka.page-title.order-search'));
    }

}
