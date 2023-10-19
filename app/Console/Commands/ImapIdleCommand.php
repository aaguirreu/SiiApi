<?php
namespace App\Console\Commands;

use App\Jobs\ProcessImapIdle;

class WorkerNewMailCommand {

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
