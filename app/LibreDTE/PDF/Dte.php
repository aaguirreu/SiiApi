<?php

namespace App\LibreDTE\PDF;

class Dte extends \sasco\LibreDTE\Sii\Dte\PDF\Dte
{
    public function __construct($papelContinuo = false)
    {
        parent::__construct();
        $this->SetTitle('Documento Tributario Electrónico (DTE) de Chile');
        $this->SetAuthor('ArBo-DTE');
        $this->SetCreator('Logiciel - https://logiciel.cl');
        $this->papelContinuo = $papelContinuo === true ? 80 : $papelContinuo;
    }

    protected $detalle_cols = [
        'CdgItem' => ['title'=>'Código', 'align'=>'left', 'width'=>20],
        'NmbItem' => ['title'=>'Item', 'align'=>'left', 'width'=>0],
        'IndExe' => ['title'=>'IE', 'align'=>'left', 'width'=>'7'],
        'QtyItem' => ['title'=>'Cant.', 'align'=>'right', 'width'=>15],
        'UnmdItem' => ['title'=>'Unidad', 'align'=>'left', 'width'=>22],
        'QtyRef' => ['title'=>'Cant. Ref.', 'align'=>'right', 'width'=>22],
        'PrcItem' => ['title'=>'Unitario', 'align'=>'right', 'width'=>22],
        'DescuentoMonto' => ['title'=>'Descuento', 'align'=>'right', 'width'=>22],
        'RecargoMonto' => ['title'=>'Recargo', 'align'=>'right', 'width'=>22],
        'MontoItem' => ['title'=>'Total', 'align'=>'right', 'width'=>22],
    ]; ///< Nombres de columnas detalle, alineación y ancho

    public function agregar(array $dte, $timbre = null)
    {
        $this->dte = $dte['Encabezado']['IdDoc']['TipoDTE'];
        $papel_tipo = (int)$this->papelContinuo;
        $method = 'agregar_papel_'.$papel_tipo;
        if (!method_exists($this, $method)) {
            $tipo = !empty(self::$papel[$papel_tipo]) ? self::$papel[$papel_tipo] : $papel_tipo;
            throw new \Exception('Papel de tipo "'.$tipo.'" no está disponible');
        }
        $this->$method($dte, $timbre);
    }

    public function setFooterText($footer = true)
    {
        if ($footer) {
            // asignar valor por defecto
            if ($footer===true) {
                $footer = [
                    'left' => 'ArBo-DTE',
                    'right' => 'www.logiciel.cl',
                ];
            }
            // si no es arreglo se convierte en uno
            if (!is_array($footer))
                $footer = ['left'=>$footer];
            // asignar footer
            $this->footer = array_merge(['left'=>'', 'right'=>''], $footer);
        } else {
            $this->footer = null;
        }
    }

    private function agregar_papel_0(array $dte, $timbre)
    {
        // agregar página para la factura
        $this->AddPage();
        // agregar cabecera del documento
        $y[] = $this->agregarEmisor($dte['Encabezado']['Emisor']);
        $y[] = $this->agregarFolio(
            $dte['Encabezado']['Emisor']['RUTEmisor'],
            $dte['Encabezado']['IdDoc']['TipoDTE'],
            $dte['Encabezado']['IdDoc']['Folio'],
            !empty($dte['Encabezado']['Emisor']['CmnaOrigen']) ? $dte['Encabezado']['Emisor']['CmnaOrigen'] : null
        );
        $this->setY(max($y));
        $this->Ln();
        // datos del documento
        $y = [];
        $y[] = $this->agregarDatosEmision($dte['Encabezado']['IdDoc'], !empty($dte['Encabezado']['Emisor']['CdgVendedor'])?$dte['Encabezado']['Emisor']['CdgVendedor']:null);
        $y[] = $this->agregarReceptor($dte['Encabezado']);
        $this->setY(max($y));
        $this->agregarTraslado(
            !empty($dte['Encabezado']['IdDoc']['IndTraslado']) ? $dte['Encabezado']['IdDoc']['IndTraslado'] : null,
            !empty($dte['Encabezado']['Transporte']) ? $dte['Encabezado']['Transporte'] : null
        );
        if (!empty($dte['Referencia'])) {
            $this->agregarReferencia($dte['Referencia']);
        }
        $this->agregarDetalle($dte['Detalle']);
        if (!empty($dte['DscRcgGlobal'])) {
            $this->agregarSubTotal($dte['Detalle']);
            $this->agregarDescuentosRecargos($dte['DscRcgGlobal']);
        }
        if (!empty($dte['Encabezado']['IdDoc']['MntPagos'])) {
            $this->agregarPagos($dte['Encabezado']['IdDoc']['MntPagos']);
        }
        // agregar observaciones
        $this->x_fin_datos = $this->getY();
        $this->agregarObservacion($dte['Encabezado']['IdDoc']);
        // Observaciones adicionales sobre el timbre
        if(isset($dte['Observaciones']))
            $this->agregarObservacionAdicional($dte['Observaciones']);
        if (!$this->timbre_pie) {
            $this->Ln();
        }
        $this->x_fin_datos = $this->getY();
        $this->agregarTotales($dte['Encabezado']['Totales'], !empty($dte['Encabezado']['OtraMoneda']) ? $dte['Encabezado']['OtraMoneda'] : null);
        // agregar timbre
        $this->agregarTimbre($timbre);
        // agregar acuse de recibo y leyenda cedible
        if ($this->cedible and !in_array($dte['Encabezado']['IdDoc']['TipoDTE'], $this->sinAcuseRecibo)) {
            $this->agregarAcuseRecibo();
            $this->agregarLeyendaDestino($dte['Encabezado']['IdDoc']['TipoDTE']);
        }
    }

