<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

/**
 * \file class/warrantyperiod.class.php
 * \ingroup warrantyperiod
 * \brief Warranty-period calculation and shipment-line extrafield synchronization.
 */

class WarrantyPeriodManager
{
	public const DEFAULT_TARGET_FIELD = 'warranty_expiration';

	/** @var DoliDB */
	private $db;

	/** @var int */
	private $entity;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 * @param int $entity Dolibarr entity
	 */
	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = (int) $entity;
	}

	/**
	 * Return Product integer extrafields that can be used as warranty months.
	 *
	 * @return array<string,array{label:string,type:string}>
	 */
	public function getProductMonthFields()
	{
		return $this->getExtraFields('product', array('int'));
	}

	/**
	 * Return shipment-line date extrafields that can receive the expiration date.
	 *
	 * @return array<string,array{label:string,type:string}>
	 */
	public function getShipmentLineDateFields()
	{
		return $this->getExtraFields('expeditiondet', array('date'));
	}

	/**
	 * Create the module's default shipment-line expiration field if it does not exist.
	 *
	 * @return int >0 on success, <0 on error
	 */
	public function ensureDefaultTargetField()
	{
		$existing = $this->getExtraFieldDefinition('expeditiondet', self::DEFAULT_TARGET_FIELD);
		if ($existing !== null) {
			if ($existing['type'] !== 'date') {
				$this->error = 'Extrafield '.self::DEFAULT_TARGET_FIELD.' already exists but is not a date field.';
				return -1;
			}
			return 1;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);
		$result = $extrafields->addExtraField(
			self::DEFAULT_TARGET_FIELD,
			'WarrantyExpiration',
			'date',
			100,
			'',
			'expeditiondet',
			0,
			0,
			'',
			'',
			1,
			'',
			'1',
			'WarrantyExpirationHelp',
			'',
			(string) $this->entity,
			'warrantyperiod@warrantyperiod',
			'1',
			0,
			1
		);

		if ($result <= 0) {
			$this->error = $extrafields->error ?: 'Failed to create shipment-line warranty expiration extrafield.';
			return -1;
		}

		return 1;
	}

	/**
	 * Recalculate every line of a shipment.
	 *
	 * Each shipment line receives its own expiration date from the warranty months
	 * of the product on that line. Different products may therefore have different
	 * warranty expiration dates on the same shipment.
	 *
	 * @param CommonObject $object Shipment object
	 * @return array<string,mixed>
	 */
	public function synchronizeShipment($object)
	{
		$configuration = $this->getValidatedConfiguration();
		if (!$configuration['ok']) {
			return $configuration;
		}
		if ($configuration['status'] === 'unconfigured') {
			return $configuration;
		}

		$startDate = $this->normalizeShipmentDate($object);
		if ($startDate === null) {
			$startDate = $this->fetchShipmentDate((int) $object->id);
		}

		$sql = "SELECT ed.rowid, ed.fk_product";
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet AS ed";
		$sql .= " WHERE ed.fk_expedition = ".((int) $object->id);
		$sql .= " ORDER BY ed.rowid";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array('ok' => false, 'status' => 'db_error');
		}

		$updated = 0;
		$cleared = 0;
		while ($line = $this->db->fetch_object($resql)) {
			$result = $this->synchronizeLineData(
				(int) $line->rowid,
				(int) $line->fk_product,
				$startDate,
				$configuration['source_field'],
				$configuration['target_field']
			);
			if (empty($result['ok'])) {
				return $result;
			}
			if ($result['status'] === 'updated') {
				$updated++;
			} else {
				$cleared++;
			}
		}

		return array(
			'ok' => true,
			'status' => 'synchronized',
			'updated' => $updated,
			'cleared' => $cleared,
		);
	}

	/**
	 * Recalculate one shipment line, used by line insert/modify triggers.
	 *
	 * @param CommonObjectLine $line Shipment line object
	 * @return array<string,mixed>
	 */
	public function synchronizeShipmentLine($line)
	{
		$configuration = $this->getValidatedConfiguration();
		if (!$configuration['ok']) {
			return $configuration;
		}
		if ($configuration['status'] === 'unconfigured') {
			return $configuration;
		}

		$sql = "SELECT ed.fk_expedition, ed.fk_product, e.date_expedition";
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet AS ed";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."expedition AS e ON e.rowid = ed.fk_expedition";
		$sql .= " WHERE ed.rowid = ".((int) $line->id);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array('ok' => false, 'status' => 'db_error');
		}

		$data = $this->db->fetch_object($resql);
		if (!$data) {
			return array('ok' => true, 'status' => 'line_missing');
		}

		$startDate = $this->normalizeDateValue($data->date_expedition);

		return $this->synchronizeLineData(
			(int) $line->id,
			(int) $data->fk_product,
			$startDate,
			$configuration['source_field'],
			$configuration['target_field']
		);
	}

	/**
	 * Add whole calendar months while clamping the day to the last valid day.
	 *
	 * @param string $date Y-m-d
	 * @param int $months Positive month count
	 * @return string|null Y-m-d or null
	 */
	public static function addMonthsClamped($date, $months)
	{
		$months = (int) $months;
		if ($months < 0) {
			return null;
		}

		$start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
		$errors = DateTimeImmutable::getLastErrors();
		if ($start === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			return null;
		}

		$day = (int) $start->format('d');
		$targetMonth = $start->modify('first day of this month')->modify('+'.$months.' months');
		$targetDay = min($day, (int) $targetMonth->format('t'));

		return $targetMonth->setDate(
			(int) $targetMonth->format('Y'),
			(int) $targetMonth->format('m'),
			$targetDay
		)->format('Y-m-d');
	}

	/** @return array<string,mixed> */
	private function getValidatedConfiguration()
	{
		$sourceField = trim(getDolGlobalString('WARRANTYPERIOD_PRODUCT_FIELD'));
		$targetField = trim(getDolGlobalString('WARRANTYPERIOD_TARGET_FIELD'));

		if ($sourceField === '' || $targetField === '') {
			return array('ok' => true, 'status' => 'unconfigured');
		}

		if (!$this->isSafeFieldName($sourceField) || $this->getExtraFieldDefinition('product', $sourceField, array('int')) === null) {
			return array('ok' => true, 'status' => 'source_invalid', 'field' => $sourceField);
		}

		if (!$this->isSafeFieldName($targetField) || $this->getExtraFieldDefinition('expeditiondet', $targetField, array('date')) === null) {
			return array('ok' => true, 'status' => 'target_invalid', 'field' => $targetField);
		}

		return array(
			'ok' => true,
			'status' => 'configured',
			'source_field' => $sourceField,
			'target_field' => $targetField,
		);
	}

	/**
	 * @param int $lineId Shipment line id
	 * @param int $productId Product id
	 * @param string|null $startDate Shipment date as Y-m-d
	 * @param string $sourceField Validated Product extrafield column
	 * @param string $targetField Validated shipment-line extrafield column
	 * @return array<string,mixed>
	 */
	private function synchronizeLineData($lineId, $productId, $startDate, $sourceField, $targetField)
	{
		$months = $this->getProductWarrantyMonths($productId, $sourceField);
		if ($months === null || $months <= 0 || $startDate === null) {
			if (!$this->persistLineExpiration($lineId, $targetField, null)) {
				return array('ok' => false, 'status' => 'db_error');
			}
			return array('ok' => true, 'status' => 'cleared');
		}

		$expiration = self::addMonthsClamped($startDate, $months);
		if ($expiration === null) {
			$this->error = 'Unable to calculate warranty expiration date.';
			return array('ok' => false, 'status' => 'calculation_error');
		}

		if (!$this->persistLineExpiration($lineId, $targetField, $expiration)) {
			return array('ok' => false, 'status' => 'db_error');
		}

		return array('ok' => true, 'status' => 'updated', 'months' => $months, 'expiration' => $expiration);
	}

	/** @return int|null */
	private function getProductWarrantyMonths($productId, $sourceField)
	{
		if ($productId <= 0) {
			return null;
		}

		$sql = "SELECT pe.".$sourceField." AS warranty_months";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_extrafields AS pe";
		$sql .= " WHERE pe.fk_object = ".((int) $productId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj || !is_numeric($obj->warranty_months)) {
			return null;
		}

		$months = (int) $obj->warranty_months;
		return $months > 0 ? $months : null;
	}

	/** @return string|null */
	private function fetchShipmentDate($shipmentId)
	{
		if ($shipmentId <= 0) {
			return null;
		}

		$sql = "SELECT date_expedition FROM ".MAIN_DB_PREFIX."expedition";
		$sql .= " WHERE rowid = ".((int) $shipmentId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}

		$obj = $this->db->fetch_object($resql);
		return $obj ? $this->normalizeDateValue($obj->date_expedition) : null;
	}

	/** @return string|null */
	private function normalizeShipmentDate($object)
	{
		$value = null;
		if (!empty($object->date_shipping)) {
			$value = $object->date_shipping;
		} elseif (!empty($object->date_expedition)) {
			$value = $object->date_expedition;
		}
		return $this->normalizeDateValue($value);
	}

	/** @return string|null */
	private function normalizeDateValue($value)
	{
		if (empty($value)) {
			return null;
		}

		if (is_numeric($value)) {
			$timestamp = (int) $value;
			if ($timestamp <= 0) {
				return null;
			}
			return dol_print_date($timestamp, '%Y-%m-%d', 'tzserver');
		}

		$stringValue = trim((string) $value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $stringValue)) {
			return substr($stringValue, 0, 10);
		}

		$timestamp = strtotime($stringValue);
		if ($timestamp === false) {
			return null;
		}
		return dol_print_date($timestamp, '%Y-%m-%d', 'tzserver');
	}

	/**
	 * Persist directly without opening/committing a nested DB transaction.
	 *
	 * @return bool
	 */
	private function persistLineExpiration($lineId, $targetField, $expiration)
	{
		$table = MAIN_DB_PREFIX.'expeditiondet_extrafields';
		$sql = "SELECT rowid FROM ".$table." WHERE fk_object = ".((int) $lineId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$obj = $this->db->fetch_object($resql);
		$sqlValue = $expiration === null ? 'NULL' : "'".$this->db->escape($expiration)."'";
		if ($obj) {
			$sql = "UPDATE ".$table." SET ".$targetField." = ".$sqlValue;
			$sql .= " WHERE fk_object = ".((int) $lineId);
		} else {
			$sql = "INSERT INTO ".$table." (fk_object, ".$targetField.")";
			$sql .= " VALUES (".((int) $lineId).", ".$sqlValue.")";
		}

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return false;
		}
		return true;
	}

	/** @return array<string,array{label:string,type:string}> */
	private function getExtraFields($elementType, array $types)
	{
		$fields = array();
		if (empty($types)) {
			return $fields;
		}

		$typeSql = array();
		foreach ($types as $type) {
			$typeSql[] = "'".$this->db->escape($type)."'";
		}

		$sql = "SELECT name, label, type, entity FROM ".MAIN_DB_PREFIX."extrafields";
		$sql .= " WHERE elementtype = '".$this->db->escape($elementType)."'";
		$sql .= " AND type IN (".implode(',', $typeSql).")";
		$sql .= " AND entity IN (0, ".((int) $this->entity).")";
		$sql .= " ORDER BY entity ASC, pos ASC, label ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $fields;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$fields[$obj->name] = array('label' => (string) $obj->label, 'type' => (string) $obj->type);
		}
		return $fields;
	}

	/** @return array{label:string,type:string}|null */
	private function getExtraFieldDefinition($elementType, $fieldName, $allowedTypes = null)
	{
		if (!$this->isSafeFieldName($fieldName)) {
			return null;
		}

		$sql = "SELECT label, type, entity FROM ".MAIN_DB_PREFIX."extrafields";
		$sql .= " WHERE elementtype = '".$this->db->escape($elementType)."'";
		$sql .= " AND name = '".$this->db->escape($fieldName)."'";
		$sql .= " AND entity IN (0, ".((int) $this->entity).")";
		$sql .= " ORDER BY entity DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj || (is_array($allowedTypes) && !in_array((string) $obj->type, $allowedTypes, true))) {
			return null;
		}
		return array('label' => (string) $obj->label, 'type' => (string) $obj->type);
	}

	/** @return bool */
	private function isSafeFieldName($fieldName)
	{
		return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string) $fieldName);
	}
}
