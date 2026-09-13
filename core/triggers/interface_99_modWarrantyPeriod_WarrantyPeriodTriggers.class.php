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
		$result = in_array($action, $lineActions, true)
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

		return 1;
	}
}
