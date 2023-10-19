<?php
namespace App\Console\Commands;

use App\Jobs\ProcessImapIdle;
use Illuminate\Console\Command;
use mysql_xdevapi\Exception;

class ImapIdleCommand extends Command {

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:idle';

    /**
     * Execute the command.
     *
     * @return void
     */
    public function handle() {
        ProcessImapIdle::dispatch();
    }
}