    /**
     * Método que agrega una página con el documento tributario
     * @param \sasco\LibreDTE\Sii\Dte\PDF\Dte Arreglo con los datos del XML (tag Documento)
     * @param timbre String XML con el tag TED del DTE
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2020-10-12
     */
    private function agregar_papel_57(array $dte, $timbre, $height = 0)
    {
        $width = 57;
        // determinar alto de la página y agregarla
        $this->AddPage('P', [$height ? $height : $this->papel_continuo_alto, $width]);
        $x = 1;
        $y = 5;
        $this->SetXY($x,$y);
        // agregar datos del documento
        $this->setFont('', '', 8);
        $this->MultiTexto(!empty($dte['Encabezado']['Emisor']['RznSoc']) ? $dte['Encabezado']['Emisor']['RznSoc'] : $dte['Encabezado']['Emisor']['RznSocEmisor'], $x, null, '', $width-2);
        $this->MultiTexto($dte['Encabezado']['Emisor']['RUTEmisor'], $x, null, '', $width-2);
        $this->MultiTexto('Giro: '.(!empty($dte['Encabezado']['Emisor']['GiroEmis']) ? $dte['Encabezado']['Emisor']['GiroEmis'] : $dte['Encabezado']['Emisor']['GiroEmisor']), $x, null, '', $width-2);
        $direccion = !empty($dte['Encabezado']['Emisor']['DirOrigen']) ? $dte['Encabezado']['Emisor']['DirOrigen'] : null;
        $comuna = !empty($dte['Encabezado']['Emisor']['CmnaOrigen']) ? $dte['Encabezado']['Emisor']['CmnaOrigen'] : null;
        $ciudad = !empty($dte['Encabezado']['Emisor']['CiudadOrigen']) ? $dte['Encabezado']['Emisor']['CiudadOrigen'] : \sasco\LibreDTE\Chile::getCiudad($comuna);
        if (!empty($this->casa_matriz)) {
            $this->MultiTexto("Casa matriz: ".$direccion.($comuna?(', '.$comuna):'').($ciudad?(', '.$ciudad):''), $x, null, '', $width-2);
            //$this->MultiTexto('Casa matriz: '.$this->casa_matriz, $x, null, '', $width-2);
        } else
            $this->MultiTexto("Casa matriz: ".$direccion.($comuna?(', '.$comuna):'').($ciudad?(', '.$ciudad):''), $x, null, '', $width-2);
        if (!empty($dte['Encabezado']['Emisor']['Sucursal'])) {
            $this->MultiTexto('Sucursal: '.$dte['Encabezado']['Emisor']['Sucursal'], $x, null, '', $width-2);
        }
        $this->MultiTexto($this->getTipo($dte['Encabezado']['IdDoc']['TipoDTE'], $dte['Encabezado']['IdDoc']['Folio']).' N° '.$dte['Encabezado']['IdDoc']['Folio'], $x, null, '', $width-2);
        $this->MultiTexto('Fecha: '.date('d/m/Y', strtotime($dte['Encabezado']['IdDoc']['FchEmis'])), $x, null, '', $width-2);
        // si no es boleta no nominativa se agregan datos receptor
        if ($dte['Encabezado']['Receptor']['RUTRecep']!='66666666-6') {
            $this->Ln();
            $this->MultiTexto('Receptor: '.$dte['Encabezado']['Receptor']['RUTRecep'], $x, null, '', $width-2);
            $this->MultiTexto($dte['Encabezado']['Receptor']['RznSocRecep'], $x, null, '', $width-2);
            if (!empty($dte['Encabezado']['Receptor']['GiroRecep'])) {
                $this->MultiTexto('Giro: '.$dte['Encabezado']['Receptor']['GiroRecep'], $x, null, '', $width-2);
            }
            if (!empty($dte['Encabezado']['Receptor']['DirRecep'])) {
                $this->MultiTexto($dte['Encabezado']['Receptor']['DirRecep'].', '.$dte['Encabezado']['Receptor']['CmnaRecep'], $x, null, '', $width-2);
            }
        }
        $this->Ln();
        // hay un sólo detalle
        if (!isset($dte['Detalle'][0])) {
            $this->MultiTexto($dte['Detalle']['NmbItem'].': $'.$this->num($dte['Detalle']['MontoItem']), $x, null, '', $width-2);
        }
        // hay más de un detalle
        else {
            foreach ($dte['Detalle'] as $d) {
                $this->MultiTexto($d['NmbItem'].': $'.$this->num($d['MontoItem']), $x, null, '', $width-2);
            }
            if (in_array($dte['Encabezado']['IdDoc']['TipoDTE'], [39, 41])) {
                $this->MultiTexto('TOTAL: $'.$this->num($dte['Encabezado']['Totales']['MntTotal']), $x, null, '', $width-2);
            }
        }
        // colocar EXENTO, NETO, IVA y TOTAL si corresponde
        if (!empty($dte['Encabezado']['Totales']['MntExe'])) {
            $this->MultiTexto('EXENTO: $'.$this->num($dte['Encabezado']['Totales']['MntExe']), $x, null, '', $width-2);
        }
        if (!empty($dte['Encabezado']['Totales']['MntNeto'])) {
            $this->MultiTexto('NETO: $'.$this->num($dte['Encabezado']['Totales']['MntNeto']), $x, null, '', $width-2);
        }
        if (!empty($dte['Encabezado']['Totales']['IVA'])) {
            $this->MultiTexto('IVA: $'.$this->num($dte['Encabezado']['Totales']['IVA']), $x, null, '', $width-2);
        }
        $this->MultiTexto('TOTAL: $'.$this->num($dte['Encabezado']['Totales']['MntTotal']), $x, null, '', $width-2);
        // agregar acuse de recibo y leyenda cedible
        if ($this->cedible and !in_array($dte['Encabezado']['IdDoc']['TipoDTE'], $this->sinAcuseRecibo)) {
            $this->agregarAcuseReciboContinuo(-1, $this->y+6, $width+2, 34);
            $this->agregarLeyendaDestinoContinuo($dte['Encabezado']['IdDoc']['TipoDTE']);
        }
        // agregar timbre
        if (!empty($dte['Encabezado']['IdDoc']['TermPagoGlosa'])) {
            $this->Ln();
            $this->MultiTexto('Observación: '.$dte['Encabezado']['IdDoc']['TermPagoGlosa']."\n\n", $x);
        }
        $this->agregarTimbre($timbre, -11, $x, $this->GetY()+6, 55, 6);
        // si el alto no se pasó, entonces es con autocálculo, se elimina esta página y se pasa el alto
        // que se logró determinar para crear la página con el alto correcto
        if (!$height) {
            $this->deletePage($this->PageNo());
            $this->agregar_papel_57($dte, $timbre, $this->getY()+30);
        }
    }

