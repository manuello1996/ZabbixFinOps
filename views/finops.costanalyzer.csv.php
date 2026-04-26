<?php declare(strict_types = 0);
/**
 * Zabbix FinOps Toolkit - CSV Export View
 *
 * @var CView $this
 * @var array $data
 */

function finops_csv_escape($value): string {
	return '"'.str_replace('"', '""', (string) $value).'"';
}

function finops_csv_row(array $values): string {
	return implode(',', $values)."\n";
}

// BOM for UTF-8 compatibility with Excel.
echo "\xEF\xBB\xBF";

// Header row.
echo finops_csv_row([
	'"Host"',
	'"Host Group"',
	'"CPU Avg %"',
	'"CPU Max %"',
	'"RAM Avg %"',
	'"RAM Max %"',
	'"Disk Avg %"',
	'"Net In Avg (bytes/s)"',
	'"Net Out Avg (bytes/s)"',
	'"Load Avg"',
	'"Waste Score"',
	'"Waste Level"',
	'"Efficiency Score"',
	'"Efficiency Level"',
	'"CPU P95 %"',
	'"RAM P95 %"',
	'"vCPUs"',
	'"vCPU Recommended"',
	'"RAM GB"',
	'"RAM Recommended GB"',
	'"CPU Trend"',
	'"RAM Trend"',
	'"Recommendation"'
]);

foreach ($data['results'] as $r) {
	$waste_level = '';
	if ($r['waste_score'] !== null) {
		if ($r['waste_score'] >= 80) $waste_level = 'HIGH';
		elseif ($r['waste_score'] >= 60) $waste_level = 'MEDIUM';
		elseif ($r['waste_score'] >= 40) $waste_level = 'LOW';
		else $waste_level = 'HEALTHY';
	}

	$eff_level = '';
	if ($r['efficiency_score'] !== null) {
		if ($r['efficiency_score'] >= 70) $eff_level = 'Healthy';
		elseif ($r['efficiency_score'] >= 40) $eff_level = 'Can be optimized';
		else $eff_level = 'High waste';
	}

	echo finops_csv_row([
		finops_csv_escape($r['host_name']),
		finops_csv_escape($r['host_groups'] ?? ''),
		($r['cpu_avg'] !== null) ? $r['cpu_avg'] : '',
		($r['cpu_max'] !== null) ? $r['cpu_max'] : '',
		($r['ram_avg'] !== null) ? $r['ram_avg'] : '',
		($r['ram_max'] !== null) ? $r['ram_max'] : '',
		($r['disk_avg'] !== null) ? $r['disk_avg'] : '',
		($r['net_in_avg'] !== null) ? $r['net_in_avg'] : '',
		($r['net_out_avg'] !== null) ? $r['net_out_avg'] : '',
		($r['load_avg'] !== null) ? $r['load_avg'] : '',
		($r['waste_score'] !== null) ? $r['waste_score'] : '',
		'"'.$waste_level.'"',
		($r['efficiency_score'] !== null) ? $r['efficiency_score'] : '',
		'"'.$eff_level.'"',
		($r['cpu_p95'] !== null) ? $r['cpu_p95'] : '',
		($r['ram_p95'] !== null) ? $r['ram_p95'] : '',
		($r['cpu_count'] !== null) ? $r['cpu_count'] : '',
		($r['cpu_recommended'] !== null) ? $r['cpu_recommended'] : '',
		($r['ram_total_gb'] !== null) ? $r['ram_total_gb'] : '',
		($r['ram_recommended_gb'] !== null) ? $r['ram_recommended_gb'] : '',
		($r['cpu_trend'] !== null) ? $r['cpu_trend'] : '',
		($r['ram_trend'] !== null) ? $r['ram_trend'] : '',
		finops_csv_escape($r['recommendation'])
	]);
}
