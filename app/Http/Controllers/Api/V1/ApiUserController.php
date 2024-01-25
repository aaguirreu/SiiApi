<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class ApiUserController extends Controller
{
    /**
     * @return void
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
     * @return void
     * Obtiene datos de la empresa según id
     */
    public function obtenerEmpresa(int $id): JsonResponse
    {
        $data = DB::table('empresa')->where('id', $id)->first();

        return Response::json($data);
    }
}
