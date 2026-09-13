<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

/**
 * \file       core/modules/expedition/doc/pdf_nautilus.modules.php
 * \ingroup    warrantyperiod
 * \brief      Digital Nautics shipment PDF model based on Espadon.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/doc/pdf_espadon.modules.php';

/**
 * Digital Nautics shipment PDF.
 *
 * Nautilus intentionally inherits Espadon's mature page-breaking, header,
 * address, totals and extra-field machinery, while customising the line table
 * for the way Digital Nautics uses shipment documents.
 */
class pdf_nautilus extends pdf_espadon
{
    /**
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        global $langs;

        parent::__construct($db);

        $langs->load('warrantyperiod@warrantyperiod');
        $this->name = 'nautilus';
        $this->description = $langs->trans('DocumentModelNautilus');
        $this->version = 'dolibarr';
    }

    /**
     * Define a compact shipment table.
     *
     * Weight/volume is displayed only when at least one physical product has
     * meaningful dimensional data. The configured WarrantyPeriod target field
     * is kept as a dedicated right-hand column when it is printable on PDFs.
     *
     * @param CommonObject $object Shipment object
     * @param Translate $outputlangs Output language
     * @param int<0,1> $hidedetails Hide line details
     * @param int<0,1> $hidedesc Hide description
     * @param int<0,1> $hideref Hide product reference
     * @return void
     */
    public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        parent::defineColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);

        // The line number adds little value on a shipment and costs useful width.
        if (isset($this->cols['position'])) {
            $this->cols['position']['status'] = false;
        }

        // Do not reserve a large empty weight/volume column. Show it only when
        // at least one physical product actually carries dimensional data.
        $hasWeightOrVolume = false;
        if (!empty($object->lines) && is_array($object->lines)) {
            foreach ($object->lines as $line) {
                $productType = isset($line->fk_product_type)
                    ? (int) $line->fk_product_type
                    : (isset($line->product_type) ? (int) $line->product_type : 0);
                if ($productType !== 0) {
                    continue;
                }
                if (!empty($line->weight) || !empty($line->volume)) {
                    $hasWeightOrVolume = true;
                    break;
                }
            }
        }
        if (isset($this->cols['weight'])) {
            $this->cols['weight']['status'] = $hasWeightOrVolume;
            $this->cols['weight']['width'] = 24;
        }

        // Compact quantity columns leave more room for product descriptions.
        if (isset($this->cols['qty_asked'])) {
            $this->cols['qty_asked']['width'] = 22;
        }
        if (isset($this->cols['unit_order'])) {
            $this->cols['unit_order']['width'] = 18;
        }
        if (isset($this->cols['qty_shipped'])) {
            $this->cols['qty_shipped']['width'] = 22;
        }

        // Make the WarrantyPeriod target easy to read and keep it at the far
        // right. Other printable shipment-line extra fields keep normal Espadon
        // behaviour.
        $targetField = trim(getDolGlobalString('WARRANTYPERIOD_TARGET_FIELD'));
        if ($targetField !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $targetField)) {
            $columnKey = 'options_'.$targetField;
            if (isset($this->cols[$columnKey])) {
                $this->cols[$columnKey]['rank'] = 10000;
                $this->cols[$columnKey]['width'] = 27;
                $this->cols[$columnKey]['content']['align'] = 'C';
                $this->cols[$columnKey]['title']['align'] = 'C';
            }
        }
    }

    /**
     * Print a shipment description without Espadon's redundant lot metadata.
     *
     * Core pdf_getlinedesc() is reused for the normal product reference, label,
     * description, variants, periods and barcode behaviour. Batch data is
     * temporarily removed from the line so core does not append sell-by/eat-by
     * dates and a duplicate quantity. We then add back only the information a
     * shipment recipient needs:
     *
     * - lot/serial number;
     * - quantity only when it carries information (for example a line split
     *   between several lots with quantities greater than one);
     * - warehouse only when Dolibarr's existing batch/warehouse option asks for
     *   it.
     *
     * @param TCPDI|TCPDF $pdf PDF object
     * @param float $curY Current Y position
     * @param string $colKey Column key
     * @param CommonObject $object Shipment
     * @param int $i Line index
     * @param Translate $outputlangs Output language
     * @param int<0,1> $hideref Hide product reference
     * @param int<0,1> $hidedesc Hide description
     * @param int<0,1> $issupplierline Supplier-line mode
     * @return void
     */
    public function printColDescContent($pdf, &$curY, $colKey, $object, $i, $outputlangs, $hideref = 0, $hidedesc = 0, $issupplierline = 0)
    {
        global $hookmanager;

        $colDef = $this->cols[$colKey];
        $currentCellPaddings = $pdf->getCellPaddings();
        $pdf->setCellPaddings(
            $colDef['content']['padding'][3],
            $colDef['content']['padding'][0],
            $colDef['content']['padding'][1],
            $colDef['content']['padding'][2]
        );

        $line = $object->lines[$i];
        $batchDetails = !empty($line->detail_batch) && is_array($line->detail_batch)
            ? $line->detail_batch
            : array();

        // Reuse Dolibarr's standard description builder, but without its batch
        // suffix. This keeps us compatible with product/variant/translation and
        // description settings without copying core logic.
        $savedBatchDetails = $line->detail_batch ?? null;
        $line->detail_batch = false;
        $description = pdf_getlinedesc($object, $i, $outputlangs, $hideref, $hidedesc, $issupplierline);
        $line->detail_batch = $savedBatchDetails;

        if (!empty($batchDetails)) {
            $outputlangs->load('productbatch');

            $showBatchQty = $this->batchQuantityAddsInformation($batchDetails);
            $showWarehouse = getDolGlobalInt('PRODUCTBATCH_SHOW_WAREHOUSE_ON_SHIPMENT');
            $tmpWarehouse = null;
            $tmpProductBatch = null;

            if ($showWarehouse) {
                include_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
                include_once DOL_DOCUMENT_ROOT.'/product/class/productbatch.class.php';
                $tmpWarehouse = new Entrepot($this->db);
                $tmpProductBatch = new Productbatch($this->db);
            }

            foreach ($batchDetails as $detail) {
                if (empty($detail->batch)) {
                    continue;
                }

                $parts = array();
                $parts[] = $outputlangs->transnoentitiesnoconv('printBatch', $detail->batch);

                if ($showBatchQty && !empty($detail->qty)) {
                    $parts[] = $outputlangs->transnoentitiesnoconv('printQty', (string) $detail->qty);
                }

                if ($showWarehouse && !empty($detail->fk_origin_stock) && $tmpProductBatch instanceof Productbatch && $tmpWarehouse instanceof Entrepot) {
                    if ($tmpProductBatch->fetch((int) $detail->fk_origin_stock) > 0 && $tmpWarehouse->fetch((int) $tmpProductBatch->warehouseid) > 0) {
                        $parts[] = $tmpWarehouse->ref;
                    }
                }

                $description .= '<br>'.dol_htmlentitiesbr(implode(' - ', $parts), 1);
            }
        }

        $align = empty($colDef['content']['align']) ? 'J' : $colDef['content']['align'];
        $pdf->SetXY($colDef['xStartPos'], $curY);
        $pdf->writeHTMLCell($colDef['width'], 3, $colDef['xStartPos'], $curY, $description, 0, 1, false, true, $align);
        $posYAfterDescription = $pdf->GetY() - $colDef['content']['padding'][0];

        $pdf->setCellPaddings(
            $currentCellPaddings['L'],
            $currentCellPaddings['T'],
            $currentCellPaddings['R'],
            $currentCellPaddings['B']
        );

        // Preserve the normal Dolibarr handling of extra fields configured to be
        // printed below the line description instead of as dedicated columns.
        $params = array(
            'display' => 'list',
            'printableEnable' => array(3),
            'printableEnableNotEmpty' => array(4),
        );
        $extrafieldDesc = $this->getExtrafieldsInHtml($line, $outputlangs, $params);
        if (!empty($extrafieldDesc)) {
            $this->printStdColumnContent($pdf, $posYAfterDescription, $colKey, $extrafieldDesc);
        }

        $parameters = array(
            'curY' => &$curY,
            'colKey' => $colKey,
            'object' => $object,
            'i' => $i,
            'outputlangs' => $outputlangs,
            'pdf' => &$pdf,
        );
        $reshook = $hookmanager->executeHooks('printColDescContent', $parameters, $this);
        if ($reshook < 0) {
            setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
        }
    }

    /**
     * Decide whether per-batch quantity adds useful information.
     *
     * Single-lot lines and ordinary serial-number lines (all detail quantities
     * are exactly one) already have their total in the dedicated shipped-qty
     * column, so repeating "Quantity: 1" only creates noise. A genuine split
     * across lot quantities retains the per-lot quantity.
     *
     * @param array<int,object> $batchDetails Batch detail rows
     * @return bool
     */
    private function batchQuantityAddsInformation(array $batchDetails)
    {
        if (count($batchDetails) <= 1) {
            return false;
        }

        foreach ($batchDetails as $detail) {
            if (isset($detail->qty) && (float) $detail->qty !== 1.0) {
                return true;
            }
        }

        return false;
    }
}