    private function agregar_papel_70(array $dte, $timbre, $width = 70, $height = 0)
    {
        // si hay logo asignado se usa centrado
        if (!empty($this->logo)) {
            $this->logo['posicion'] = 'C';
        }
        // determinar alto de la página y agregarla
        $x_start = 1;
        $y_start = 1;
        $offset = 16;
        $w_recuadro = $width-($x_start*4);
        // determinar alto de la página y agregarla
        $this->AddPage('P', [$height ? $height : $this->papel_continuo_alto, $width]);
        // agregar cabecera del documento
        $y = $this->agregarFolio(
            $dte['Encabezado']['Emisor']['RUTEmisor'],
            $dte['Encabezado']['IdDoc']['TipoDTE'],
            $dte['Encabezado']['IdDoc']['Folio'],
            isset($dte['Encabezado']['Emisor']['CmnaOrigen']) ? $dte['Encabezado']['Emisor']['CmnaOrigen'] : '', // siempre debería tener comuna
            ($width-$w_recuadro)/2, $y_start, $w_recuadro, 10,
            [0,0,0]
        );
        $y = $this->agregarEmisor($dte['Encabezado']['Emisor'], $x_start, $y+2, $width-($x_start*45), 45, 9, [0,0,0]);
        // datos del documento
        $this->SetY($y);
        $this->Ln();
        $this->setFont('', '', 8);
        $this->agregarDatosEmision($dte['Encabezado']['IdDoc'], !empty($dte['Encabezado']['Emisor']['CdgVendedor'])?$dte['Encabezado']['Emisor']['CdgVendedor']:null, $x_start, $offset, false);
        $this->agregarReceptor_70($dte['Encabezado'], $x_start, $offset);
        $this->agregarTraslado(
            !empty($dte['Encabezado']['IdDoc']['IndTraslado']) ? $dte['Encabezado']['IdDoc']['IndTraslado'] : null,
            !empty($dte['Encabezado']['Transporte']) ? $dte['Encabezado']['Transporte'] : null,
            $x_start, $offset
        );
        if (!empty($dte['Referencia'])) {
            $this->agregarReferencia($dte['Referencia'], $x_start, $offset);
        }
        $this->Ln();
        $this->agregarDetalleContinuo($dte['Detalle'], 2, [1, 15, 35, 43]);
        if (!empty($dte['DscRcgGlobal'])) {
            $this->Ln();
            $this->Ln();
            $this->agregarSubTotal($dte['Detalle'], 21, 17);
            $this->agregarDescuentosRecargos($dte['DscRcgGlobal'], 21, 17);
        }
        if (!empty($dte['Encabezado']['IdDoc']['MntPagos'])) {
            $this->Ln();
            $this->Ln();
            $this->agregarPagos($dte['Encabezado']['IdDoc']['MntPagos'], $x_start);
        }
        $OtraMoneda = !empty($dte['Encabezado']['OtraMoneda']) ? $dte['Encabezado']['OtraMoneda'] : null;
        $this->agregarTotales($dte['Encabezado']['Totales'], $OtraMoneda, $this->y+6, 21, 17);
        // agregar acuse de recibo y leyenda cedible
        if ($this->cedible and !in_array($dte['Encabezado']['IdDoc']['TipoDTE'], $this->sinAcuseRecibo)) {
            $this->agregarAcuseReciboContinuo_70(3, $this->y+6, $width-6, 34);
            $this->agregarLeyendaDestinoContinuo($dte['Encabezado']['IdDoc']['TipoDTE']);
        }
        // agregar timbre
        $y = $this->agregarObservacion($dte['Encabezado']['IdDoc'], $x_start, $this->y+6);
        // Observaciones adicionales sobre el timbre
        if(isset($dte['Observaciones'])) {
            if ($this->cedible)
                $this->y += 2;
            $y = $this->agregarObservacionAdicional($dte['Observaciones'], $x_start, $this->y);
        }
        $this->agregarTimbreContinuo($timbre, -10, $x_start, $y+2, 70, 6);
        // si el alto no se pasó, entonces es con autocálculo, se elimina esta página y se pasa el alto
        // que se logró determinar para crear la página con el alto correcto
        if (!$height) {
            $this->deletePage($this->PageNo());
            $this->agregar_papel_70($dte, $timbre, $width, $this->getY()+30);
        }
    }

    /**
     * Método que agrega una página con el documento tributario en papel
     * contínuo de 75mm
     * @param dte Arreglo con los datos del XML (tag Documento)
     * @param timbre String XML con el tag TED del DTE
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2019-10-06
     */
    private function agregar_papel_75(array $dte, $timbre)
    {
        $this->agregar_papel_80($dte, $timbre, 75);
    }

    private function agregar_papel_77(array $dte, $timbre)
    {
        $this->agregar_papel_80($dte, $timbre, 76.5);
    }

