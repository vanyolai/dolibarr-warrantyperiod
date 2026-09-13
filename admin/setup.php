<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

$res = 0;
foreach (array(__DIR__.'/../../main.inc.php', __DIR__.'/../../../main.inc.php') as $main) {
	if (!$res && file_exists($main)) {
		$res = @include $main;
	}
}
if (!$res) {
	die('Failed to include Dolibarr main.inc.php');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/warrantyperiod/class/warrantyperiod.class.php');

$langs->loadLangs(array('admin', 'warrantyperiod@warrantyperiod'));

if (!$user->admin) {
	accessforbidden();
}

$manager = new WarrantyPeriodManager($db, (int) $conf->entity);
$action = GETPOST('action', 'aZ09');

if ($action === 'create_default_target') {
	$result = $manager->ensureDefaultTargetField();
	if ($result > 0) {
		dolibarr_set_const($db, 'WARRANTYPERIOD_TARGET_FIELD', WarrantyPeriodManager::DEFAULT_TARGET_FIELD, 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans('WarrantyDefaultTargetCreated'), null, 'mesgs');
	} else {
		setEventMessages(null, array($manager->error), 'errors');
	}
}

$productFields = $manager->getProductMonthFields();
$shipmentLineFields = $manager->getShipmentLineDateFields();

$formSourceField = $action === 'save' ? trim(GETPOST('product_field', 'alphanohtml')) : getDolGlobalString('WARRANTYPERIOD_PRODUCT_FIELD');
$formTargetField = $action === 'save' ? trim(GETPOST('target_field', 'alphanohtml')) : getDolGlobalString('WARRANTYPERIOD_TARGET_FIELD');

if ($action === 'save') {
	$errors = array();
	if ($formSourceField === '' || !isset($productFields[$formSourceField])) {
		$errors[] = $langs->trans('WarrantySelectValidSourceField');
	}
	if ($formTargetField === '' || !isset($shipmentLineFields[$formTargetField])) {
		$errors[] = $langs->trans('WarrantySelectValidTargetField');
	}

	if (empty($errors)) {
		dolibarr_set_const($db, 'WARRANTYPERIOD_PRODUCT_FIELD', $formSourceField, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'WARRANTYPERIOD_TARGET_FIELD', $formTargetField, 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans('SettingsSaved'), null, 'mesgs');
	} else {
		setEventMessages(null, $errors, 'errors');
	}
}

llxHeader('', $langs->trans('WarrantyPeriodSetup'));
print load_fiche_titre($langs->trans('WarrantyPeriodSetup'), '', 'title_setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('WarrantyPeriodConfiguration').'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('WarrantyProductField').'</td><td>';
print '<select name="product_field" class="minwidth300">';
print '<option value="">'.$langs->trans('Select').'</option>';
foreach ($productFields as $name => $definition) {
	$selected = $formSourceField === $name ? ' selected' : '';
	$label = $langs->trans($definition['label']);
	print '<option value="'.dol_escape_htmltag($name).'"'.$selected.'>'.dol_escape_htmltag($label.' ('.$name.')').'</option>';
}
print '</select> <span class="opacitymedium">'.$langs->trans('WarrantyProductFieldHelp').'</span></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('WarrantyStartDate').'</td><td>'.$langs->trans('WarrantyShippingDateFixed').'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('WarrantyTargetField').'</td><td>';
print '<select name="target_field" class="minwidth300">';
print '<option value="">'.$langs->trans('Select').'</option>';
foreach ($shipmentLineFields as $name => $definition) {
	$selected = $formTargetField === $name ? ' selected' : '';
	$label = $langs->trans($definition['label']);
	print '<option value="'.dol_escape_htmltag($name).'"'.$selected.'>'.dol_escape_htmltag($label.' ('.$name.')').'</option>';
}
print '</select> <span class="opacitymedium">'.$langs->trans('WarrantyTargetFieldHelp').'</span></td></tr>';
print '</table>';

if (empty($productFields)) {
	print '<div class="warning">'.$langs->trans('WarrantyNoProductFields').'</div>';
}
if (empty($shipmentLineFields)) {
	print '<div class="warning">'.$langs->trans('WarrantyNoShipmentLineFields').'</div>';
}

print '<div class="center"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button></div>';
print '</form>';

$defaultTargetExists = isset($shipmentLineFields[WarrantyPeriodManager::DEFAULT_TARGET_FIELD]);
if (!$defaultTargetExists) {
	print '<br>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="create_default_target">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('WarrantyDefaultTargetTitle').'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('WarrantyDefaultTargetDescription', WarrantyPeriodManager::DEFAULT_TARGET_FIELD);
	print ' <button class="button" type="submit">'.$langs->trans('WarrantyCreateDefaultTarget').'</button></td></tr>';
	print '</table></form>';
}

llxFooter();
$db->close();
