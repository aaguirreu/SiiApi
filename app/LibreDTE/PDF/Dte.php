<?php

namespace App\LibreDTE\PDF;

class Dte extends \sasco\LibreDTE\Sii\Dte\PDF\Dte
{
    public function __construct($papelContinuo = false)
    {
        parent::__construct();
        $this->SetTitle('Documento Tributario Electrónico (DTE) de Chile by LibreDTE');
        $this->papelContinuo = $papelContinuo === true ? 80 : $papelContinuo;
    }

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
                    'left' => 'Boletas y Facturas con Logiciel',
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
        $this->MultiTexto($dte['Encabezado']['Emisor']['DirOrigen'].', '.$dte['Encabezado']['Emisor']['CmnaOrigen'], $x, null, '', $width-2);
        if (!empty($dte['Encabezado']['Emisor']['Sucursal'])) {
            $this->MultiTexto('Sucursal: '.$dte['Encabezado']['Emisor']['Sucursal'], $x, null, '', $width-2);
        }
        if (!empty($this->casa_matriz)) {
            $this->MultiTexto('Casa matriz: '.$this->casa_matriz, $x, null, '', $width-2);
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
        // determinar alto de la página y agregarla
        $this->AddPage('P', [$height ? $height : $this->papel_continuo_alto, $width]);
        // agregar cabecera del documento
        $y = $this->agregarFolio(
            $dte['Encabezado']['Emisor']['RUTEmisor'],
            $dte['Encabezado']['IdDoc']['TipoDTE'],
            $dte['Encabezado']['IdDoc']['Folio'],
            isset($dte['Encabezado']['Emisor']['CmnaOrigen']) ? $dte['Encabezado']['Emisor']['CmnaOrigen'] : 'Sin comuna', // siempre debería tener comuna
            $x_start, $y_start, $width-($x_start*4), 10,
            [0,0,0]
        );
        $y = $this->agregarEmisor($dte['Encabezado']['Emisor'], $x_start, $y+2, $width-($x_start*45), 8, 9, [0,0,0]);
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
            $this->agregarAcuseReciboContinuo(3, $this->y+6, 68, 34);
            $this->agregarLeyendaDestinoContinuo($dte['Encabezado']['IdDoc']['TipoDTE']);
        }
        // agregar timbre
        $y = $this->agregarObservacion($dte['Encabezado']['IdDoc'], $x_start, $this->y+6);
        // Observaciones adicionales sobre el timbre
        if(isset($dte['Observaciones']))
            $y = $this->agregarObservacionAdicional($dte['Observaciones'], $x_start, $this->y);
        $this->agregarTimbre($timbre, -10, $x_start, $y+6, 70, 6);
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
        $y[] = $this->agregarEmisor($dte['Encabezado']['Emisor'], 1, 2, 20, 30, 9, [0,0,0], $y[0]);
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

    protected function agregarObservacionAdicional(array $observaciones, $x, $y): float
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
        $this->Line($p1x, $p1y, $p2x, $p2y, $style);

        // Agregar observaciones adicionales con texto centrado
        $this->setFont('', '', 8);
        //$this->SetXY($x, $y);
        $this->Ln();
        foreach ($observaciones as $observacion) {
            $this->MultiTexto($observacion, null, null, 'C');
        }
        return $this->getY();
    }
}
