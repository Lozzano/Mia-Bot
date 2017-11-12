<?php

namespace App\Jobs;

use App\Http\Controllers\MiaBotController;
use BotMan\BotMan\BotMan;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $bot;
    protected $mia;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(BotMan $bot)
    {
        $this->bot = $bot;
        $this->mia = new MiaBotController;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $msg_txt = $this->bot->getMessage()->getText();

        if(strtolower($msg_txt) == 'start game') {
            $this->mia->start($this->bot);
        } elseif(preg_match('/^([1-9]{0,1}[0-9]+(,|\.)[0-6]|small mia|mia).*$/', strtolower($msg_txt))) {
            $this->mia->playRound($this->bot);
        } elseif(preg_match('/^play mia.*$/', strtolower($msg_txt))) {
            $this->mia->host($this->bot);
        } elseif(preg_match('/^(shake|liar).*$/', strtolower($msg_txt))) {
            $this->mia->playRound($this->bot);
        } elseif(strtolower($msg_txt) == 'abort game') {
            $this->mia->abort($this->bot);
        } elseif(preg_match('/^say .*$/i', $msg_txt)) {
            $this->mia->say($this->bot);
        }
    }
}
