<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Storage;
use Webklex\IMAP\Facades\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class ProcessImapIdle implements ShouldQueue, ShouldBeUnique
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
     * Callback used for the idle command and triggered for every new received message
     * @param Message $message
     */
    public function onNewMessage(Message $message): void
    {
        //$this->info("Command: New message received: ".$message->subject);
        // Debería funcionar mejor con redis que con database
        $this->workerJob($message);
        ProcessNewMail::dispatch($message->uid);
        // Se marca como leído para evitar re-leerlo
        // Idealmente esto debería realizarse cuando el worker termine, pero no se le puede enviar el mensaje.
        // Una opción es que el worker obtenga nuevamente el mensaje a través del id.
        // ya que, al enviar el mensaje como parámetro éste deja de ser el mensaje original.
        $message->setFlag('Seen');
    }

    public function workerJob($message) {
        // Obtener header
        //return $this->message->getHeader();

        // Obtener body
        //return $this->message->getTextBody();

        // Obtener adjuntos
        if ($message->hasAttachments()) {
            $attachmentsInfo = [];

            /* @var  \Webklex\PHPIMAP\Support\MessageCollection $attachments*/
            $attachments = $message->getAttachments();
            foreach ($attachments as $attachment) {
                /**
                 * Obtener el contenido del adjunto
                 *
                 * @var \Webklex\PHPIMAP\Attachment $attachment
                 * @var string $content
                 */
                $content = $attachment->getContent();

                // Convertir el contenido a UTF-8 (solo para mostrar por pantalla)
                $utf8Content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

                $attachmentInfo = [
                    'filename' => $attachment->getName(),
                    'content' => $utf8Content,
                ];
                //$this->info($attachment->getName()."\n".$content);
                if(str_ends_with($attachment->getName(), '.xml')) {
                    Storage::disk('dtes')->put('EnvioFACTURA\\'.$attachment->getName(), $content);
                    $attachmentsInfo[] = $attachmentInfo;
                }
            }
            // Devolver la información de los adjuntos
            //Log::channel(env('LOG_CHANNEL'))->info(json_decode(json_encode($attachmentsInfo)));
            echo json_encode($attachmentsInfo);
        } else {
            Log::channel(env('LOG_CHANNEL'))->info("No hay adjuntos");
        }
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $client = Client::account('default');
        try {
            $client->connect();
        } catch (ConnectionFailedException $e) {
            Log::error($e->getMessage());
            return 1;
        }

        /** @var Folder $folder */
        try {
            $folder = $client->getFolder('INBOX');
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
            },300);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return 1;
        }
    }
}
