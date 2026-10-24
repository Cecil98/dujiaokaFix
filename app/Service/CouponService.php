<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

namespace App\Service;


use App\Models\Coupon;

class CouponService
{

    /**
     * 获得优惠码，通过商品关联
     *
     * @param string $coupon 优惠码
     * @param int $goodsID 商品id
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|null
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function withHasGoods(string $coupon, int $goodsID)
    {
        $coupon = Coupon::query()->whereHas('goods', function ($query) use ($goodsID) {
            $query->where('goods_id', $goodsID);
        })->where('is_open', Coupon::STATUS_OPEN)->where('coupon', $coupon)->first();
        return $coupon;
    }

    /**
     * 设置优惠券已使用
     * @param string $coupon
     * @return bool
     */
    public function used(string $coupon): bool
    {
        return Coupon::query()
            ->where('coupon',  $coupon)
            ->update(['is_use' => Coupon::STATUS_USE]);
    }

    /**
     * 设置优惠券使用次数 -1
     *
     * 原子条件扣减：只有 ret > 0 时才会自减，避免并发下先查后减把次数扣成负数
     * （PQ-06-A）。返回受影响行数，调用方为 0 时应回滚事务。
     *
     * @param string $coupon
     * @return int 受影响行数
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function retDecr(string $coupon): int
    {
        return (int) Coupon::query()
            ->where('coupon',  $coupon)
            ->where('ret', '>', 0)
            ->decrement('ret', 1);
    }

    /**
     * 设置优惠券次数+1
     *
     * @param int $id
     * @return int
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function retIncrByID(int $id)
    {
        return Coupon::query()->where('id',  $id)->increment('ret', 1);
    }

}
