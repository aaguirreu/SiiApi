<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\DteResponse;
use Egulias\EmailValidator\Exception\InvalidEmail;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Response;
use SimpleXMLElement;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\MessageCollection;
use function PHPUnit\Framework\isEmpty;

class ApiUserController extends Controller
{
    /**
     * Método que retorna los dte de la empresa según id
     */
    public function obtenerDtes(Request $request): JsonResponse
    {
        // Definir filtros
        $filtros = $request->only(['tipo_dte', 'folio', 'estado', 'entidad', 'fecha_desde', 'fecha_hasta']);

        // Obtener los datos de DTE, documentos y detalles con filtros
        $data = DB::table('empresa')
            ->where('empresa.id', $request->get('empresa_id'))
            ->join('caratula', 'empresa.id', '=', 'caratula.emisor_id')
            ->join('dte', 'caratula.id', '=', 'dte.caratula_id')
            ->join('documento', 'dte.id', '=', 'documento.dte_id')
            ->join('detalle', 'documento.id', '=', 'detalle.documento_id')
            ->join('caf', 'documento.caf_id', '=', 'caf.id')
            ->when(isset($filtros['tipo_dte']), function ($query) use ($filtros) {
                return $query->where('caf.tipo', $filtros['tipo_dte']);
            })
            ->when(isset($filtros['folio']), function ($query) use ($filtros) {
                return $query->where('documento.folio', $filtros['folio']);
            })
            ->when(isset($filtros['estado']), function ($query) use ($filtros) {
                return $query->where('dte.estado', $filtros['estado']);
            })
            ->when(isset($filtros['entidad']), function ($query) use ($filtros) {
                // Ajusta esta parte según cómo determines el filtro por entidad
                return $query->where('documento.receptor_id', $filtros['entidad']);
            })
            ->when(isset($filtros['fecha_desde']), function ($query) use ($filtros) {
                return $query->where('dte.created_at', '>=', $filtros['fecha_desde']);
            })
            ->when(isset($filtros['fecha_hasta']), function ($query) use ($filtros) {
                return $query->where('dte.created_at', '<=', $filtros['fecha_hasta']);
            })
            ->select(
                'dte.id as dte_id',
                'dte.estado as dte_estado',
                'documento.id as documento_id',
                'documento.folio as documento_folio',
                'caf.tipo as documento_tipo',
                'detalle.id as detalle_id',
                'detalle.nombre as detalle_nombre',
                'detalle.descripcion as detalle_descripcion',
                'detalle.cantidad as detalle_cantidad',
                'detalle.unidad_medida as detalle_unidad_medida',
                'detalle.precio as detalle_precio',
                'detalle.monto as detalle_monto'
            )
            ->get();

        // Convertir los datos a formato JSON
        // Retornar la respuesta
        return Response::json($data);
    }


    /**
     * Importa los DTEs desde el correo
     */
    public function obtenerDtesCorreo(Request $request): JsonResponse
    {
        $body = $request->only(['mail', 'password', 'host', 'port', 'protocol', 'folder']);
        $cm = new ClientManager(base_path().'/config/imap.php');
        $client = $cm->make([
            'host'          => $body['host'],
            'port'          => $body['port'],
            'encryption'    => $body['encryption'] ?? 'ssl',
            'validate_cert' => $body['validate_cert'] ?? true,
            'username'      => $body['mail'],
            'password'      => $body['password'],
            'protocol'      => $body['protocol'] ?? 'imap'
        ]);

        try {
            $client->connect();
        } catch (Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }

        // Obtener todos los correos no leídos en el folder INBOX
        /* @var Folder $folder */
        $folder = $client->getFolder($body['folder'] ?? 'INBOX');
        $unseenMessages = $folder->query()->unseen()->get();

        // Procesar cada mensaje no leído
        $correos = [];
        /* @var Message $message */
        foreach ($unseenMessages as $message) {
            if ($message->hasAttachments()) {
                // Verificar si adjunto es un DTE
                $attachments = $this->procesarAttachments($message);

                // Quitar firmas a adjuntos
                $attachments = $this->quitarFirmas($attachments);

                $correos[] = [
                    "uid" => $message->uid,
                    "from" => $message->from[0]->mail,
                    "subject" => $message->subject->get(),
                    "date" => $message->date->get(),
                    "attachments" => $attachments,
                ];
            }
        }

        $client->disconnect();
        $cm->disconnect();

        return response()->json(json_decode(json_encode($correos, true)), 200);
    }

    /**
     * @param int $id
     * @return JsonResponse Obtiene datos de la empresa según id
     * Obtiene datos de la empresa según id
     */
    public function obtenerEmpresa(int $id): JsonResponse
    {
        $data = DB::table('empresa')->where('id', $id)->first();

        return Response::json($data);
    }

