<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Keep shipment-line warranty expiration synchronized with shipment contents/date.
 */
class InterfaceWarrantyPeriodTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'technic';
		$this->description = 'Warranty period calculation for shipment lines';
		$this->version = self::VERSIONS['dev'];
		$this->picto = 'calendar';
	}

	/**
	 * @param string $action Trigger action
	 * @param CommonObject $object Business object
	 * @param User $user User
	 * @param Translate $langs Translation handler
	 * @param Conf $conf Configuration
	 * @return int <0 on blocking error, 0 if ignored, >0 if handled
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (empty($conf->warrantyperiod) || empty($conf->warrantyperiod->enabled)) {
			return 0;
		}

		$shipmentActions = array('SHIPPING_CREATE', 'SHIPPING_MODIFY', 'SHIPPING_VALIDATE');
		$lineActions = array('LINESHIPPING_INSERT', 'LINESHIPPING_MODIFY');
		if (!in_array($action, $shipmentActions, true) && !in_array($action, $lineActions, true)) {
			return 0;
		}
		if (empty($object->id)) {
			return 0;
		}

		$langs->load('warrantyperiod@warrantyperiod');
		dol_include_once('/warrantyperiod/class/warrantyperiod.class.php');

		$entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
		$manager = new WarrantyPeriodManager($this->db, $entity);
		$isLineAction = in_array($action, $lineActions, true);
		$result = $isLineAction
			? $manager->synchronizeShipmentLine($object)
			: $manager->synchronizeShipment($object);

		if (empty($result['ok'])) {
			$this->error = $manager->error ?: $langs->trans('WarrantyPeriodCalculationFailed');
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}

		if ($result['status'] === 'source_invalid') {
			setEventMessages($langs->trans('WarrantySourceFieldInvalid', $result['field']), null, 'warnings');
		} elseif ($result['status'] === 'target_invalid') {
			setEventMessages($langs->trans('WarrantyTargetFieldInvalid', $result['field']), null, 'warnings');
		}

		// Warranty applies only to physical products. The generic Shipment-line
		// extrafield exists on service rows too, so explicitly clear any value that
		// may have been entered or calculated there. This also cleans legacy values
		// on the next create/modify/validate synchronization.
		if (!in_array($result['status'], array('unconfigured', 'source_invalid', 'target_invalid'), true)) {
			$targetField = trim(getDolGlobalString('WARRANTYPERIOD_TARGET_FIELD'));
			if ($this->isSafeFieldName($targetField)) {
				$cleared = $isLineAction
					? $this->clearServiceLineExpiration((int) $object->id, $targetField)
					: $this->clearShipmentServiceExpirations((int) $object->id, $targetField);
				if (!$cleared) {
					$this->error = $this->db->lasterror();
				dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
					return -1;
				}
			}
		}

		return 1;
	}

	/** @return bool */
	private function clearServiceLineExpiration($lineId, $targetField)
	{
		if ($lineId <= 0) {
			return true;
		}

		$sql = 'UPDATE '.MAIN_DB_PREFIX.'expeditiondet_extrafields';
		$sql .= ' SET '.$targetField.' = NULL';
		$sql .= ' WHERE fk_object IN (';
		$sql .= 'SELECT ed.rowid FROM '.MAIN_DB_PREFIX.'expeditiondet AS ed';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = ed.fk_product';
		$sql .= ' WHERE ed.rowid = '.$lineId.' AND p.fk_product_type = 1';
		$sql .= ')';

		return (bool) $this->db->query($sql);
	}

	/** @return bool */
	private function clearShipmentServiceExpirations($shipmentId, $targetField)
	{
		if ($shipmentId <= 0) {
			return true;
		}

		$sql = 'UPDATE '.MAIN_DB_PREFIX.'expeditiondet_extrafields';
		$sql .= ' SET '.$targetField.' = NULL';
		$sql .= ' WHERE fk_object IN (';
		$sql .= 'SELECT ed.rowid FROM '.MAIN_DB_PREFIX.'expeditiondet AS ed';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = ed.fk_product';
		$sql .= ' WHERE ed.fk_expedition = '.$shipmentId.' AND p.fk_product_type = 1';
		$sql .= ')';

		return (bool) $this->db->query($sql);
	}

	/** @return bool */
	private function isSafeFieldName($fieldName)
	{
		return $fieldName !== '' && (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $fieldName);
	}
}
