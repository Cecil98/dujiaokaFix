<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

namespace App\Service;


use App\Models\Carmis;

class CarmisService
{

    /**
     * 通过商品查询一些数量未使用的卡密
     *
     * 必须在事务内调用：orderBy('id') + lockForUpdate() 让并发订单串行取卡，
     * 同时补齐 is_loop = 0 与写侧保持一致（PQ-06-B）。
     *
     * @param int $goodsID 商品id
     * @param int $byAmount 数量
     * @return array
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function withGoodsByAmountAndStatusUnsold(int $goodsID, int $byAmount)
    {
        $carmis = Carmis::query()
            ->where('goods_id', $goodsID)
            ->where('status', Carmis::STATUS_UNSOLD)
            ->where('is_loop', 0)
            ->orderBy('id')
            ->take($byAmount)
            ->lockForUpdate()
            ->get();
        return $carmis->toArray();
    }

    /**
     * 通过id集合设置卡密已售出
     *
     * 带上 status = UNSOLD 的 CAS 谓词并返回受影响行数，调用方必须断言行数等于
     * 期望数量，否则回滚事务（PQ-06-B）。
     *
     * @param array $ids 卡密id集合
     * @return int 受影响行数
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function soldByIDS(array $ids): int
    {
        return (int) Carmis::query()
            ->whereIn('id', $ids)
            ->where('status', Carmis::STATUS_UNSOLD)
            ->where('is_loop', 0)
            ->update(['status' => Carmis::STATUS_SOLD]);
    }

}