    public function importarDte(Request $request)//: JsonResponse
    {
        $body = $request->only(['mail', 'password', 'host', 'port', 'protocol', 'folder', 'correos']);

        $cm = new ClientManager(base_path().'/config/imap.php');
        $client = $cm->make([
            'host'          => $body['host'],
            'port'          => $body['port'],
            'encryption'    => $body['encryption'] ?? 'ssl',
            'validate_cert' => $body['validate_cert'] ?? true,
            'username'      => $body['mail'],
            'password'      => $body['password'],
            'protocol'      => $body['protocol'] ?? 'imap'
        ]);

        try {
            $client->connect();
        } catch (Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }

        $correos = [];
        foreach ($body['correos'] as $correo) {
            try {
                $folder = $client->getFolder($body['folder'] ?? 'INBOX');
                $query = $folder->messages();
                $message = $query->getMessage($correo['uid']);
            } catch (Exception $e) {
                $correos[] = [
                    'uid' => $correo['uid'],
                    'error' => $e->getMessage()
                ];
            }
            if(!empty($message->getFlags()->toArray())) {
                $correos[] = [
                    'uid' => $correo['uid'],
                    'message' => 'Este correo ya ha sido leído'
                ];
            }

            if(empty($message->getFlags()->toArray()))
                if ($message->hasAttachments()) {
                    // Verificar si adjunto es un DTE
                    $attachments = $this->procesarAttachments($message);

                    /* @var  Attachment $Attachment*/
                    foreach ($attachments as $Attachment) {
                        try {
                            // Revisar si el DTE es válido y enviar respuesta al correo emisor
                            $rpta = new FacturaController([33, 34, 56, 61]);

                            // Obtener respuesta del Dte
                            $respuesta = $rpta->respuestaEnvio($Attachment);
                            if (isset($respuesta['error'])) {
                                $correos[] = [
                                    'uid' => $correo['uid'],
                                    'error' => $respuesta['error']
                                ];
                                break;
                            }

                            // Enviar respuesta por correo
                            //Mail::to($message->from[0]->mail)->send(new DteResponse($Attachment->getName(), $respuesta));

                            $correos[] = [
                                'uid' => $correo['uid'],
                                'message' => 'DTE importado correctamente'
                            ];

                        } catch (Exception $e) {
                            $correos[] = [
                                'uid' => $correo['uid'],
                                'error' => $e->getMessage()
                            ];
                        }
                    }
                }
            $message->setFlag('Seen');
        }

        $client->disconnect();
        $cm->disconnect();

        return response()->json($correos, 200);
    }

    protected function procesarAttachments($message): array
    {
        $attachments_arr = [];
        /* @var  MessageCollection $Attachments*/
        $Attachments = $message->getAttachments();
        if (!$Attachments->isEmpty()) {
            /**
             * Obtener el contenido del adjunto
             *
             * @var Attachment $Attachment
             * @var string $content
             */
            foreach ($Attachments as $Attachment) {
                // Verificar si el adjunto es un xml
                if(str_ends_with($Attachment->getName(), '.xml')) {
                    // Ver si el xmles un dte o una respuesta a un dte
                    $Xml = new SimpleXMLElement($Attachment->getContent());
                    $tipoXml = $Xml[0]->getName();
                    if($tipoXml == 'EnvioDTE') {
                        $attachments_arr[] = $Attachment;
                    }
                }
            }
        }
        return $attachments_arr;
    }

    protected function quitarFirmas($attachments): array
    {
        $attachments_arr = [];
        /**
         * Obtener el contenido del adjunto
         *
         * @var Attachment $Attachment
         * @var string $content
         */
        foreach ($attachments as $Attachment) {
            $Xml = new SimpleXMLElement($Attachment->getContent());

            // Eliminar las firmas del XML si existen
            if (isset($Xml->Signature)) {
                unset($Xml->Signature);
            }

            if (isset($Xml->SetDTE->Signature)) {
                unset($Xml->SetDTE->Signature);
            }

            foreach ($Xml->SetDTE->DTE as $Dte) {
                if (isset($Dte->Signature)) {
                    unset($Dte->Signature);
                }
                foreach ($Dte->Documento as $Documento) {
                    if (isset($Documento->TED)) {
                        unset($Documento->TED);
                    }
                }
            }

            $attachments_arr[] = [
                "filename" => $Attachment->getName(),
                "content" =>  json_decode(json_encode($Xml), true),
            ];
        }
        return $attachments_arr;
    }

    /** borrar
    protected function procesarMensaje($message)
    {
        $attachments_arr = [];
        $Attachments = $message->getAttachments();
        if (!$Attachments->isEmpty()) {
            foreach ($Attachments as $Attachment) {
                // Verificar si el adjunto es un xml
                if(str_ends_with($Attachment->getName(), '.xml')) {
                    // Ver si el xmles un dte o una respuesta a un dte
                    $Xml = new SimpleXMLElement($Attachment->getContent());
                    $tipoXml = $Xml[0]->getName();
                    if($tipoXml == 'EnvioDTE') {
                        // Eliminar las firmas del XML si existen
                        if (isset($Xml->Signature)) {
                            unset($Xml->Signature);
                        }

                        if (isset($Xml->SetDTE->Signature)) {
                            unset($Xml->SetDTE->Signature);
                        }

                        foreach ($Xml->SetDTE->DTE as $Dte) {
                            if (isset($Dte->Signature)) {
                                unset($Dte->Signature);
                            }
                            foreach ($Dte->Documento as $Documento) {
                                if (isset($Documento->TED)) {
                                    unset($Documento->TED);
                                }
                            }
                        }

                        $attachments_arr[] = [
                            "filename" => $Attachment->getName(),
                            "content" =>  json_decode(json_encode($Xml), true),
                        ];
                    }
                }
            }
        }
        return [
            "uid" => $message->uid,
            "from" => $message->from[0]->mail,
            "subject" => $message->subject->get(),
            "date" => $message->date->get(),
            "attachments" => $attachments_arr,
        ];
    }*/
}

