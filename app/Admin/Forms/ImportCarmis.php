<?php

namespace App\Admin\Forms;

use App\Models\Carmis;
use App\Models\Goods;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\Storage;

class ImportCarmis extends Form
{

    /**
     * Handle the form request.
     *
     * @param array $input
     *
     * @return mixed
     */
    public function handle(array $input)
    {
        // dcat 的 file 字段在没有上传文件时可能是 null；PHP 8 的
        // Flysystem::delete() 不接受 null，必须先做标量/空值判断。
        $carmisList = is_scalar($input['carmis_list'] ?? null)
            ? trim((string) $input['carmis_list'])
            : '';
        $carmisTxt = is_scalar($input['carmis_txt'] ?? null)
            ? trim((string) $input['carmis_txt'])
            : '';

        if ($carmisList === '' && $carmisTxt === '') {
            return $this->response()->error(admin_trans('carmis.rule_messages.carmis_list_and_carmis_txt_can_not_be_empty'));
        }

        $carmisContent = '';
        if ($carmisTxt !== '') {
            $carmisContent = (string) Storage::disk('public')->get($carmisTxt);
        }
        if ($carmisList !== '') {
            $carmisContent = $carmisList;
        }

        $carmisData = [];
        $tempList = explode(PHP_EOL, $carmisContent);
        foreach ($tempList as $val) {
            if (trim($val) != "") {
                $carmisData[] = [
                    'goods_id' => $input['goods_id'],
                    'carmi' => trim($val),
                    'status' => Carmis::STATUS_UNSOLD,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        if (empty($carmisData)) {
            return $this->response()->error(admin_trans('carmis.rule_messages.carmis_list_and_carmis_txt_can_not_be_empty'));
        }

        if ((int) ($input['remove_duplication'] ?? 0) === 1) {
            $carmisData = assoc_unique($carmisData, 'carmi');
        }

        Carmis::query()->insert($carmisData);

        // 只有确实上传了文件才删除，不能把 null 传给 delete()。
        if ($carmisTxt !== '') {
            Storage::disk('public')->delete($carmisTxt);
        }

        return $this
				->response()
				->success(admin_trans('carmis.rule_messages.import_carmis_success'))
				->location('/carmis');
    }

    /**
     * Build a form here.
     */
    public function form()
    {
        $this->confirm(admin_trans('carmis.fields.are_you_import_sure'));
        $this->select('goods_id')->options(
            Goods::query()->where('type', Goods::AUTOMATIC_DELIVERY)->pluck('gd_name', 'id')
        )->required();
        $this->textarea('carmis_list')
            ->rows(20)
            ->help(admin_trans('carmis.helps.carmis_list'));
        $this->file('carmis_txt')
            ->disk('public')
            ->uniqueName()
            ->accept('txt')
            ->maxSize(5120)
            ->help(admin_trans('carmis.helps.carmis_list'));
        $this->switch('remove_duplication');
    }

}
