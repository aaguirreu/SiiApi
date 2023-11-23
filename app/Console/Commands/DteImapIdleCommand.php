<?php
namespace App\Console\Commands;

use App\Jobs\ProcessNewMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\Util\Xml;
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
                        echo 'Es un DTE';
                        // Revisar si el DTE es válido y enviar respuesta al correo emisor
                        $this->respuetaDte($attachment);
                    } else if($tipoXml == 'RespuestaDTE') {
                        echo 'Es una respuesta';
                        // Revisar si la respuesta es válida

                    }
                }
            }
            // Devolver la información de los adjuntos
            //Log::channel(env('LOG_CHANNEL'))->info(json_decode(json_encode($attachmentsInfo)));
            //echo json_encode($attachmentsInfo);
        } else {
            Log::channel(env('LOG_CHANNEL'))->info("Correo entrante sin adjuntos");
        }
    }

    private function respuetaDte($attachment)
    {
        // EJEMPLO
        $RutReceptor_esperado = '76192083-9';
        $RutEmisor_esperado = '88888888-8';

        // Cargar EnvioDTE y extraer arreglo con datos de carátula y DTEs
        $EnvioDte = new \sasco\LibreDTE\Sii\EnvioDte();
        $EnvioDte->loadXML($attachment->getContent());
        $Caratula = $EnvioDte->getCaratula();
        $Documentos = $EnvioDte->getDocumentos();

        // caratula
        $caratula = [
            'RutResponde' => $RutReceptor_esperado,
            'RutRecibe' => $Caratula['RutEmisor'],
            'IdRespuesta' => 1,
            //'NmbContacto' => '',
            //'MailContacto' => '',
        ];

        // procesar cada DTE
        $RecepcionDTE = [];
        foreach ($Documentos as $DTE) {
            $estado = $DTE->getEstadoValidacion(['RUTEmisor'=>$RutEmisor_esperado, 'RUTRecep'=>$RutReceptor_esperado]);
            $RecepcionDTE[] = [
                'TipoDTE' => $DTE->getTipo(),
                'Folio' => $DTE->getFolio(),
                'FchEmis' => $DTE->getFechaEmision(),
                'RUTEmisor' => $DTE->getEmisor(),
                'RUTRecep' => $DTE->getReceptor(),
                'MntTotal' => $DTE->getMontoTotal(),
                'EstadoRecepDTE' => $estado,
                'RecepDTEGlosa' => \sasco\LibreDTE\Sii\RespuestaEnvio::$estados['documento'][$estado],
            ];
        }

        // armar respuesta de envío
        $estado = $EnvioDte->getEstadoValidacion(['RutReceptor'=>$RutReceptor_esperado]);
        $RespuestaEnvio = new \sasco\LibreDTE\Sii\RespuestaEnvio();
        $RespuestaEnvio->agregarRespuestaEnvio([
            'NmbEnvio' => $attachment->getName(),
            'CodEnvio' => 1,
            'EnvioDTEID' => $EnvioDte->getID(),
            'Digest' => $EnvioDte->getDigest(),
            'RutEmisor' => $EnvioDte->getEmisor(),
            'RutReceptor' => $EnvioDte->getReceptor(),
            'EstadoRecepEnv' => $estado,
            'RecepEnvGlosa' => \sasco\LibreDTE\Sii\RespuestaEnvio::$estados['envio'][$estado],
            'NroDTE' => count($RecepcionDTE),
            'RecepcionDTE' => $RecepcionDTE,
        ]);

        // asignar carátula y Firma
        $RespuestaEnvio->setCaratula($caratula);
        $RespuestaEnvio->setFirma(new \sasco\LibreDTE\FirmaElectronica($config['firma']));

        // generar XML
        $xml = $RespuestaEnvio->generar();

        // validar schema del XML que se generó
        if ($RespuestaEnvio->schemaValidate()) {
            // mostrar XML al usuario, deberá ser guardado y subido al SII en:
            // https://www4.sii.cl/pfeInternet
            echo $xml;
        }

        // si hubo errores mostrar
        foreach (\sasco\LibreDTE\Log::readAll() as $error)
            echo $error,"\n";
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
            $folder = $client->getFolder('INBOX');
        } catch (ConnectionFailedException $e) {
            Log::error($e->getMessage());
            return 1;
        } catch (FolderFetchingException $e) {
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
