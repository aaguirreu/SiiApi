<?php
namespace App\Console\Commands;

use App\Jobs\ProcessNewMail;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Commands\ImapIdleCommand;
use Webklex\IMAP\Facades\Client as ClientFacade;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class WorkerNewMailCommand extends ImapIdleCommand {

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'worker:newmail';

    /**
     * Holds the account information
     *
     * @var string|array $account
     */
    protected $account = "default";

    /**
     * Callback used for the idle command and triggered for every new received message
     * @param Message $message
     */
    public function onNewMessage(Message $message): void
    {
        $this->info("Command: New message received: ".$message->subject);
        // Debería funcionar mejor con redis que con database
        ProcessNewMail::dispatch($message);

        // Se marca como leído para evitar re-leerlo
        // Idealmente esto debería realizarse cuando el worker termine, pero no se le puede enviar el mensaje.
        // Una opción es que el worker obtenga nuevamente el mensaje a través del id.
        // ya que, al enviar el mensaje como parámetro éste deja de ser el mensaje original.
        $message->setFlag('Seen');
    }

    // Se edita ligeramente la función handle
    public function handle() {
        if (is_array($this->account)) {
            $client = ClientFacade::make($this->account);
        }else{
            $client = ClientFacade::account($this->account);
        }

        try {
            $client->connect();
        } catch (ConnectionFailedException $e) {
            Log::error($e->getMessage());
            return 1;
        }

        /** @var Folder $folder */
        try {
            $folder = $client->getFolder($this->folder_name);
        } catch (ConnectionFailedException $e) {
            Log::error($e->getMessage());
            return 1;
        } catch (FolderFetchingException $e) {
            Log::error($e->getMessage());
            return 1;
        }

        try {
            $folder->idle(function(Message $message){
                // Se agrega esta linea para evitar re-leer correos al moverlos al folder
                // Los correos nuevos no traen flags
                if(empty($message->getFlags()->toArray()))
                    $this->onNewMessage($message);
            }, $timeout = 1200, $auto_reconnect = true);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return 1;
        }

        return 0;
    }
}