    /**
     * Método que agrega observaciones abajo de los totales
     * Es identico al original, pero se le agrega una seccion de observaciones
     */
    private function agregar_papel_80(array $dte, $timbre, $width = 80, $height = 0)
    {
        // si hay logo asignado se usa centrado
        if (!empty($this->logo)) {
            $this->logo['posicion'] = 'C';
        }
        // determinar alto de la página y agregarla
        $x_start = 1;
        $y_start = 1;
        $offset = 16;
        $w_recuadro = $width-($x_start*4);
        // determinar alto de la página y agregarla
        $this->AddPage('P', [$height ? $height : $this->papel_continuo_alto, $width]);
        // agregar cabecera del documento
        $y = $this->agregarFolio(
            $dte['Encabezado']['Emisor']['RUTEmisor'],
            $dte['Encabezado']['IdDoc']['TipoDTE'],
            $dte['Encabezado']['IdDoc']['Folio'],
            isset($dte['Encabezado']['Emisor']['CmnaOrigen']) ? $dte['Encabezado']['Emisor']['CmnaOrigen'] : '', // siempre debería tener comuna
            ($width-$w_recuadro)/2, $y_start, $w_recuadro, 10,
            [0,0,0]
        );
        $y = $this->agregarEmisor($dte['Encabezado']['Emisor'], $x_start, $y+2, $width-($x_start*45), 45, 9, [0,0,0]);
        // datos del documento
        $this->SetY($y);
        $this->Ln();
        $this->setFont('', '', 8);
        $this->agregarDatosEmision($dte['Encabezado']['IdDoc'], !empty($dte['Encabezado']['Emisor']['CdgVendedor'])?$dte['Encabezado']['Emisor']['CdgVendedor']:null, $x_start, $offset, false);
        $this->agregarReceptor($dte['Encabezado'], $x_start, $offset);
        $this->agregarTraslado(
            !empty($dte['Encabezado']['IdDoc']['IndTraslado']) ? $dte['Encabezado']['IdDoc']['IndTraslado'] : null,
            !empty($dte['Encabezado']['Transporte']) ? $dte['Encabezado']['Transporte'] : null,
            $x_start, $offset
        );
        if (!empty($dte['Referencia'])) {
            $this->agregarReferencia($dte['Referencia'], $x_start, $offset);
        }
        $this->Ln();
        $this->agregarDetalleContinuo($dte['Detalle']);
        if (!empty($dte['DscRcgGlobal'])) {
            $this->Ln();
            $this->Ln();
            $this->agregarSubTotal($dte['Detalle'], 23, 17);
            $this->agregarDescuentosRecargos($dte['DscRcgGlobal'], 23, 17);
        }
        if (!empty($dte['Encabezado']['IdDoc']['MntPagos'])) {
            $this->Ln();
            $this->Ln();
            $this->agregarPagos($dte['Encabezado']['IdDoc']['MntPagos'], $x_start);
        }
        $OtraMoneda = !empty($dte['Encabezado']['OtraMoneda']) ? $dte['Encabezado']['OtraMoneda'] : null;
        $this->agregarTotales($dte['Encabezado']['Totales'], $OtraMoneda, $this->y+6, 23, 17);
        // agregar acuse de recibo y leyenda cedible
        if ($this->cedible and !in_array($dte['Encabezado']['IdDoc']['TipoDTE'], $this->sinAcuseRecibo)) {
            $this->agregarAcuseReciboContinuo(3, $this->y+6, $width-6, 34);
            $this->agregarLeyendaDestinoContinuo($dte['Encabezado']['IdDoc']['TipoDTE']);
        }
        // agregar timbre
        $y = $this->agregarObservacion($dte['Encabezado']['IdDoc'], $x_start, $this->y+6);
        // Observaciones adicionales sobre el timbre
        if(isset($dte['Observaciones'])) {
            if ($this->cedible)
                $this->y += 2;
            $y = $this->agregarObservacionAdicional($dte['Observaciones'], $x_start, $this->y);
        }
        $this->agregarTimbreContinuo($timbre, -10, $x_start, $y+2, 70, 6);
        // si el alto no se pasó, entonces es con autocálculo, se elimina esta página y se pasa el alto
        // que se logró determinar para crear la página con el alto correcto
        if (!$height) {
            $this->deletePage($this->PageNo());
            $this->agregar_papel_80($dte, $timbre, $width, $this->getY()+30);
        }
    }

