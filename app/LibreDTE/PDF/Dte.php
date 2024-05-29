<?php

namespace App\LibreDTE\PDF;

class Dte extends \sasco\LibreDTE\Sii\Dte\PDF\Dte
{
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

    /**
     * Método que agrega observaciones abajo de los totales
     * Es identico al original, pero se le agrega una seccion de observaciones
     */
    public function agregar_papel_80(array $dte, $timbre, $width = 80, $height = 0)
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
        $dte['Observaciones'] = ["Observación 1 larga de prueba para ver el resultado en la boleta papel continuo", "Observación 2", "Observación 3","Observación 4", "Observación 5", "Observación 6"];
        $y = $this->agregarObservacionAdicional($dte['Observaciones'], $x_start, $this->y);
        $this->agregarTimbre($timbre, -10, $x_start, $y+6, 70, 6);
        // si el alto no se pasó, entonces es con autocálculo, se elimina esta página y se pasa el alto
        // que se logró determinar para crear la página con el alto correcto
        if (!$height) {
            $this->deletePage($this->PageNo());
            $this->agregar_papel_80($dte, $timbre, $width, $this->getY()+30);
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
