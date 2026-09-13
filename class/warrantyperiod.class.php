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
 * \brief Warranty-period calculation and shipment extrafield synchronization.
 */

class WarrantyPeriodManager
{
	public const DEFAULT_TARGET_FIELD = 'warranty_expiration';
	public const POLICY_BLANK = 'blank';
	public const POLICY_SHORTEST = 'shortest';
	public const POLICY_LONGEST = 'longest';

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
	 * Return product integer extrafields that can be used as warranty months.
	 *
	 * @return array<string,array{label:string,type:string}>
	 */
	public function getProductMonthFields()
	{
		return $this->getExtraFields('product', array('int'));
	}

	/**
	 * Return shipment date extrafields that can receive the expiration date.
	 *
	 * @return array<string,array{label:string,type:string}>
	 */
	public function getShipmentDateFields()
	{
		return $this->getExtraFields('expedition', array('date'));
	}

	/**
	 * Create the module's default shipment expiration field if it does not exist.
	 *
	 * The field is deliberately created only on explicit setup action so an
	 * installation that already has its own shipment warranty field does not get
	 * a duplicate field merely by enabling the module.
	 *
	 * @return int >0 on success, <0 on error
	 */
	public function ensureDefaultTargetField()
	{
		$existing = $this->getExtraFieldDefinition('expedition', self::DEFAULT_TARGET_FIELD);
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
			'expedition',
			0,
			0,
			'',
			'',
			0,
			'',
			'3',
			'WarrantyExpirationHelp',
			'',
			(string) $this->entity,
			'warrantyperiod@warrantyperiod',
			'1',
			0,
			1
		);

		if ($result <= 0) {
			$this->error = $extrafields->error ?: 'Failed to create shipment warranty expiration extrafield.';
			return -1;
		}

