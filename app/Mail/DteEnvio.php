<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Webklex\PHPIMAP\Message;

class DteEnvio extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(
        protected array $message,
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
            ->view('envio-email', ['name' => $this->message['from']])
            ->subject('EnvioDTE')
            ->attachData($this->file['data'], $this->file['filename'], [
                'mime' => 'text/xml',
            ]);
    }
}
