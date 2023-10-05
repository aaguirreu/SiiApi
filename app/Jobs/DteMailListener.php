<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webklex\PHPIMAP\ClientManager;

class DteMailListener implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $cm = new ClientManager(base_path().'/config/imap.php');
        $client = $cm->account('default');
        //Connect to the IMAP Server
        $client->connect();
        while ($client->isConnected()) {
            echo "Esperando correos...\n";
            $folder = $client->getFolderByPath('INBOX');
            $folder->idle(function($message){
                echo "New message with the subject '".$message->subject."' received\n";
            }, $timeout = 1200, $auto_reconnect = true);
        }
    }
}
