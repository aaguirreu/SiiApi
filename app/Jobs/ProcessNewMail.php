<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use PhpParser\Node\Expr\Print_;
use Webklex\IMAP\Commands\ImapIdleCommand;
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
        public Message $message,
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Obtener header
        //return $this->message->getHeader();

        // Obtener body
        //return $this->message->getTextBody();

        // Obtener adjuntos
        if ($this->message->hasAttachments()) {
            $attachmentsInfo = [];

            $attachments = $this->message->getAttachments();
            foreach ($attachments as $attachment) {
                // Obtener el contenido del adjunto
                $content = $attachment->getContent();

                // Convertir el contenido a UTF-8 (solo para mostrar por pantalla)
                $utf8Content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

                $attachmentInfo = [
                    'filename' => $attachment->getFilename(),
                    'content' => $utf8Content,
                ];

                $attachmentsInfo[] = $attachmentInfo;
            }
            // Devolver la información de los adjuntos
            return $attachmentsInfo;
        } else {
            Log::channel(env('LOG_CHANNEL'))->info("No hay adjuntos");
        }

    }
}
