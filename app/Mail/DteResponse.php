<?php

namespace App\Mail;

use Facade\FlareClient\View;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use sasco\LibreDTE\XML;
use Webklex\PHPIMAP\Message;

class DteResponse extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(
        protected string $nombre_attachment,
        protected array $file
    ) {}

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this
            ->view('respuesta-email', ['nombre_attachment' => $this->nombre_attachment])
            ->subject("Respuesta DTE: $this->nombre_attachment")
            ->attachData($this->file['data'], $this->file['filename'], [
                'mime' => 'text/xml',
            ]);
    }
}
