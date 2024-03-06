<?php

namespace App\Http\Controllers\Api\V1;

use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Exception;
use SimpleXMLElement;

class ApiAdminController extends DteController
{

    public function __construct()
    {
        $this->timestamp = Carbon::now('America/Santiago');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     * Agregar una empresa
     */
    public function agregarEmpresa(Request $request): JsonResponse
    {
        // Validar datos
        $validator = Validator::make($request->all(), [
            'rut' => 'required',
            'fecha_resolucion' => 'nullable',
            'razon_social' => 'nullable',
            'giro' => 'nullable',
            'acteco' => 'nullable',
            'direccion' => 'nullable',
            'comuna' => 'nullable',
            'ciudad' => 'nullable',
            'codigo_vendedor' => 'nullable',
            'correo' => 'nullable|email',
            'telefono' => 'nullable',
        ], [
            'rut.required' => 'El campo rut es obligatorio',
            'correo.email' => 'El campo correo debe ser un email válido',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'message' => "Error al agregar empresa",
                'error' => $validator->errors()->first(),
            ], 400);
        }

        // Verificar si existe empresa
        $empresa = DB::table('empresa')->where('rut', '=', $request->rut)->first();
        if ($empresa) {
            return response()->json([
                'message' => "Error al agregar empresa",
                'error' => "La empresa ya existe",
            ], 400);
        }

        // Estructurar datos según función guardarEmpresa los recibe
        $empresa = [
            'rut' => $request->rut,
            'FchResol' => $request->fecha_resolucion ?? null,
            'RznSoc' => $request->razon_social ?? null,
            'GiroEmis' => $request->giro ?? null,
            'Acteco' => $request->acteco ?? null,
            'DirOrigen' => $request->direccion ?? null,
            'CmnaOrigen' => $request->comuna ?? null,
            'CiudadOrigen' => $request->ciudad ?? null,
            'CodigoVendedor' => $request->codigo_vendedor ?? null,
            'CorreoEmisor' => $request->correo ?? null,
            'Telefono' => $request->telefono ?? null,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ];

        // empresa array a json
        $empresa = json_decode(json_encode($empresa));

        // Guardar datos en DB
        try {
            $id = $this->guardarEmpresa($empresa->rut, $empresa);
        } catch (Exception $e) {
            return response()->json([
                'message' => "Error al agregar empresa",
                'error' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'message' => "Empresa agregada correctamente",
            'id' => $id,
        ], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * Función para agregar un cliente con el id de la empresa
     */
    public function agregarCliente(Request $request): JsonResponse {
        // Leer string como json
        $body = json_decode(json_encode($request->json()->all()));

        // Verificar si existe empresa
        $empresa = DB::table('empresa')->where('id', '=', $body->empresa_id)->first();
        if (!$empresa) {
            return response()->json([
                'message' => "Error al agregar cliente",
                'error' => "La empresa de id $body->empresa_id no existe",
            ], 400);
        }

        // Verificar si existe el cliente en DB
        $cliente = DB::table('cliente')->where('empresa_id', '=', $body->empresa_id)->first();
        if ($cliente) {
            return response()->json([
                'message' => "Error al agregar cliente",
                'error' => "El cliente ya existe",
            ], 400);
        } else {
            try {
                $id_cliente = DB::table('cliente')->insertGetId([
                    'empresa_id' => $body->empresa_id,
                    'created_at' => $this->timestamp,
                    'updated_at' => $this->timestamp,
                ]);

                return response()->json([
                    'message' => "Cliente agregado correctamente",
                ], 200);

            } catch (Exception $e) {
                return response()->json([
                    'message' => "Error al agregar cliente",
                    'error' => $e->getMessage(),
                ], 400);
            }
        }
    }

    /**
     * @param Request $request
     * @param $ambiente
     * @param $id
     * @param string|null $forzar
     * @return JsonResponse
     * @throws \Exception
     * Función para subir caf relacionado al id de la empresa
     */
    public function subirCaf(Request $request, $ambiente, int $id, string $forzar = null): JsonResponse
    {
        // Leer string como xml
        $rbody = $request->getContent();
        $caf_xml = new simpleXMLElement($rbody);

        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

        // Si el ambiente es de certificación agregar un 0 al id
        $tipo_folio = $caf_xml->CAF->DA->TD[0];
        if(self::$ambiente == 0)
            $tipo_folio = -$tipo_folio;

        // Verificar si existe el id de la empresa en DB
        $empresa = DB::table('empresa')->where('id', '=', $id)->first();
        if (!$empresa)
            return response()->json([
                'message' => "Error al subir caf",
                'error' => "No se ha encontrado la empresa de id $id",
            ], 400);

        // Verificar la empresa es cliente
        $cliente = DB::table('cliente')->where('empresa_id', '=', $empresa->id)->first();
        if (!$cliente) {
            return response()->json([
                'message' => "Error al encontrar el cliente",
                'error' => "No existe cliente con el rut " . $empresa->rut,
            ], 400);
        }

        // Calcular fecha de vencimiento a 6 meses de la fecha de autorización
        $fecha_vencimiento = Carbon::parse($caf_xml->CAF->DA->FA[0], 'America/Santiago')->addMonths(6)->format('Y-m-d');

        // Nombre caf tipodte_timestamp.xml
        $filename = "F{$tipo_folio}_RNG{$caf_xml->CAF->DA->RNG->D[0]}-{$caf_xml->CAF->DA->RNG->H[0]}_FA{$caf_xml->CAF->DA->FA[0]}.xml";

        // Verificar si existe el caf en DB
        $caf_duplicado = DB::table('caf')
            ->where('empresa_id', '=', $empresa->id)
            ->where('xml_filename', '=', $filename)
            ->first();
        if ($caf_duplicado) {
            return response()->json([
                'message' => 'El caf ya existe en la base de datos',
                'error' => "Nombre XML: $caf_duplicado->xml_filename"
            ], 400);
        }

        // Verificar si caf está vencido
        if (Carbon::parse($fecha_vencimiento)->isPast()) {
            return response()->json([
                'message' => "Error al subir caf",
                'error' => "El caf está vencido",
            ], 400);
        }

        // Verificar que el rut del caf con el del cliente sean el mismo
        if ($caf_xml->CAF->DA->RE != $empresa->rut) {
            return response()->json([
                'message' => "Error al subir caf",
                'error' => "El rut del caf no coincide con el de la empresa",
            ], 400);
        }

        // Si existe '.forzar' al final del url se fuerza la subida del caf
        if (!$forzar)
            return $this->uploadCaf($caf_xml, $tipo_folio, $filename, $id, $fecha_vencimiento);
        else
            return $this->uploadCaf($caf_xml, $tipo_folio, $filename, $id, $fecha_vencimiento, true);
    }

    public function testCaLogin()
    {
        $header = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0',
            //'Cookie' => 's_cc=true',
            //'Connection' => 'keep-alive',
        ];

        $pfxPath = env('CERT_PATH');
        $password = env('CERT_PASS');

        // Obtener el nombre del archivo sin extensión
        $fileNameWithoutExtension = pathinfo($pfxPath, PATHINFO_FILENAME);

        // Construir las rutas para los archivos .key.pem y .crt.pem
        $crtPath = dirname($pfxPath) . DIRECTORY_SEPARATOR . $fileNameWithoutExtension . '.crt';
        $keyPath = dirname($pfxPath) . DIRECTORY_SEPARATOR . $fileNameWithoutExtension . '.key';

        // Convertir el archivo .pfx a .key y .crt si no existen
        file_exists($crtPath) ?: shell_exec("openssl pkcs12 -in $pfxPath -out $crtPath -clcerts -nokeys -password pass:$password");
        file_exists($keyPath) ?: shell_exec("openssl pkcs12 -in $pfxPath -out $keyPath -nocerts -nodes -password pass:$password");

        /*
        // Construir las rutas para los archivos .key.pem y .crt.pem
        $pfx_sha2 = dirname($pfxPath) . DIRECTORY_SEPARATOR . "SHA2" . $fileNameWithoutExtension . '.pfx';

        // Convertir el archivo .pfx a .key y .crt si no existen
        if (!file_exists($pfx_sha2)) {
            return response()->json([
                'error' => 'Error al encontrar certificado .pfx codificado con SHA2',
                'message' => "el archivo SHA2$fileNameWithoutExtension.pfx no existe",
            ], 400);
        }
        */

        $url = 'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?https://misiir.sii.cl/cgi_misii/siihome.cgi';
        $body = 'referencia=' . urlencode('https://misiir.sii.cl/cgi_misii/siihome.cgi');

        $client = new Client([
            'debug' => fopen('php://stderr', 'w'),
        ]);

        $response = $client->request('POST', $url, [
            //'headers' => $header,
            'form_params' => [
                'referencia' => urlencode('https://misiir.sii.cl/cgi_misii/siihome.cgi'),
            ],
            'curl' => [
                CURLOPT_SSLCERTTYPE => 'P12',
                CURLOPT_SSL_CIPHER_LIST => CURLOPT_TLS13_CIPHERS,
                CURLOPT_SSLCERT => $pfxPath,
                CURLOPT_SSLCERTPASSWD => $password,
            ],
            'allow_redirects' => true,
            //'cert' => [$pfxPath, $password],
        ]);

        echo $response->getBody()->getContents();

        /*
        try {
            $this->generarNuevoCaf('', '', '');
        } catch (GuzzleException $e) {
            return response()->json([
                'error' => 'Error en la consulta al obtener un nuevo caf del SII',
                'message' => $e->getMessage(),
            ], 400);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Error al pedir nuevo caf obtenido del SII',
                'message' => $e->getMessage(),
            ], 400);
        }
        */
    }
}
