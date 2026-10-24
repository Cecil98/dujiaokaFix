<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Service\MailConfigService;
use App\Service\SystemSettingService;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class MailSend implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 2;

    /**
     * 任务运行的超时时间。
     *
     * @var int
     */
    public $timeout = 30;

    private $to;

    private $content;

    private $title;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $to, string $title, string $content)
    {
        $this->to = $to;
        $this->title = $title;
        $this->content = $content;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $to = trim((string) $this->to);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            \Illuminate\Support\Facades\Log::warning('MailSend skipped: invalid recipient', [
                'recipient' => $this->to,
            ]);
            return;
        }

        $body = $this->content;
        $title = $this->title;
        $sysConfig = SystemSettingService::get();
        MailConfigService::apply($sysConfig);
        Mail::send('email.mail', ['body' => $body], function ($message) use ($to, $title){
            $message->to($to)->subject($title);
        });
    }
}
