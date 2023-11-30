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
        protected Message $message,
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
            ->view('respuesta-email', ['name' => $this->message->getFrom()[0]->personal])
            ->subject('Respuesta DTE')
            //->replyTo($this->message->getFrom()[0]->personal, $this->message->getFrom()[0]->personal)
            ->attachData($this->file['data'], $this->file['filename'], [
                'mime' => 'text/xml',
            ]);
    }
}
