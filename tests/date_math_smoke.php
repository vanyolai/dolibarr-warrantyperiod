<?php

require_once __DIR__.'/../class/warrantyperiod.class.php';

$cases = array(
	array('2024-01-31', 1, '2024-02-29'),
	array('2025-01-31', 1, '2025-02-28'),
	array('2026-09-13', 24, '2028-09-13'),
	array('2024-02-29', 12, '2025-02-28'),
	array('2024-12-31', 2, '2025-02-28'),
);

foreach ($cases as $case) {
	list($start, $months, $expected) = $case;
	$actual = WarrantyPeriodManager::addMonthsClamped($start, $months);
	if ($actual !== $expected) {
		fwrite(STDERR, sprintf(
			"FAIL: %s + %d months: expected %s, got %s\n",
			$start,
			$months,
			$expected,
			var_export($actual, true)
		));
		exit(1);
	}
}

echo "WarrantyPeriod date math smoke test passed.\n";
