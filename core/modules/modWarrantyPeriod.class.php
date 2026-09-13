<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modWarrantyPeriod extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 581100;
		$this->rights_class = 'warrantyperiod';
		$this->family = 'products';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleWarrantyPeriodDesc';
		$this->descriptionlong = 'ModuleWarrantyPeriodDescLong';
		$this->editor_name = 'Krisztian Vanyolai';
		$this->editor_url = 'https://github.com/vanyolai/dolibarr-warrantyperiod';
		$this->version = '0.2.2';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'calendar';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array('expeditioncard'),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@warrantyperiod');
		$this->hidden = false;
		$this->depends = array('modExpedition');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('warrantyperiod@warrantyperiod');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(23, 0);
		$this->need_javascript_ajax = 0;

		$this->const = array(
			0 => array('WARRANTYPERIOD_PRODUCT_FIELD', 'chaine', '', 'Product extrafield containing warranty months', 0, 'current', 0),
			1 => array('WARRANTYPERIOD_TARGET_FIELD', 'chaine', '', 'Shipment-line date extrafield receiving warranty expiration', 0, 'current', 0),
		);

		if (!isModEnabled('warrantyperiod')) {
			$conf->warrantyperiod = new stdClass();
			$conf->warrantyperiod->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();
	}

	public function init($options = '')
	{
		$sql = array();
		return $this->_init($sql, $options);
	}

	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