    private function agregar_papel_110(array $dte, $timbre, $height = 0)
    {
        $width = 110;
        if (!empty($this->logo)) {
            $this->logo['posicion'] = 1;
        }
        // determinar alto de la página y agregarla
        $x_start = 1;
        $y_start = 1;
        $offset = 14;
        // determinar alto de la página y agregarla
        $this->AddPage('P', [$height ? $height : $this->papel_continuo_alto, $width]);
        // agregar cabecera del documento
        $y[] = $this->agregarFolio(
            $dte['Encabezado']['Emisor']['RUTEmisor'],
            $dte['Encabezado']['IdDoc']['TipoDTE'],
            $dte['Encabezado']['IdDoc']['Folio'],
            !empty($dte['Encabezado']['Emisor']['CmnaOrigen']) ? $dte['Encabezado']['Emisor']['CmnaOrigen'] : null,
            63,
            2,
            45,
            9,
            [0,0,0]
        );
        $y[] = $this->agregarEmisor($dte['Encabezado']['Emisor'], 1, 2, 20, 56, 9, [0,0,0], $y[0],110);
        $this->SetY(max($y));
        $this->Ln();
        // datos del documento
        $this->setFont('', '', 8);
        $this->agregarDatosEmision($dte['Encabezado']['IdDoc'], !empty($dte['Encabezado']['Emisor']['CdgVendedor'])?$dte['Encabezado']['Emisor']['CdgVendedor']:null, $x_start, $offset, false);
        $this->agregarReceptor($dte['Encabezado'], $x_start, $offset);
        $this->agregarTraslado(
            !empty($dte['Encabezado']['IdDoc']['IndTraslado']) ? $dte['Encabezado']['IdDoc']['IndTraslado'] : null,
            !empty($dte['Encabezado']['Transporte']) ? $dte['Encabezado']['Transporte'] : null,
            $x_start, $offset
        );
        if (!empty($dte['Referencia'])) {
            $this->agregarReferencia($dte['Referencia'], $x_start, $offset);
        }
        $this->Ln();
        $this->agregarDetalleContinuo($dte['Detalle'], 3, [1, 53, 73, 83]);
        if (!empty($dte['DscRcgGlobal'])) {
            $this->Ln();
            $this->Ln();
            $this->agregarSubTotal($dte['Detalle'], 61, 17);
            $this->agregarDescuentosRecargos($dte['DscRcgGlobal'], 61, 17);
        }
        if (!empty($dte['Encabezado']['IdDoc']['MntPagos'])) {
            $this->Ln();
            $this->Ln();
            $this->agregarPagos($dte['Encabezado']['IdDoc']['MntPagos'], $x_start);
        }
        $OtraMoneda = !empty($dte['Encabezado']['OtraMoneda']) ? $dte['Encabezado']['OtraMoneda'] : null;
        $this->agregarTotales($dte['Encabezado']['Totales'], $OtraMoneda, $this->y+6, 61, 17);
        // agregar observaciones
        $y = $this->agregarObservacion($dte['Encabezado']['IdDoc'], $x_start, $this->y+6);
        // Observaciones adicionales sobre el timbre
        if(isset($dte['Observaciones']))
            $y = $this->agregarObservacionAdicional($dte['Observaciones'], $x_start, $this->y);
        // agregar timbre
        $this->agregarTimbre($timbre, 2, 2, $y+6, 60, 6, 'S');
        // agregar acuse de recibo y leyenda cedible
        if ($this->cedible and !in_array($dte['Encabezado']['IdDoc']['TipoDTE'], $this->sinAcuseRecibo)) {
            $this->agregarAcuseRecibo(63, $y+6, 45, 40, 15);
            $this->setFont('', 'B', 8);
            $this->Texto('CEDIBLE'.($dte['Encabezado']['IdDoc']['TipoDTE']==52?' CON SU FACTURA':''), $x_start, $this->y+1, 'L');
        }
        // si el alto no se pasó, entonces es con autocálculo, se elimina esta página y se pasa el alto
        // que se logró determinar para crear la página con el alto correcto
        if (!$height) {
            $this->deletePage($this->PageNo());
            $this->agregar_papel_110($dte, $timbre, $this->getY()+30);
        }
    }

