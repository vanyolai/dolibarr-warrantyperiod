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
 * Keep shipment warranty expiration synchronized with shipment contents/date.
 */
class InterfaceWarrantyPeriodTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'technic';
		$this->description = 'Warranty period calculation for shipments';
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

		if (!in_array($action, array('SHIPPING_CREATE', 'SHIPPING_MODIFY', 'SHIPPING_VALIDATE'), true)) {
			return 0;
		}

		if (empty($object->id)) {
			return 0;
		}

		$langs->load('warrantyperiod@warrantyperiod');
		dol_include_once('/warrantyperiod/class/warrantyperiod.class.php');

		$entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
		$manager = new WarrantyPeriodManager($this->db, $entity);
		$result = $manager->synchronizeShipment($object);

		if (empty($result['ok'])) {
			$this->error = $manager->error ?: $langs->trans('WarrantyPeriodCalculationFailed');
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}

		if ($result['status'] === 'mixed') {
			$durations = implode(', ', array_map('intval', $result['durations']));
			setEventMessages(
				$langs->trans('WarrantyMixedWarning', $durations),
				null,
				'warnings'
			);
		} elseif ($result['status'] === 'source_invalid') {
			setEventMessages(
				$langs->trans('WarrantySourceFieldInvalid', $result['field']),
				null,
				'warnings'
			);
		} elseif ($result['status'] === 'target_invalid') {
			setEventMessages(
				$langs->trans('WarrantyTargetFieldInvalid', $result['field']),
				null,
				'warnings'
			);
		}

		return 1;
	}
}
