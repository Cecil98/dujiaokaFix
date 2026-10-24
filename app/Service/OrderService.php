<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

namespace App\Service;


use App\Exceptions\RuleValidationException;
use App\Models\BaseModel;
use App\Models\Coupon;
use App\Models\Goods;
use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Rules\SearchPwd;
use App\Rules\VerifyImg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderService
{

    /**
     * 商品服务层.
     * @var \App\Service\PayService
     */
    private $goodsService;


    /**
     * 优惠码服务层
     * @var \App\Service\CouponService
     */
    private $couponService;

    public function __construct()
    {
        $this->goodsService = app('Service\GoodsService');
        $this->couponService = app('Service\CouponService');
    }


    /**
     * 验证集合
     *
     * @param Request $request
     * @throws RuleValidationException
     * @throws \Illuminate\Validation\ValidationException
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function validatorCreateOrder(Request $request): void
    {
        $validator = Validator::make($request->all(), [
            'gid' => ['required', 'integer'],
            'email' => ['required', 'email'],
            'coupon_code' => ['nullable', 'string', 'max:255'],
            'payway' => ['required', 'integer'],
            'search_pwd' => [new SearchPwd()],
            'by_amount' => ['required', 'integer', 'min:1'],
            'img_verify_code' => [new VerifyImg()],
        ], [
            'by_amount.required' =>  __('dujiaoka.prompt.buy_amount_format_error'),
            'by_amount.integer' =>  __('dujiaoka.prompt.buy_amount_format_error'),
            'by_amount.min' =>  __('dujiaoka.prompt.buy_amount_format_error'),
            'payway.required' =>  __('dujiaoka.prompt.please_select_mode_of_payment'),
            'payway.integer' =>  __('dujiaoka.prompt.please_select_mode_of_payment'),
            'email.required' =>  __('dujiaoka.prompt.email_format_error'),
            'email.email' =>  __('dujiaoka.prompt.email_format_error'),
            'gid.required' =>  __('dujiaoka.prompt.goods_does_not_exist'),
        ]);
        if ($validator->fails()) {
            throw new RuleValidationException($validator->errors()->first());
        }
        // 极验验证：Validator::make() 返回对象，不能直接取反；必须检查 fails()。
        // 注意必须带 required：自定义扩展规则对不存在的字段是「跳过」而不是「失败」，
        // 否则攻击者只要不提交 geetest_challenge 就能绕过极验（PQ-006）。
        if (dujiaoka_config_get('is_open_geetest') == BaseModel::STATUS_OPEN) {
            $geetestValidator = Validator::make(
                $request->all(),
                ['geetest_challenge' => 'required|geetest'],
                ['geetest' => __('dujiaoka.prompt.geetest_validate_fail')]
            );
            if ($geetestValidator->fails()) {
                throw new RuleValidationException(__('dujiaoka.prompt.geetest_validate_fail'));
            }
        }
        // 支付渠道校验：必须存在、已启用，易支付还需要可用凭据（PQ-01）
        $this->validatorSelectedPay((int) $request->input('payway'));
    }

    /**
     * 校验下单时选择的支付渠道。
     *
     * 防止客户端直接提交未启用、已删除或仍使用出厂占位符凭据的渠道 id。
     *
     * @param int $payID 支付渠道 id
     * @throws RuleValidationException
     */
    public function validatorSelectedPay(int $payID): void
    {
        $pay = Pay::query()
            ->where('id', $payID)
            ->where('is_open', Pay::STATUS_OPEN)
            ->first();
        if (!$pay) {
            throw new RuleValidationException(__('dujiaoka.prompt.please_select_mode_of_payment'));
        }
        if ($pay->isYipayGateway() && !$pay->hasUsableYipayCredentials()) {
            throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    /**
     * 得到商品详情并验证
     *
     * @param Request $request 请求
     * @throws RuleValidationException
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function validatorGoods(Request $request): Goods
    {
        // 获得商品详情（validatorCreateOrder 已保证 gid 是整数）
        $goods = $this->goodsService->detail((int) $request->input('gid'));
        // 商品状态验证
        $this->goodsService->validatorGoodsStatus($goods);
        // 如果有限购
        if ($goods->buy_limit_num > 0 && $request->input('by_amount') > $goods->buy_limit_num) {
            throw new RuleValidationException(__('dujiaoka.prompt.purchase_limit_exceeded'));
        }
        // 库存不足
        if ($request->input('by_amount') > $goods->in_stock) {
            throw new RuleValidationException(__('dujiaoka.prompt.inventory_shortage'));
        }
        return $goods;
    }
	
    /**
     * 判断是否有循环卡密
     *
     * @param int $goodsID 商品id
     * @return array|null
     *
     * @author    ZhangYiQiu<me@zhangyiqiu.net>
     * @copyright ZhangYiQiu<me@zhangyiqiu.net>
     * @link      http://zhangyiqiu.net/
     */
    public function validatorLoopCarmis(Request $request)
    {
        $carmis = Carmis::query()
            ->where('goods_id', $request->input('gid'))
            ->where('status', Carmis::STATUS_UNSOLD)
            ->where('is_loop', true)
            ->count();
        if($carmis > 0 && $request->input('by_amount') > 1){
			throw new RuleValidationException(__('dujiaoka.prompt.loop_carmis_limit'));
		}
		return $carmis;
    }

    /**
     * 优惠码验证
     *
     * @param Request $request
     * @return Coupon|null
     * @throws RuleValidationException
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function validatorCoupon(Request $request):? Coupon
    {
        // 如果提交了优惠码
        if ($request->filled('coupon_code')) {
            $couponCode = $request->input('coupon_code');
            if (! is_scalar($couponCode)) {
                throw new RuleValidationException(__('dujiaoka.prompt.server_illegal_request'));
            }
            // 查询优惠码是否存在
            $coupon = $this->couponService->withHasGoods(
                trim((string) $couponCode),
                (int) $request->input('gid')
            );
            // 此商品没有这个优惠码
            if (empty($coupon)) {
                throw new RuleValidationException(__('dujiaoka.prompt.coupon_does_not_exist'));
            }
            // 剩余次数不足
            if ($coupon->ret <= 0) {
                throw new RuleValidationException(__('dujiaoka.prompt.coupon_lack_of_available_opportunities'));
            }
            return $coupon;
        }
        return null;
    }

    /**
     * 代充框验证.
     *
     * @param Goods $goods
     * @param Request $request
     * @return string
     * @throws RuleValidationException
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function validatorChargeInput(Goods $goods, Request $request): string
    {
        $otherIpt = '';
        // 代充框验证
        if ($goods->type == Goods::MANUAL_PROCESSING && !empty($goods->other_ipu_cnf)) {
            // 如果有其他输入框 判断其他输入框内容  然后载入信息
            $formatIpt = format_charge_input($goods->other_ipu_cnf) ?: [];
            foreach ($formatIpt as $item) {
                if ($item['rule'] && !$request->filled($item['field'])) {
                    $errMessage = $item['desc'] . __('dujiaoka.prompt.can_not_be_empty');
                    throw new RuleValidationException($errMessage);
                }
                $otherIpt .= $item['desc'].':'.$request->input($item['field']) . PHP_EOL;
            }
        }
        return $otherIpt;
    }

    /**
     * 通过订单号查询订单
     * @param string $orderSN 订单号
     * @param bool $lockForUpdate 是否加行锁（必须在事务内调用）
     * @return Order
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function detailOrderSN(string $orderSN, bool $lockForUpdate = false):? Order
    {
        $order = Order::query()
            ->with(['coupon', 'pay', 'goods'])
            ->where('order_sn', $orderSN)
            ->when($lockForUpdate, function ($query) {
                $query->lockForUpdate();
            })
            ->first();
        return $order;
    }

    /**
     * 根据订单号过期订单.
     *
     * 只有待支付订单才会被置为已过期，并根据影响行数判断是否真的发生了变更
     * （用于决定是否回退优惠券，见 PQ-02）。
     *
     * @param string $orderSN
     * @return bool
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function expiredOrderSN(string $orderSN): bool
    {
        $affected = Order::query()
            ->where('order_sn', $orderSN)
            ->where('status', Order::STATUS_WAIT_PAY)
            ->update(['status' => Order::STATUS_EXPIRED]);
        return $affected > 0;
    }

    /**
     * 设置订单优惠码已回退
     *
     * @param string $orderSN
     * @return bool
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function couponIsBack(string $orderSN): bool
    {
        return Order::query()->where('order_sn', $orderSN)->update(['coupon_ret_back' => Order::COUPON_BACK_OK]);
    }

    /**
     * 通过邮箱和查询密码查询
     *
     * 为避免匿名者仅凭邮箱取走卡密（PQ-04），调用方必须提供归属证明：
     * 查询密码或浏览器 cookie 里的订单号集合。两者都为空时返回空集合。
     *
     * @param string $email 邮箱
     * @param string $searchPwd 查询密码
     * @param array $orderSNS 订单号集合（通常来自 dujiaoka_orders cookie）
     * @return \Illuminate\Database\Eloquent\Collection
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function withEmailAndPassword(string $email, string $searchPwd = '', array $orderSNS = [])
    {
        $orderSNS = array_values(array_unique(array_filter(
            array_map(function ($orderSN) {
                return is_scalar($orderSN) ? trim((string) $orderSN) : '';
            }, $orderSNS),
            function (string $orderSN) {
                return $orderSN !== '';
            }
        )));

        // 没有任何归属证明时不得按邮箱查询
        if ($searchPwd === '' && empty($orderSNS)) {
            return Order::query()->whereRaw('1 = 0')->get();
        }

        return Order::query()
            ->where('email', $email)
            ->when($searchPwd !== '', function ($query) use ($searchPwd) {
                $query->where('search_pwd', $searchPwd);
            })
            // 只有「没有查询密码、仅靠浏览器 cookie」这条弱证明路径才用订单号
            // 收窄结果；查询密码本身已是归属证明，不能再被 cookie 内容限制，
            // 否则换浏览器/清 cookie 的老客户查不到自己的订单。
            ->when($searchPwd === '' && !empty($orderSNS), function ($query) use ($orderSNS) {
                $query->whereIn('order_sn', $orderSNS);
            })
            ->orderBy('created_at', 'DESC')
            ->take(5)
            ->get();
    }

    /**
     * 通过订单号集合查询
     *
     * @param array $orderSNS 订单号集合
     * @return \Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Query\Builder[]|\Illuminate\Support\Collection
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function byOrderSNS(array $orderSNS)
    {
        return Order::query()
            ->whereIn('order_sn', $orderSNS)
            ->orderBy('created_at', 'DESC')
            ->take(5)
            ->get();
    }

}