    protected function agregarEmisor(array $emisor, $x = 10, $y = 15, $w = 75, $w_img = 30, $font_size = null, array $color = null, $h_folio = null, $w_all = null)
    {
        $agregarDatosEmisor = true;
        // logo del documento
        if (isset($this->logo)) {
            // logo centrado (papel continuo)
            try {
                $imagick = new \Imagick($this->logo['uri']);
            } catch (\Exception $e) {
                $imagick = false;
                //echo $this->logo['uri']. "\n";
                //echo $e->getMessage(). "\n";
            }
            if ($imagick) {
                if (!empty($this->logo['posicion']) and $this->logo['posicion'] == 'C') {
                    // Se setean en 0 para que $this->Image haga el ajuste.
                    $logo_y = 0;
                    $logo_w = 0;
                    $logo_position = 'C';
                    $logo_next_pointer = 'N';

                    // ver si es svg o png
                    if ($imagick->getImageFormat() != 'SVG') {
                        // Se hace el resize con imagick antes de asignar la imagen
                        // para evitar que la librería haga el resize
                        $imagick = new \Imagick($this->logo['uri']);
                        // Tamaño imagen
                        $logo_size = getimagesize($this->logo['uri']);
                        //echo var_dump($logo_size)."\n"; // quitar
                        if ($logo_size[0] > 200) {
                            $imagick->scaleImage(200, 0); // original 200, 0
                            $imagick->writeImage($this->logo['uri']);
                        }
                    }
                } // logo a la derecha (posicion=0) o arriba (posicion=1)
                else if (empty($this->logo['posicion']) or $this->logo['posicion'] == 1) {
                    $logo_w = !$this->logo['posicion'] ? $w_img : null;
                    $logo_y = $this->logo['posicion'] ? $w_img / 2 : null;
                    $logo_position = '';
                    $logo_next_pointer = 'T';
                } // logo completo, reemplazando datos del emisor (posicion=2)
                else {
                    $logo_w = null;
                    $logo_y = $w_img;
                    $logo_position = '';
                    $logo_next_pointer = 'T';
                    $agregarDatosEmisor = false;
                }

                if ($imagick->getImageFormat() != 'SVG') { // png
                    $this->Image(
                        $this->logo['uri'],
                        $x,
                        $y,
                        $w_img,
                        '',
                        'PNG',
                        '',
                        $logo_next_pointer,
                        false,
                        300,
                        $logo_position,
                    );
                }
                else if (!empty($this->logo['posicion']) and $this->logo['posicion'] == 'C'){ // SVG papel continuo
                    $y -= 15;
                    $this->ImageSVG(
                        $this->logo['uri'],
                        $x,
                        $y,
                        $w_img,
                        '',
                        '',
                        $logo_next_pointer,
                        $logo_position,
                    );
                    $this->y -= 15;
                } else if ((int)$this->papelContinuo == 110) { // SVG Tamaño carta (papel_110)
                    $y -= 19;
                    $this->ImageSVG(
                        $this->logo['uri'],
                        $x,
                        $y,
                        $w_img,
                        '',
                        '',
                        $logo_next_pointer,
                        $logo_position,
                    );
                    $this->y += 19;
                } else if (!empty($this->logo['posicion']) and $this->logo['posicion'] == 1) { // SVG papel continuo
                    $y -= 18;
                    $this->ImageSVG(
                        $this->logo['uri'],
                        $x+1,
                        $y,
                        56,
                        '',
                        '',
                        $logo_next_pointer,
                        $logo_position,
                    );
                    $this->y += 28;
                }  else { // SVG Tamaño carta (papel_0)
                    $y -= 10;
                    $this->ImageSVG(
                        $this->logo['uri'],
                        $x,
                        $y,
                        $w_img,
                        '',
                        '',
                        $logo_next_pointer,
                        $logo_position,
                    );
                    $this->y += 10;
                }

                if (!empty($this->logo['posicion']) and $this->logo['posicion'] == 'C') {
                    $w += 40;
                } else {
                    if ($this->logo['posicion']) {
                        $this->SetY($this->y + ($w_img/2));
                        $w += 40;
                    } else {
                        $x = $this->x+3;
                    }
                }
            }
            $this->y = $y-2;
            $w += 40;
        } else {
            $this->y = $y-2;
            $w += 40;
        }
        // agregar datos del emisor
        if ($agregarDatosEmisor) {
            $this->setFont('', 'B', $font_size ? $font_size : 14);
            $this->SetTextColorArray($color===null?[32, 92, 144]:$color);
            $this->MultiTexto(!empty($emisor['RznSoc']) ? $emisor['RznSoc'] : $emisor['RznSocEmisor'], $x, $this->y+2, 'C', ($h_folio and $h_folio < $this->getY()) ? $w_all : $w);
            $this->setFont('', 'B', $font_size ? $font_size : 9);
            $this->SetTextColorArray([0,0,0]);
            $this->MultiTexto(!empty($emisor['GiroEmis']) ? $emisor['GiroEmis'] : $emisor['GiroEmisor'], $x, $this->y, 'C', ($h_folio and $h_folio < $this->getY()) ? $w_all : $w);
            $direccion = !empty($emisor['DirOrigen']) ? $emisor['DirOrigen'] : null;
            $comuna = !empty($emisor['CmnaOrigen']) ? $emisor['CmnaOrigen'] : null;
            $ciudad = !empty($emisor['CiudadOrigen']) ? $emisor['CiudadOrigen'] : \sasco\LibreDTE\Chile::getCiudad($comuna);
            $this->setFont('', 'B', $font_size ? $font_size-1 : 8);
            if (empty($this->casa_matriz))
                $this->MultiTexto($direccion.($comuna?(', '.$comuna):'').($ciudad?(', '.$ciudad):''), $x, $this->y, 'L', ($h_folio and $h_folio < $this->getY()) ? $w_all : $w);
            if (!empty($emisor['Sucursal'])) {
                $this->MultiTexto('Sucursal: '.$emisor['Sucursal'], $x, $this->y, 'L', ($h_folio and $h_folio < $this->getY()) ? $w_all : $w);
            }
            if (!empty($this->casa_matriz)) {
                $this->MultiTexto("CASA MATRIZ\n".$direccion.($comuna?(', '.$comuna):'').($ciudad?(', '.$ciudad):''), $x, $this->y, 'L', ($h_folio and $h_folio < $this->getY()) ? $w_all : $w);
                //$this->MultiTexto('Casa matriz: '.$this->casa_matriz, $x, $this->y, 'L', ($h_folio and $h_folio < $this->getY()) ? $w_all : $w);
            }
            $contacto = [];
            if (!empty($emisor['Telefono'])) {
                if (!is_array($emisor['Telefono'])) {
                    $emisor['Telefono'] = [$emisor['Telefono']];
                }
                foreach ($emisor['Telefono'] as $t) {
                    $contacto[] = $t;
                }
            }
            if (!empty($emisor['CorreoEmisor'])) {
                $contacto[] = $emisor['CorreoEmisor'];
            }
            if ($contacto) {
                if ((int)$this->papelContinuo == 0) // Si es papel_0
                    $contacto = implode(" / ", $contacto);
                else
                    $contacto = implode("\n", $contacto);
                $this->MultiTexto($contacto, $x, $this->y, 'L', ($h_folio and $h_folio < $this->getY()) ? $w_all : $w);
            }
            $this->setFont('', 'B', $font_size ? $font_size : 9);
        }
        return $this->y-6;
    }

    protected function agregarObservacionAdicional(array $observaciones, $x = 10, $y = 190): float
    {
        $y = (!$this->papelContinuo and !$this->timbre_pie) ? $this->x_fin_datos : $y;
        if (!$this->papelContinuo and $this->timbre_pie) {
            $y -= 15;
        }

        // Agregar linea divisoria
        $p1x = $x;
        $p1y = $y;
        $p2x = $this->getPageWidth() - 2;
        $p2y = $p1y;  // Use same y for a straight line
        $style = array('width' => 0.2,'color' => array(0, 0, 0));
        $this->Line($p1x+2, $p1y, $p2x, $p2y, $style);

        // Agregar observaciones adicionales con texto centrado
        $font = $this->detalle_fuente;
        $this->setFont('', '', 5);
        //$this->SetXY($x, $y-2);
        $this->Ln();
        foreach ($observaciones as $observacion) {
            //$this->MultiTexto($observaciones_str, null, $y-2, 'C');
            $this->MultiCell($this->w-4, null, $observacion, $border=0, $align='C', $fill=false, $ln=1, $x, $this->y, $reseth=true, $stretch=0, $ishtml=false, $autopadding=true, $maxh=0, $valign='T', $fitcell=false);
        }
        //$observaciones_str = implode(' ', $observaciones);
        $this->setFont('', '', $font);
        return $this->getY();
    }

    public function agregarTickets(array $tickets, $x = 10, $y = 190, $width = 80, $height = 0): void
    {
        // Agregar tickets
        $this->setFont('', '', 8);
        //$this->SetXY($x, $y);
        $this->setMargins(5,0,5);
        foreach ($tickets as $ticket) {
            $this->AddPage('P', [$height ? $height : 80, $width]);
            $this->MultiTexto(base64_decode($ticket), $x, null, 'L');
        }
    }

