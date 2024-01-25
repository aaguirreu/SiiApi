<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpParser\Node\Expr\Print_;
use Webklex\IMAP\Commands\ImapIdleCommand;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Message;

class ProcessNewMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        private int $uid,
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        /*
         * Obtener mensaje con uid
         * @var \Webklex\PHPIMAP\Client $client
         */
        $client = Client::account('default');
        $folder = $client->getFolder('INBOX');
        $query = $folder->query();
        $message = $query->getMessageByUid($uid = $this->uid);
        if ($this->$message->hasAttachments()) {
            $attachmentsInfo = [];

            /* @var  \Webklex\PHPIMAP\Support\MessageCollection $attachments*/
            $attachments = $this->$message->getAttachments();
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

                //if(str_ends_with($attachment->getName(), '.xml'))
                // Storage::disk('xml')->put('\\file.xml', "content");
                $attachmentsInfo[] = $attachmentInfo;
            }
            // Devolver la información de los adjuntos
            //Log::channel('default')->info(json_decode(json_encode($attachmentsInfo)));
            echo json_encode($attachmentsInfo);
        } else {
            Log::channel('default')->info("No hay adjuntos");
        }
    }
}
