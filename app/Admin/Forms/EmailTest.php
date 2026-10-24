<?php
/**
 * The file was created by Assimon.
 *
 * @author    ZhangYiQiu<me@zhangyiqiu.net>
 * @copyright ZhangYiQiu<me@zhangyiqiu.net>
 * @link      http://zhangyiqiu.net/
 */

namespace App\Admin\Forms;

use App\Models\BaseModel;
use Dcat\Admin\Widgets\Form;
use App\Service\MailConfigService;
use App\Service\SystemSettingService;
use Illuminate\Support\Facades\Mail;

class EmailTest extends Form
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
      $to = (string) ($input['to'] ?? '');
      $title = (string) ($input['title'] ?? '');
      $body = (string) ($input['body'] ?? '');
      $sysConfig = SystemSettingService::get();
      MailConfigService::apply($sysConfig);
	  try
	  {
		  Mail::send('email.mail', ['body' => $body], function ($message) use ($to, $title){
			  $message->to($to)->subject($title);
		  });
	  }
	  catch(\Exception $e)
	  {
		  return $this
					->response()
					->error($e->getMessage());
	  }
      return $this
				->response()
				->success(admin_trans('email-test.labels.success'));
    }

    /**
     * Build a form here.
     */
    public function form()
    {
        $this->tab(admin_trans('menu.titles.email_test'), function () {
            $this->text('to', admin_trans('email-test.labels.to'))->required();
            $this->text('title', admin_trans('email-test.labels.title'))->default('这是一条测试邮件')->required();
            $this->editor('body', admin_trans('email-test.labels.body'))->default("这是一条测试邮件的正文内容<br/><br/>正文比较长<br/><br/>非常长<br/><br/>测试测试测试")->required();
        });
    }

    public function default()
    {
      
    }

}