    protected function agregarDetalleContinuo($detalle, $x = 3, array $offsets = [])
    {
        if (!$offsets) {
            $offsets = [1, 15, 35, 45];
        }
        $this->SetY($this->getY()+1);
        $p1x = $x;
        $p1y = $this->y;
        $p2x = $this->getPageWidth() - 2;
        $p2y = $p1y;  // Use same y for a straight line
        $style = array('width' => 0.2,'color' => array(0, 0, 0));
        $this->Line($p1x, $p1y, $p2x, $p2y, $style);
        $this->Texto($this->detalle_cols['NmbItem']['title'], $x+$offsets[0], $this->y, ucfirst($this->detalle_cols['NmbItem']['align'][0]), $this->detalle_cols['NmbItem']['width']);
        $this->Texto($this->detalle_cols['PrcItem']['title'], $x+$offsets[1], $this->y, ucfirst($this->detalle_cols['PrcItem']['align'][0]), $this->detalle_cols['PrcItem']['width']);
        $this->Texto($this->detalle_cols['QtyItem']['title'], $x+$offsets[2], $this->y, ucfirst($this->detalle_cols['QtyItem']['align'][0]), $this->detalle_cols['QtyItem']['width']);
        $this->Texto($this->detalle_cols['MontoItem']['title'], $x+$offsets[3], $this->y, ucfirst($this->detalle_cols['MontoItem']['align'][0]), $this->detalle_cols['MontoItem']['width']);
        $this->Line($p1x, $p1y+4, $p2x, $p2y+4, $style);
        if (!isset($detalle[0])) {
            $detalle = [$detalle];
        }
        // mostrar items
        $this->SetY($this->getY()+2);
        foreach($detalle as  &$d) {
            // sku del item
            $item = $d['NmbItem'];
            $this->Texto($item, $x+$offsets[0], $this->y+4, ucfirst($this->detalle_cols['NmbItem']['align'][0]), $this->detalle_cols['NmbItem']['width']);
            // descuento
            if (!empty($d['DescuentoPct']) or !empty($d['DescuentoMonto'])) {
                if (!empty($d['DescuentoPct'])) {
                    $descuento = (is_numeric($d['DescuentoPct']) ? $this->num($d['DescuentoPct']) : $d['DescuentoPct']).'%';
                } else {
                    $descuento = is_numeric($d['DescuentoMonto']) ? $this->num($d['DescuentoMonto']) : $d['DescuentoMonto'];
                }
                $this->Texto('Desc.: '.$descuento, $x+$offsets[0], $this->y, ucfirst($this->detalle_cols['NmbItem']['align'][0]), $this->detalle_cols['NmbItem']['width']);
            }
            // precio y cantidad
            if (isset($d['PrcItem'])) {
                $this->Texto(is_numeric($d['PrcItem']) ? $this->num($d['PrcItem']) : $d['PrcItem'], $x+$offsets[1], $this->y, ucfirst($this->detalle_cols['PrcItem']['align'][0]), $this->detalle_cols['PrcItem']['width']);
            }
            if (isset($d['QtyItem'])) {
                $this->Texto($this->num($d['QtyItem']), $x+$offsets[2], $this->y, ucfirst($this->detalle_cols['QtyItem']['align'][0]), $this->detalle_cols['QtyItem']['width']);
            }
            $this->Texto($this->num($d['MontoItem']), $x+$offsets[3], $this->y, ucfirst($this->detalle_cols['MontoItem']['align'][0]), $this->detalle_cols['MontoItem']['width']);
            // descripción del item
            if ($this->papel_continuo_item_detalle and !empty($d['DscItem'])) {
                $this->MultiTexto($d['DscItem'], $x+$offsets[0], $this->y+4, ucfirst($this->detalle_cols['NmbItem']['align'][0]), $this->w-4);
                $this->y -=4;
            }
        }
        $this->y += 2;
        $this->Line($p1x, $this->y+4, $p2x, $this->y+4, $style);
    }

    protected function agregarTimbreContinuo($timbre, $x_timbre = 10, $x = 10, $y = 190, $w = 70, $font_size = 8, $position = null)
    {
        $y = (!$this->papelContinuo and !$this->timbre_pie) ? $this->x_fin_datos : $y;
        if ($timbre!==null) {
            $style = [
                'border' => false,
                'padding' => 0,
                'hpadding' => 0,
                'vpadding' => 0,
                'module_width' => 1, // width of a single module in points
                'module_height' => 1, // height of a single module in points
                'fgcolor' => [0,0,0],
                'bgcolor' => false, // [255,255,255]
                'position' => $position === null ? ($this->papelContinuo ? 'C' : 'S') : $position,
            ];
            $ecl = version_compare(phpversion(), '7.0.0', '<') ? -1 : $this->ecl;
            $this->write2DBarcode($timbre, 'PDF417,,'.$ecl, $x_timbre, $y, $w, 0, $style, 'B');
            $this->setFont('', 'B', $font_size);
            $this->Texto('Timbre Electrónico SII', $x, null, 'C', $this->w);
            $this->Ln();
            $this->Texto('Resolución '.$this->resolucion['NroResol'].' de '.explode('-', $this->resolucion['FchResol'])[0], $x, null, 'C', $this->w);
            $this->Ln();
            if ($w>=60) {
                $this->Texto('Verifique documento: '.$this->web_verificacion, $x, null, 'C', $this->w);
            } else {
                $this->Texto('Verifique documento:', $x, null, 'C', $this->w);
                $this->Ln();
                $this->Texto($this->web_verificacion, $x, null, 'C', $this->w);
            }
        }
    }

