<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hooks used by WarrantyPeriod.
 *
 * Shipment-line records do not exist yet while Dolibarr renders the shipment
 * creation form, therefore persistence triggers cannot populate the visible
 * line extrafields at that point. This hook pre-fills the normal core date
 * widget without replacing any shipment-page HTML.
 */
class ActionsWarrantyPeriod extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Pre-fill the configured warranty-expiration field for an order line while
	 * Dolibarr renders the shipment creation form.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object Current source object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0 to keep standard rendering
	 */
	public function printObjectLine($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $date_shipping;

		if ($action !== 'create' || ($parameters['currentcontext'] ?? '') !== 'expeditioncard') {
			return 0;
		}
		if (empty($parameters['line']) || !is_object($parameters['line'])) {
			return 0;
		}

		$sourceField = trim(getDolGlobalString('WARRANTYPERIOD_PRODUCT_FIELD'));
		$targetField = trim(getDolGlobalString('WARRANTYPERIOD_TARGET_FIELD'));
		if (!$this->isSafeFieldName($sourceField) || !$this->isSafeFieldName($targetField)) {
			return 0;
		}

		$line = $parameters['line'];
		$productId = !empty($line->fk_product) ? (int) $line->fk_product : 0;
		if ($productId <= 0) {
			return 0;
		}

		$shippingDate = $this->normalizeDate($date_shipping);
		if ($shippingDate === null) {
			return 0;
		}

		$sql = 'SELECT pe.'.$sourceField.' AS warranty_months';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product_extrafields AS pe';
		$sql .= ' WHERE pe.fk_object = '.$productId;
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}

		$data = $this->db->fetch_object($resql);
		if (!$data || !is_numeric($data->warranty_months) || (int) $data->warranty_months <= 0) {
			return 0;
		}

		dol_include_once('/warrantyperiod/class/warrantyperiod.class.php');
		$expiration = WarrantyPeriodManager::addMonthsClamped($shippingDate, (int) $data->warranty_months);
		if ($expiration === null) {
			return 0;
		}

		$index = isset($parameters['i']) ? (int) $parameters['i'] : 0;
		$dateKey = 'options_'.$targetField.$index;

		// Keep a value entered by the user when a failed submit redisplays the form.
		if (GETPOSTISSET($dateKey)) {
			return 0;
		}

		$timestamp = strtotime($expiration.' 12:00:00');
		if ($timestamp === false) {
			return 0;
		}

		// CommonObject::showOptionals() checks the base date key and then reads
		// these date components. The temporary ExpeditionLigne has no database id,
		// so elrowid=0 selects that create-form row.
		$_POST[$dateKey] = $expiration;
		$_POST[$dateKey.'year'] = (int) date('Y', $timestamp);
		$_POST[$dateKey.'month'] = (int) date('m', $timestamp);
		$_POST[$dateKey.'day'] = (int) date('d', $timestamp);
		if (!GETPOSTISSET('elrowid')) {
			$_POST['elrowid'] = 0;
		}

		return 0;
	}

	/** @return bool */
	private function isSafeFieldName($fieldName)
	{
		return $fieldName !== '' && (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $fieldName);
	}

	/** @return string|null Y-m-d */
	private function normalizeDate($value)
	{
		if (empty($value)) {
			return null;
		}
		if (is_numeric($value)) {
			$timestamp = (int) $value;
			return $timestamp > 0 ? dol_print_date($timestamp, '%Y-%m-%d', 'tzserver') : null;
		}
		$value = trim((string) $value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
			return substr($value, 0, 10);
		}
		$timestamp = strtotime($value);
		return $timestamp === false ? null : dol_print_date($timestamp, '%Y-%m-%d', 'tzserver');
	}
}
