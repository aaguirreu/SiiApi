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
        protected array $files
    ) {}

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        foreach ($this->files as $file) {
            $this->attachData($file['data'], $file['filename'], [
                'mime' => $file['mime'] ?? 'text/xml',
            ]);
        }
        if ($this->message['body'] != '')
            return $this
                ->html(base64_decode($this->message['body']))
                ->subject($this->message['subject']);

        return $this
            ->view('envio-email', ['emisor' => $this->message['emisor'], 'name' => $this->message['from']])
            ->subject($this->message['subject']);
    }
}