    /**
     * Método que agrega los datos del receptor
     * @param receptor Arreglo con los datos del receptor (tag Receptor del XML)
     * @param x Posición horizontal de inicio en el PDF
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2019-10-06
     */
    protected function agregarReceptor_70(array $Encabezado, $x = 10, $offset = 22)
    {
        $w = $this->w-($x+$offset+13);
        $receptor = $Encabezado['Receptor'];
        if (!empty($receptor['RUTRecep']) and $receptor['RUTRecep']!='66666666-6') {
            list($rut, $dv) = explode('-', $receptor['RUTRecep']);
            $this->setFont('', 'B', null);
            $this->Texto(in_array($this->dte, [39, 41]) ? 'R.U.N.' : 'R.U.T.', $x);
            $this->Texto(':', $x+$offset);
            $this->setFont('', '', null);
            $this->MultiTexto($this->num($rut).'-'.$dv, $x+$offset+2, null, '', $w);
        }
        if (!empty($receptor['RznSocRecep'])) {
            $this->setFont('', 'B', null);
            $this->Texto(in_array($this->dte, [39, 41]) ? 'Nombre' : ($x==10?'Razón social':'Razón soc.'), $x);
            $this->Texto(':', $x+$offset);
            $this->setFont('', '', null);
            $this->MultiTexto($receptor['RznSocRecep'], $x+$offset+2, null, '', $w);
        }
        if (!empty($receptor['GiroRecep'])) {
            $this->setFont('', 'B', null);
            $this->Texto('Giro', $x);
            $this->Texto(':', $x+$offset);
            $this->setFont('', '', null);
            $this->MultiTexto($receptor['GiroRecep'], $x+$offset+2, null, '', $w);
        }
        if (!empty($receptor['DirRecep'])) {
            $this->setFont('', 'B', null);
            $this->Texto('Dirección', $x);
            $this->Texto(':', $x+$offset);
            $this->setFont('', '', null);
            $ciudad = !empty($receptor['CiudadRecep']) ? $receptor['CiudadRecep'] : (
            !empty($receptor['CmnaRecep']) ? \sasco\LibreDTE\Chile::getCiudad($receptor['CmnaRecep']) : ''
            );
            $this->MultiTexto($receptor['DirRecep'].(!empty($receptor['CmnaRecep'])?(', '.$receptor['CmnaRecep']):'').($ciudad?(', '.$ciudad):''), $x+$offset+2, null, '', $w);
        }
        if (!empty($receptor['Extranjero']['Nacionalidad'])) {
            $this->setFont('', 'B', null);
            $this->Texto('Nacionalidad', $x);
            $this->Texto(':', $x+$offset);
            $this->setFont('', '', null);
            $this->MultiTexto(\sasco\LibreDTE\Sii\Aduana::getNacionalidad($receptor['Extranjero']['Nacionalidad']), $x+$offset+2, null, '', $w);
        }
        if (!empty($receptor['Extranjero']['NumId'])) {
            $this->setFont('', 'B', null);
            $this->Texto('N° ID extranj.', $x);
            $this->Texto(':', $x+$offset);
            $this->setFont('', '', null);
            $this->MultiTexto($receptor['Extranjero']['NumId'], $x+$offset+2, null, '', $w);
        }
        $contacto = [];
        if (!empty($receptor['Contacto']))
            $contacto[] = $receptor['Contacto'];
        if (!empty($receptor['CorreoRecep']))
            $contacto[] = $receptor['CorreoRecep'];
        if (!empty($contacto)) {
            $this->setFont('', 'B', null);
            $this->Texto('Contacto', $x);
            $this->Texto(':', $x+$offset);
            $this->setFont('', '', null);
            $this->MultiTexto(implode(' / ', $contacto), $x+$offset+2, null, '', 40);
        }
        if (!empty($Encabezado['RUTSolicita'])) {
            list($rut, $dv) = explode('-', $Encabezado['RUTSolicita']);
            $this->setFont('', 'B', null);
            $this->Texto('RUT solicita', $x);
            $this->Texto(':', $x+$offset);
            $this->setFont('', '', null);
            $this->MultiTexto($this->num($rut).'-'.$dv, $x+$offset+2, null, '', $w);
        }
        if (!empty($receptor['CdgIntRecep'])) {
            $this->setFont('', 'B', null);
            $this->Texto('Cód. recep.', $x);
            $this->Texto(':', $x+$offset);
            $this->setFont('', '', null);
            $this->MultiTexto($receptor['CdgIntRecep'], $x+$offset+2, null, '', $w);
        }
        return $this->GetY();
    }

    /**
     * Método que agrega el acuse de rebido
     * @param x Posición horizontal de inicio en el PDF
     * @param y Posición vertical de inicio en el PDF
     * @param w Ancho del acuse de recibo
     * @param h Alto del acuse de recibo
     * @author Pablo Reyes (https://github.com/pabloxp)
     * @version 2015-11-17
     */
    protected function agregarAcuseReciboContinuo_70($x = 3, $y = null, $w = 68, $h = 40)
    {
        $this->SetTextColorArray([0,0,0]);
        $this->Rect($x, $y, $w, $h, 'D', ['all' => ['width' => 0.1, 'color' => [0, 0, 0]]]);
        $style = array('width' => 0.2,'color' => array(0, 0, 0));
        $this->Line($x, $y+22, $w+3, $y+22, $style);
        //$this->setFont('', 'B', 10);
        //$this->Texto('Acuse de recibo', $x, $y+1, 'C', $w);
        $this->setFont('', 'B', 6);
        $this->Texto('Nombre', $x+2, $this->y+8);
        $this->Texto('__________________________________________', $x+12);
        $this->Texto('RUN', $x+2, $this->y+6);
        $this->Texto('________________', $x+12);
        $this->Texto('Firma', $x+32, $this->y+0.5);
        $this->Texto('________________', $x+42.5);
        $this->Texto('Fecha', $x+2, $this->y+6);
        $this->Texto('________________', $x+12);
        $this->Texto('Recinto', $x+32, $this->y+0.5);
        $this->Texto('________________', $x+42.5);

        $this->setFont('', 'B', 5);
        $this->MultiTexto('El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4°, y la letra c) del Art. 5° de la Ley 19.983, acredita que la entrega de mercaderías o servicio (s) prestado (s) ha (n) sido recibido (s).'."\n", $x+2, $this->y+8, 'J', $w-3);
    }
}
