<?php

namespace App\Jobs;

use http\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Providers\NewMailEvent;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\NotSupportedCapabilityException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

class ProcessNewMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(NewMailEvent $event)
    {
        echo "Handeling event";

        $cm = new ClientManager(base_path().'/config/imap.php');

        // Conectar a la cuenta definida en .env
        $client = $cm->account('default');
        $client->connect();

        // Obtener la carpeta Dtes
        $folder = $client->getFolderByPath('INBOX');

        // Escuchar mensajes nuevos
        try {
            $folder->idle(function ($message) {
                echo "New message with the subject '" . $message->subject . "' received\n";
            }, $timeout = 1200, $auto_reconnect = true);
        } catch (AuthFailedException $e) {
        } catch (ConnectionFailedException $e) {
        } catch (ImapBadRequestException $e) {
        } catch (ImapServerErrorException $e) {
        } catch (NotSupportedCapabilityException $e) {
        } catch (ResponseException $e) {
        } catch (RuntimeException $e) {
        }

        //$folder->setEvent("message", "new", NewMailEvent::class);
        //echo "Escuchando";
    }
}
