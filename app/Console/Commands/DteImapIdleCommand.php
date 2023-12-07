<?php
namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\ApiFacturaController;
use App\Http\Controllers\Api\V1\FacturaController;
use App\Jobs\ProcessNewMail;
use App\Mail\DteResponse;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Webklex\IMAP\Commands\ImapIdleCommand;
use Webklex\IMAP\Facades\Client as ClientFacade;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class DteImapIdleCommand extends ImapIdleCommand {

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dteimap:idle';

    /**
     * Callback used for the idle command and triggered for every new received message
     * reemplaza el método onNewMessage de la clase padre
     * función que llama a los workers
     * @param Message $message
     */
    public function onNewMessage(Message $message): void
    {
        $this->info("Command: New message received: ".$message->subject);
        // Debería funcionar mejor con redis que con database
        $this->workerJobManageMessage($message);
        //ProcessNewMail::dispatch($message->uid);
        /** Se marca como leído para evitar re-leerlo
         * Idealmente esto debería realizarse cuando el worker termine, pero no se le puede enviar el mensaje.
         * Una opción es que el worker obtenga nuevamente el mensaje a través del id.
         * ya que, al enviar el mensaje como parámetro éste deja de ser el mensaje original.
         */
        $message->setFlag('Seen');
    }

    /**
     * Simula el trabajo de un worker
     * Este worker verifíca si el correo eso un dte o una respuesta a un dte (intercambio de información del Sii)
     * @param Message $message
     */
    public function workerJobManageMessage($message) {
        // Obtener header
        //return $this->message->getHeader();

        // Obtener body
        //return $this->message->getTextBody();

        // Obtener adjuntos
        if ($message->hasAttachments()) {
            $attachmentsInfo = [];

            /* @var  \Webklex\PHPIMAP\Support\MessageCollection $attachments*/
            $attachments = $message->getAttachments();
            if ($attachments->isEmpty()) {
                Log::channel(env('LOG_CHANNEL'))->info("Correo entrante sin adjuntos");
            } else {
                foreach ($attachments as $attachment) {
                    /**
                     * Obtener el contenido del adjunto
                     *
                     * @var \Webklex\PHPIMAP\Attachment $attachment
                     * @var string $content
                     */

                    $attachmentInfo = [
                        'filename' => $attachment->getName(),
                        // Convertir el contenido a UTF-8 (solo para mostrar por pantalla)
                        'content' => mb_convert_encoding($attachment->getContent(), 'UTF-8', 'ISO-8859-1'),
                    ];

                    // Verificar si el adjunto es un xml
                    if(str_ends_with($attachment->getName(), '.xml')) {
                        //Storage::disk('dtes')->put('Dte\\'.$attachment->getName(), $content);
                        $attachmentsInfo[] = $attachmentInfo;
                        //echo json_encode($attachmentsInfo);
                        // Ver si el xmles un dte o una respuesta a un dte
                        $xml = new \SimpleXMLElement($attachment->getContent());
                        $tipoXml = $xml[0]->getName();
                        if($tipoXml == 'EnvioDTE') {
                            try {
                                echo "Correo entrante: EnvioDTE\n";
                                Log::channel(env('LOG_CHANNEL'))->info("Correo entrante: EnvioDTE");
                                // Revisar si el DTE es válido y enviar respuesta al correo emisor
                                $rpta = new FacturaController([33, 34, 56, 61]);

                                // Obtener respuesta del Dte
                                $fileRpta = $rpta->respuestaEnvio($attachment);

                                // Enviar respuesta por correo
                                Mail::to($message->from[0]->mail)->send(new DteResponse($message, $fileRpta));

                                echo "Correo saliente: Respuesta enviada\n";
                                Log::channel(env('LOG_CHANNEL'))->info("Correo saliente: Respuesta enviada");

                            } catch (\Exception $e) {
                                Log::channel(env('LOG_CHANNEL'))->info($e);
                            }

                        } else if($tipoXml == 'RespuestaDTE') {
                            try {
                                // Revisar si la respuesta es válida
                                echo "Correo entrante: RespuestaDTE\n";
                                Log::channel(env('LOG_CHANNEL'))->info("Correo entrante: RespuestaDTE");

                                // Buscar en la base de datos el nombre del archivo.
                                $filename = $xml[0]->Resultado->RecepcionEnvio->NmbEnvio;
                                $estado = $xml[0]->Resultado->RecepcionEnvio->RecepEnvGlosa;
                                DB::table('dte')
                                    ->where('filename', '=', $filename)
                                    ->update(['estado' => $estado]);

                            } catch (\Exception $e) {
                                Log::channel(env('LOG_CHANNEL'))->info($e);
                            }
                        }
                    }
                }
                // Devolver la información de los adjuntos
                //Log::channel(env('LOG_CHANNEL'))->info(json_decode(json_encode($attachmentsInfo)));
                //echo json_encode($attachmentsInfo);
            }
        } else {
            Log::channel(env('LOG_CHANNEL'))->info("Correo entrante sin adjuntos");
        }
    }

    /**
     * Execute the command.
     * Este método reemplaza al método handle de la clase padre
     * Solo lee casilla INBOX
     * Evita que se re-lean correos
     * @return void
     */
    public function handle() {
        if (is_array($this->account)) {
            $client = ClientFacade::make($this->account);
        } else {
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
            $folder = $client->getFolder('INBOX');
        } catch (ConnectionFailedException $e) {
            Log::error($e->getMessage());
            return 1;
        } catch (FolderFetchingException $e) {
            Log::error($e->getMessage());
            return 1;
        }

        try {
            // Obtener todos los correos no leídos en el folder INBOX
            $unseenMessages = $folder->query()->unseen()->get();

            // Procesar cada mensaje no leído
            foreach ($unseenMessages as $message) {
                $this->onNewMessage($message);
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return 1;
        }


        try {
            $folder->idle(function($message){
                /**
                 * Se agrega esta linea para evitar re-leer correos al moverlos al folder
                 * Los correos nuevos no traen flags
                 */
                if(empty($message->getFlags()->toArray()))
                    $this->onNewMessage($message);
            });
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return 1;
        }

        return 0;
    }
}