		return 1;
	}

	/**
	 * Recalculate and persist the shipment warranty expiration.
	 *
	 * Blank/zero product values do not participate in the calculation. When
	 * several different positive warranty periods are present, the configured
	 * mixed policy decides whether the result is blank, shortest, or longest.
	 *
	 * @param CommonObject $object Shipment object
	 * @return array<string,mixed>
	 */
	public function synchronizeShipment($object)
	{
		$sourceField = trim(getDolGlobalString('WARRANTYPERIOD_PRODUCT_FIELD'));
		$targetField = trim(getDolGlobalString('WARRANTYPERIOD_TARGET_FIELD'));
		$policy = getDolGlobalString('WARRANTYPERIOD_MIXED_POLICY', self::POLICY_BLANK);

		if ($sourceField === '' || $targetField === '') {
			return array('ok' => true, 'status' => 'unconfigured');
		}

		if (!$this->isSafeFieldName($sourceField) || $this->getExtraFieldDefinition('product', $sourceField, array('int')) === null) {
			return array('ok' => true, 'status' => 'source_invalid', 'field' => $sourceField);
		}

		if (!$this->isSafeFieldName($targetField) || $this->getExtraFieldDefinition('expedition', $targetField, array('date')) === null) {
			return array('ok' => true, 'status' => 'target_invalid', 'field' => $targetField);
		}

		if (!in_array($policy, array(self::POLICY_BLANK, self::POLICY_SHORTEST, self::POLICY_LONGEST), true)) {
			$policy = self::POLICY_BLANK;
		}

		$startDate = $this->normalizeShipmentDate($object);
		if ($startDate === null) {
			if (!$this->persistExpiration((int) $object->id, $targetField, null)) {
				return array('ok' => false, 'status' => 'db_error');
			}
			$this->updateObjectOptionCache($object, $targetField, null);
			return array('ok' => true, 'status' => 'no_shipping_date');
		}

		$durations = $this->getShipmentWarrantyDurations((int) $object->id, $sourceField);
		if ($durations === null) {
			return array('ok' => false, 'status' => 'db_error');
		}

		if (empty($durations)) {
			if (!$this->persistExpiration((int) $object->id, $targetField, null)) {
				return array('ok' => false, 'status' => 'db_error');
			}
			$this->updateObjectOptionCache($object, $targetField, null);
			return array('ok' => true, 'status' => 'no_warranty');
		}

		sort($durations, SORT_NUMERIC);

		if (count($durations) > 1 && $policy === self::POLICY_BLANK) {
			if (!$this->persistExpiration((int) $object->id, $targetField, null)) {
				return array('ok' => false, 'status' => 'db_error');
			}
			$this->updateObjectOptionCache($object, $targetField, null);
			return array(
				'ok' => true,
				'status' => 'mixed',
				'durations' => $durations,
			);
		}

		if ($policy === self::POLICY_LONGEST) {
			$months = max($durations);
		} else {
			// Single duration and "shortest" both resolve to the minimum.
			$months = min($durations);
		}

		$expiration = self::addMonthsClamped($startDate, $months);
		if ($expiration === null) {
			$this->error = 'Unable to calculate warranty expiration date.';
			return array('ok' => false, 'status' => 'calculation_error');
		}

		if (!$this->persistExpiration((int) $object->id, $targetField, $expiration)) {
			return array('ok' => false, 'status' => 'db_error');
		}
		$this->updateObjectOptionCache($object, $targetField, $expiration);

		return array(
			'ok' => true,
			'status' => count($durations) > 1 ? 'mixed_resolved' : 'updated',
			'months' => $months,
			'expiration' => $expiration,
			'durations' => $durations,
		);
	}

	/**
	 * Add whole calendar months while clamping the day to the last valid day.
	 *
	 * Examples:
	 * 2024-01-31 + 1 month = 2024-02-29
	 * 2025-01-31 + 1 month = 2025-02-28
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

	/**
	 * @param string $elementType product|expedition
	 * @param string[] $types Allowed extrafield types
	 * @return array<string,array{label:string,type:string}>
	 */
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

		$sql = "SELECT name, label, type, entity";
		$sql .= " FROM ".MAIN_DB_PREFIX."extrafields";
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
			// Current-entity definitions override a shared (entity=0) definition
			// of the same field name if both happen to be present.
			$fields[$obj->name] = array(
				'label' => (string) $obj->label,
				'type' => (string) $obj->type,
			);
		}

		return $fields;
	}

	/**
	 * @param string $elementType
	 * @param string $fieldName
	 * @param string[]|null $allowedTypes
	 * @return array{label:string,type:string}|null
	 */
	private function getExtraFieldDefinition($elementType, $fieldName, $allowedTypes = null)
	{
		if (!$this->isSafeFieldName($fieldName)) {
			return null;
		}

		$sql = "SELECT label, type, entity";
		$sql .= " FROM ".MAIN_DB_PREFIX."extrafields";
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
		if (!$obj) {
			return null;
		}

		if (is_array($allowedTypes) && !in_array((string) $obj->type, $allowedTypes, true)) {
			return null;
		}

		return array(
			'label' => (string) $obj->label,
			'type' => (string) $obj->type,
		);
	}

	/**
	 * Read unique positive warranty-month values from actual shipment lines.
	 *
	 * @param int $shipmentId
	 * @param string $sourceField Validated product extrafield column
	 * @return int[]|null null on DB error
	 */
	private function getShipmentWarrantyDurations($shipmentId, $sourceField)
	{
		$durations = array();

		$sql = "SELECT DISTINCT pe.".$sourceField." AS warranty_months";
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet AS ed";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_extrafields AS pe ON pe.fk_object = ed.fk_product";
		$sql .= " WHERE ed.fk_expedition = ".((int) $shipmentId);
		$sql .= " AND ed.fk_product > 0";
		$sql .= " AND ed.qty > 0";
		$sql .= " AND pe.".$sourceField." IS NOT NULL";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			if (!is_numeric($obj->warranty_months)) {
				continue;
			}
			$months = (int) $obj->warranty_months;
			if ($months > 0) {
				$durations[$months] = $months;
			}
		}

		return array_values($durations);
	}

	/**
	 * @param CommonObject $object Shipment object
	 * @return string|null Y-m-d
	 */
	private function normalizeShipmentDate($object)
	{
		$value = null;
		if (!empty($object->date_shipping)) {
			$value = $object->date_shipping;
		} elseif (!empty($object->date_expedition)) {
			$value = $object->date_expedition;
		}

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
	 * SHIPPING_CREATE is fired inside Expedition::create()'s transaction, so
	 * CommonObject::updateExtraField() must not be used here: it manages its own
	 * transaction and could disturb the caller's atomicity.
	 *
	 * @param int $shipmentId
	 * @param string $targetField Validated expedition extrafield column
	 * @param string|null $expiration Y-m-d or null
	 * @return bool
	 */
	private function persistExpiration($shipmentId, $targetField, $expiration)
	{
		$table = MAIN_DB_PREFIX.'expedition_extrafields';

		$sql = "SELECT rowid FROM ".$table." WHERE fk_object = ".((int) $shipmentId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$obj = $this->db->fetch_object($resql);
		$sqlValue = $expiration === null ? 'NULL' : "'".$this->db->escape($expiration)."'";

		if ($obj) {
			$sql = "UPDATE ".$table;
			$sql .= " SET ".$targetField." = ".$sqlValue;
			$sql .= " WHERE fk_object = ".((int) $shipmentId);
		} else {
			$sql = "INSERT INTO ".$table." (fk_object, ".$targetField.")";
			$sql .= " VALUES (".((int) $shipmentId).", ".$sqlValue.")";
		}

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return false;
		}

		return true;
	}

	/**
	 * Keep the current PHP object coherent for code that uses it after trigger.
	 *
	 * @param CommonObject $object
	 * @param string $targetField
	 * @param string|null $expiration
	 * @return void
	 */
	private function updateObjectOptionCache($object, $targetField, $expiration)
	{
		if (!isset($object->array_options) || !is_array($object->array_options)) {
			$object->array_options = array();
		}

		$object->array_options['options_'.$targetField] = $expiration === null
			? null
			: strtotime($expiration.' 12:00:00');
	}

	/**
	 * @param string $fieldName
	 * @return bool
	 */
	private function isSafeFieldName($fieldName)
	{
		return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string) $fieldName);
	}
}
