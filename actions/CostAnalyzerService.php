<?php declare(strict_types = 0);

namespace Modules\ZabbixFinOpsToolkit\Actions;

use API;

class CostAnalyzerService {

	private const CPU_AVG_THRESHOLD   = 20;
	private const RAM_AVG_THRESHOLD   = 40;
	private const CPU_MAX_THRESHOLD   = 60;
	private const RAM_MAX_THRESHOLD   = 80;
	private const DISK_HIGH_THRESHOLD = 85;
	private const RIGHT_SIZE_FACTOR   = 0.80;

	private const ANALYSIS_DAYS = 30;
	private const MIN_TREND_HOURS = 24;
	private const SQL_ITEM_CHUNK_SIZE = 500;
	private const EXPORT_HOST_CHUNK_SIZE = 100;

	private const ITEM_KEY_CPU        = 'system.cpu.util';
	private const ITEM_KEY_RAM_UTIL   = 'vm.memory.utilization';
	private const ITEM_KEY_RAM_PAVAIL = 'vm.memory.size[pavailable]';
	private const ITEM_KEY_DISK       = 'vfs.fs.size[';
	private const ITEM_KEY_NETIN      = 'net.if.in';
	private const ITEM_KEY_NETOUT     = 'net.if.out';
	private const ITEM_KEY_LOAD       = 'system.cpu.load';
	private const ITEM_KEY_CPU_NUM    = 'system.cpu.num';
	private const ITEM_KEY_RAM_TOTAL  = 'vm.memory.size[total]';

	public function analyze(array $filters = [], string $sort = 'waste_score', string $sortorder = 'DESC'): array {
		$filters = $this->normalizeFilters($filters);
		if (!$this->hasAnalysisScope($filters)) {
			return [
				'results' => [],
				'summary' => $this->buildSummary([])
			];
		}

		$hosts = $this->getHosts($filters);
		$results = $this->analyzeHosts($hosts, $sort, $sortorder);

		return [
			'results' => $results,
			'summary' => $this->buildSummary($results)
		];
	}

	public function analyzePage(array $filters = [], string $sort = 'waste_score', string $sortorder = 'DESC',
			int $page = 1, int $page_size = 50): array {
		$filters = $this->normalizeFilters($filters);
		$page_size = max(1, $page_size);

		if (!$this->hasAnalysisScope($filters)) {
			return [
				'results' => [],
				'summary' => $this->buildSummary([]),
				'total_hosts' => 0,
				'page' => 1,
				'page_count' => 1,
				'page_size' => $page_size
			];
		}

		$hosts = $this->getHosts($filters, false);
		$total_hosts = count($hosts);
		$page_count = max(1, (int) ceil($total_hosts / $page_size));
		$page = max(1, min($page, $page_count));
		$offset = ($page - 1) * $page_size;
		$page_hosts = array_slice($hosts, $offset, $page_size, true);

		if ($page_hosts) {
			$page_filters = $filters;
			$page_filters['hostids'] = array_keys($page_hosts);
			$page_hosts = $this->getHosts($page_filters);
		}

		$page_host_items = $this->getHostItems(array_keys($page_hosts));
		$page_hosts = array_filter($page_hosts, function ($hostid) use ($page_host_items) {
			return isset($page_host_items[$hostid]);
		}, ARRAY_FILTER_USE_KEY);
		$results = $this->analyzeHosts($page_hosts, $sort, $sortorder, $page_host_items);

		return [
			'results' => $results,
			'summary' => $this->buildSummary($results),
			'total_hosts' => $total_hosts,
			'page' => $page,
			'page_count' => $page_count,
			'page_size' => $page_size
		];
	}

	public function analyzeForExport(array $filters = [], int $chunk_size = self::EXPORT_HOST_CHUNK_SIZE): \Generator {
		$filters = $this->normalizeFilters($filters);
		if (!$this->hasAnalysisScope($filters)) {
			return;
		}

		$hosts = $this->getHosts($filters);

		foreach (array_chunk($hosts, max(1, $chunk_size), true) as $host_chunk) {
			foreach ($this->analyzeHosts($host_chunk, 'host_name', 'ASC') as $result) {
				yield $result;
			}
		}
	}

	private function normalizeFilters(array $filters): array {
		$filters += [
			'groupids' => [],
			'hostids' => [],
			'status' => -1,
			'show_maintenance' => HOST_MAINTENANCE_STATUS_ON,
			'evaltype' => TAG_EVAL_TYPE_AND_OR,
			'tags' => []
		];

		$filters['groupids'] = array_values((array) $filters['groupids']);
		$filters['hostids'] = array_values(array_filter((array) $filters['hostids'], static function ($hostid): bool {
			return (string) $hostid !== '';
		}));
		$filters['status'] = (int) $filters['status'];
		$filters['show_maintenance'] = (int) $filters['show_maintenance'];
		$filters['evaltype'] = (int) $filters['evaltype'];
		$filters['tags'] = (array) $filters['tags'];

		return $filters;
	}

	private function hasAnalysisScope(array $filters): bool {
		return !empty($filters['groupids'])
			|| !empty($filters['hostids'])
			|| !empty($this->normalizeTags($filters['tags']));
	}

	private function analyzeHosts(array $hosts, string $sort, string $sortorder, ?array $host_items = null): array {
		$now = time();
		$time_from = $now - (self::ANALYSIS_DAYS * 86400);

		$first_week_start = $time_from;
		$first_week_end = $time_from + (7 * 86400);
		$last_week_start = $now - (7 * 86400);
		$last_week_end = $now;

		if (empty($hosts)) {
			return [];
		}

		$host_items = $host_items ?? $this->getHostItems(array_keys($hosts));
		$item_context = $this->buildItemContext($host_items);
		$itemids_by_table = $this->groupItemIdsByTable($item_context);
		$cpu_ram_itemids_by_table = $this->groupItemIdsByTable($this->filterItemContext($item_context, [
			self::ITEM_KEY_CPU,
			self::ITEM_KEY_RAM_UTIL,
			self::ITEM_KEY_RAM_PAVAIL
		]));

		$trend_data = $this->getTrendDataBatch($itemids_by_table, $time_from, $now);
		$p95_data = $this->getP95Batch($cpu_ram_itemids_by_table, $time_from, $now);
		$ram_inverted_p95 = $this->getInvertedRamP95Batch($host_items, $item_context, $time_from, $now);
		$trend_delta = $this->getTrendDeltaBatch(
			$cpu_ram_itemids_by_table, $first_week_start, $first_week_end, $last_week_start, $last_week_end
		);

		$results = [];

		foreach ($hosts as $hostid => $host) {
			if (!isset($host_items[$hostid])) {
				continue;
			}

			$hi = $host_items[$hostid];
			$group_names = array_column($host['hostgroups'], 'name');

			$result = [
				'hostid' => $hostid,
				'host_name' => $host['name'],
				'host_groups' => implode(', ', $group_names),
				'cpu_avg' => null,
				'cpu_max' => null,
				'cpu_p95' => null,
				'ram_avg' => null,
				'ram_max' => null,
				'ram_p95' => null,
				'disk_avg' => null,
				'net_in_avg' => null,
				'net_out_avg' => null,
				'load_avg' => null,
				'waste_score' => null,
				'efficiency_score' => null,
				'cpu_trend' => null,
				'ram_trend' => null,
				'recommendation' => '',
				'waste_level' => '',
				'efficiency_level' => '',
				'cpu_count' => null,
				'ram_total_gb' => null,
				'cpu_recommended' => null,
				'ram_recommended_gb' => null
			];

			if (isset($hi['cpu'])) {
				$itemid = $hi['cpu']['itemid'];
				$result['cpu_avg'] = $trend_data[$itemid]['avg'] ?? null;
				$result['cpu_max'] = $trend_data[$itemid]['max'] ?? null;
				$result['cpu_p95'] = $p95_data[$itemid] ?? null;
				$result['cpu_trend'] = $trend_delta[$itemid] ?? null;
			}

			if (isset($hi['ram'])) {
				$itemid = $hi['ram']['itemid'];
				$ram_inverted = !empty($hi['ram_inverted']);
				$ram_data = $trend_data[$itemid] ?? ['avg' => null, 'max' => null, 'min' => null];

				if ($ram_inverted && $ram_data['avg'] !== null) {
					$result['ram_avg'] = round(100 - $ram_data['avg'], 2);
					$result['ram_max'] = ($ram_data['min'] !== null) ? round(100 - $ram_data['min'], 2) : null;
					$result['ram_p95'] = $ram_inverted_p95[$itemid] ?? null;
				}
				else {
					$result['ram_avg'] = $ram_data['avg'];
					$result['ram_max'] = $ram_data['max'];
					$result['ram_p95'] = $p95_data[$itemid] ?? null;
				}

				foreach (['ram_avg', 'ram_max', 'ram_p95'] as $ram_key) {
					if ($result[$ram_key] !== null) {
						$result[$ram_key] = round(max(0, min(100, (float) $result[$ram_key])), 2);
					}
				}

				$ram_trend_raw = $trend_delta[$itemid] ?? null;
				$result['ram_trend'] = ($ram_inverted && $ram_trend_raw !== null)
					? round(-$ram_trend_raw, 2)
					: $ram_trend_raw;
			}

			$result['disk_avg'] = $this->maxAverage($hi['disk'] ?? [], $trend_data);

			$result['net_in_avg'] = $this->sumAverages($hi['net_in'] ?? [], $trend_data);
			$result['net_out_avg'] = $this->sumAverages($hi['net_out'] ?? [], $trend_data);

			if (isset($hi['load'])) {
				$itemid = $hi['load']['itemid'];
				$result['load_avg'] = $trend_data[$itemid]['avg'] ?? null;
			}

			if (isset($hi['cpu_num']) && !empty($hi['cpu_num']['lastvalue'])) {
				$result['cpu_count'] = (int) $hi['cpu_num']['lastvalue'];
			}
			if (isset($hi['ram_total']) && !empty($hi['ram_total']['lastvalue'])) {
				$result['ram_total_gb'] = round((float) $hi['ram_total']['lastvalue'] / 1073741824, 1);
			}

			if ($result['cpu_avg'] !== null && $result['ram_avg'] !== null) {
				$avg_usage = ($result['cpu_avg'] + $result['ram_avg']) / 2;
				$result['waste_score'] = max(0, min(100, round(100 - $avg_usage, 1)));
				$result['efficiency_score'] = max(0, min(100, round($avg_usage, 1)));
				$result['waste_level'] = $this->classifyWaste($result['waste_score']);
				$result['efficiency_level'] = $this->classifyEfficiency($result['efficiency_score']);
			}

			$result['recommendation'] = $this->generateRecommendation($result);
			$results[] = $this->calculateRightSizing($result);
		}

		$this->sortResults($results, $sort, $sortorder);

		return $results;
	}

	public function getHostGroups(array $groupids = []): array {
		$groupids = array_values(array_filter((array) $groupids, static function ($groupid): bool {
			return (string) $groupid !== '';
		}));

		if (empty($groupids)) {
			return [];
		}

		return API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'groupids' => $groupids,
			'preservekeys' => true
		]);
	}

	public function getHostsForMultiselect(array $hostids = []): array {
		$hostids = array_values(array_filter((array) $hostids, static function ($hostid): bool {
			return (string) $hostid !== '';
		}));

		if (empty($hostids)) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => $hostids,
			'preservekeys' => true
		]);

		$data = [];
		foreach ($hosts as $host) {
			$data[] = [
				'id' => $host['hostid'],
				'name' => $host['name']
			];
		}

		return $data;
	}

	private function getHosts(array $filters, bool $select_host_groups = true): array {
		$options = [
			'output' => ['hostid', 'host', 'name'],
			'filter' => [
				'status' => ($filters['status'] == -1)
					? [HOST_STATUS_MONITORED, HOST_STATUS_NOT_MONITORED]
					: (int) $filters['status']
			],
			'sortfield' => 'name',
			'sortorder' => ZBX_SORT_UP,
			'preservekeys' => true
		];

		if ($select_host_groups) {
			$options['selectHostGroups'] = ['groupid', 'name'];
		}

		if ((int) $filters['show_maintenance'] === HOST_MAINTENANCE_STATUS_OFF) {
			$options['filter']['maintenance_status'] = HOST_MAINTENANCE_STATUS_OFF;
		}

		if (!empty($filters['groupids'])) {
			$options['groupids'] = $filters['groupids'];
		}

		if (!empty($filters['hostids'])) {
			$options['hostids'] = $filters['hostids'];
		}

		$tags = $this->normalizeTags($filters['tags']);
		if ($tags) {
			$options['evaltype'] = (int) $filters['evaltype'];
			$options['tags'] = $tags;
		}

		return API::Host()->get($options);
	}

	private function getHostItems(array $hostids): array {
		if (empty($hostids)) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'value_type', 'units', 'lastvalue'],
			'hostids' => $hostids,
			'search' => [
				'key_' => [
					self::ITEM_KEY_CPU,
					self::ITEM_KEY_RAM_UTIL,
					self::ITEM_KEY_RAM_PAVAIL,
					self::ITEM_KEY_DISK,
					self::ITEM_KEY_NETIN,
					self::ITEM_KEY_NETOUT,
					self::ITEM_KEY_LOAD,
					self::ITEM_KEY_CPU_NUM,
					self::ITEM_KEY_RAM_TOTAL
				]
			],
			'searchByAny' => true,
			'preservekeys' => true
		]);

		$host_items = [];

		foreach ($items as $item) {
			$hostid = $item['hostid'];
			$key = $item['key_'];

			if ($key === self::ITEM_KEY_CPU) {
				$host_items[$hostid]['cpu'] = $item;
			}
			elseif ($key === self::ITEM_KEY_RAM_UTIL) {
				$host_items[$hostid]['ram'] = $item;
				$host_items[$hostid]['ram_inverted'] = false;
			}
			elseif ($key === self::ITEM_KEY_RAM_PAVAIL && !isset($host_items[$hostid]['ram'])) {
				$host_items[$hostid]['ram'] = $item;
				$host_items[$hostid]['ram_inverted'] = true;
			}
			elseif (strpos($key, self::ITEM_KEY_DISK) === 0 && preg_match('/,pused\]$/', $key)) {
				$host_items[$hostid]['disk'][] = $item;
			}
			elseif (strpos($key, self::ITEM_KEY_NETIN) === 0) {
				$host_items[$hostid]['net_in'][] = $item;
			}
			elseif (strpos($key, self::ITEM_KEY_NETOUT) === 0) {
				$host_items[$hostid]['net_out'][] = $item;
			}
			elseif (strpos($key, self::ITEM_KEY_LOAD) === 0) {
				$host_items[$hostid]['load'] = $item;
			}
			elseif ($key === self::ITEM_KEY_CPU_NUM) {
				$host_items[$hostid]['cpu_num'] = $item;
			}
			elseif ($key === self::ITEM_KEY_RAM_TOTAL) {
				$host_items[$hostid]['ram_total'] = $item;
			}
		}

		return $host_items;
	}

	private function buildItemContext(array $host_items): array {
		$context = [];

		foreach ($host_items as $items) {
			foreach (['cpu', 'ram', 'load'] as $type) {
				if (isset($items[$type])) {
					$itemid = $items[$type]['itemid'];
					$context[$itemid] = [
						'table' => ((int) $items[$type]['value_type'] === ITEM_VALUE_TYPE_UINT64) ? 'trends_uint' : 'trends',
						'key' => $items[$type]['key_']
					];
				}
			}

			foreach (['disk', 'net_in', 'net_out'] as $type) {
				foreach ($items[$type] ?? [] as $item) {
					$itemid = $item['itemid'];
					$context[$itemid] = [
						'table' => ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64) ? 'trends_uint' : 'trends',
						'key' => $item['key_']
					];
				}
			}
		}

		return $context;
	}

	private function groupItemIdsByTable(array $item_context): array {
		$grouped = [
			'trends' => [],
			'trends_uint' => []
		];

		foreach ($item_context as $itemid => $context) {
			$grouped[$context['table']][] = $itemid;
		}

		return $grouped;
	}

	private function filterItemContext(array $item_context, array $keys): array {
		$filtered = [];

		foreach ($item_context as $itemid => $context) {
			if (in_array($context['key'], $keys, true)) {
				$filtered[$itemid] = $context;
			}
		}

		return $filtered;
	}

	private function getTrendDataBatch(array $itemids_by_table, int $time_from, int $time_till): array {
		$data = [];

		foreach ($itemids_by_table as $table => $itemids) {
			foreach (array_chunk($itemids, self::SQL_ITEM_CHUNK_SIZE) as $chunk) {
				if (empty($chunk)) {
					continue;
				}

				$sql = 'SELECT itemid, AVG(value_avg) AS avg_val, MAX(value_max) AS max_val,'.
					' MIN(value_min) AS min_val'.
					' FROM '.$table.
					' WHERE itemid IN ('.$this->dbIn($chunk).')'.
					' AND clock>='.zbx_dbstr($time_from).
					' AND clock<='.zbx_dbstr($time_till).
					' GROUP BY itemid';

				$result = \DBselect($sql);
				while ($row = \DBfetch($result)) {
					$data[$row['itemid']] = [
						'avg' => ($row['avg_val'] !== null) ? round((float) $row['avg_val'], 2) : null,
						'max' => ($row['max_val'] !== null) ? round((float) $row['max_val'], 2) : null,
						'min' => ($row['min_val'] !== null) ? round((float) $row['min_val'], 2) : null
					];
				}
			}
		}

		return $data;
	}

	private function getP95Batch(array $itemids_by_table, int $time_from, int $time_till): array {
		$p95 = [];

		foreach ($itemids_by_table as $table => $itemids) {
			foreach (array_chunk($itemids, self::SQL_ITEM_CHUNK_SIZE) as $chunk) {
				$p95 += $this->getPercentileBatchForTable($table, $chunk, $time_from, $time_till, 'value_max', 0.95);
			}
		}

		return $p95;
	}

	private function getInvertedRamP95Batch(array $host_items, array $item_context, int $time_from, int $time_till): array {
		$itemids_by_table = [
			'trends' => [],
			'trends_uint' => []
		];

		foreach ($host_items as $items) {
			if (!empty($items['ram_inverted']) && isset($items['ram'])) {
				$itemid = $items['ram']['itemid'];
				$itemids_by_table[$item_context[$itemid]['table']][] = $itemid;
			}
		}

		$inverted = [];

		foreach ($itemids_by_table as $table => $itemids) {
			foreach (array_chunk($itemids, self::SQL_ITEM_CHUNK_SIZE) as $chunk) {
				$p05 = $this->getPercentileBatchForTable($table, $chunk, $time_from, $time_till, 'value_min', 0.05);

				foreach ($p05 as $itemid => $value) {
					$inverted[$itemid] = round(100 - $value, 2);
				}
			}
		}

		return $inverted;
	}

	private function getPercentileBatchForTable(string $table, array $itemids, int $time_from, int $time_till,
			string $value_column, float $percentile): array {
		if (empty($itemids)) {
			return [];
		}

		$sql = 'SELECT itemid, '.$value_column.' AS metric_val'.
			' FROM '.$table.
			' WHERE itemid IN ('.$this->dbIn($itemids).')'.
			' AND clock>='.zbx_dbstr($time_from).
			' AND clock<='.zbx_dbstr($time_till).
			' ORDER BY itemid, '.$value_column.' ASC';

		$result = \DBselect($sql);
		$values = [];

		while ($row = \DBfetch($result)) {
			if ($row['metric_val'] !== null) {
				$values[$row['itemid']][] = (float) $row['metric_val'];
			}
		}

		$percentiles = [];
		foreach ($values as $itemid => $item_values) {
			$total = count($item_values);
			if ($total === 0) {
				continue;
			}

			$index = max(0, min($total - 1, (int) floor($total * $percentile) - 1));
			$percentiles[$itemid] = round($item_values[$index], 2);
		}

		return $percentiles;
	}

	private function getTrendDeltaBatch(array $itemids_by_table, int $fw_start, int $fw_end, int $lw_start,
			int $lw_end): array {
		$first_week = $this->getWindowAverageBatch($itemids_by_table, $fw_start, $fw_end);
		$last_week = $this->getWindowAverageBatch($itemids_by_table, $lw_start, $lw_end);
		$trend = [];

		foreach ($first_week as $itemid => $first) {
			$last = $last_week[$itemid] ?? null;

			if ($last === null
					|| $first['cnt'] < self::MIN_TREND_HOURS
					|| $last['cnt'] < self::MIN_TREND_HOURS
					|| $first['avg'] === null
					|| $last['avg'] === null) {
				continue;
			}

			$trend[$itemid] = round($last['avg'] - $first['avg'], 2);
		}

		return $trend;
	}

	private function getWindowAverageBatch(array $itemids_by_table, int $time_from, int $time_till): array {
		$data = [];

		foreach ($itemids_by_table as $table => $itemids) {
			foreach (array_chunk($itemids, self::SQL_ITEM_CHUNK_SIZE) as $chunk) {
				if (empty($chunk)) {
					continue;
				}

				$sql = 'SELECT itemid, COUNT(*) AS cnt, AVG(value_avg) AS avg_val'.
					' FROM '.$table.
					' WHERE itemid IN ('.$this->dbIn($chunk).')'.
					' AND clock>='.zbx_dbstr($time_from).
					' AND clock<='.zbx_dbstr($time_till).
					' GROUP BY itemid';

				$result = \DBselect($sql);
				while ($row = \DBfetch($result)) {
					$data[$row['itemid']] = [
						'cnt' => (int) $row['cnt'],
						'avg' => ($row['avg_val'] !== null) ? (float) $row['avg_val'] : null
					];
				}
			}
		}

		return $data;
	}

	private function sumAverages(array $items, array $trend_data): ?float {
		$total = 0.0;
		$has_value = false;

		foreach ($items as $item) {
			$itemid = $item['itemid'];
			if (isset($trend_data[$itemid]) && $trend_data[$itemid]['avg'] !== null) {
				$total += (float) $trend_data[$itemid]['avg'];
				$has_value = true;
			}
		}

		return $has_value ? round($total, 2) : null;
	}

	private function maxAverage(array $items, array $trend_data): ?float {
		$max = null;

		foreach ($items as $item) {
			$itemid = $item['itemid'];
			if (isset($trend_data[$itemid]) && $trend_data[$itemid]['avg'] !== null) {
				$value = (float) $trend_data[$itemid]['avg'];
				$max = ($max === null) ? $value : max($max, $value);
			}
		}

		return ($max !== null) ? round($max, 2) : null;
	}

	private function normalizeTags(array $tags): array {
		$normalized = [];

		foreach ($tags as $tag) {
			$tag += ['tag' => '', 'operator' => TAG_OPERATOR_LIKE, 'value' => ''];
			$name = trim((string) $tag['tag']);
			$value = trim((string) $tag['value']);

			if ($name === '' && $value === '') {
				continue;
			}

			$normalized[] = [
				'tag' => $name,
				'operator' => (int) $tag['operator'],
				'value' => $value
			];
		}

		return $normalized;
	}

	private function sortResults(array &$results, string $sort, string $sortorder): void {
		usort($results, function ($a, $b) use ($sort, $sortorder) {
			$va = $a[$sort] ?? null;
			$vb = $b[$sort] ?? null;

			if ($va === $vb) {
				return strcasecmp((string) ($a['host_name'] ?? ''), (string) ($b['host_name'] ?? ''));
			}

			if ($va === null) {
				return 1;
			}
			if ($vb === null) {
				return -1;
			}

			if ($sortorder === 'DESC') {
				return ($va > $vb) ? -1 : 1;
			}

			return ($va < $vb) ? -1 : 1;
		});
	}

	private function buildSummary(array $results): array {
		$oversized_count = 0;
		$high_waste_count = 0;

		foreach ($results as $result) {
			if ($result['waste_level'] === _('HIGH') || $result['waste_level'] === _('MEDIUM')) {
				$oversized_count++;
			}
			if ($result['waste_level'] === _('HIGH')) {
				$high_waste_count++;
			}
		}

		return [
			'total_hosts' => count($results),
			'oversized_count' => $oversized_count,
			'high_waste_count' => $high_waste_count
		];
	}

	private function classifyWaste(float $score): string {
		if ($score >= 80) return _('HIGH');
		if ($score >= 60) return _('MEDIUM');
		if ($score >= 40) return _('LOW');
		return _('HEALTHY');
	}

	private function classifyEfficiency(float $score): string {
		if ($score >= 70) return _('Healthy usage');
		if ($score >= 40) return _('Can be optimized');
		return _('High waste');
	}

	private function generateRecommendation(array $r): string {
		if ($r['cpu_avg'] === null || $r['ram_avg'] === null) {
			return _('Insufficient data for analysis.');
		}

		$growth_blocking = false;
		$growth_details = [];

		if ($r['cpu_trend'] !== null && $r['cpu_trend'] > 0) {
			$cpu_projected = $r['cpu_avg'] + $r['cpu_trend'];
			if ($cpu_projected >= self::CPU_AVG_THRESHOLD) {
				$growth_blocking = true;
				$growth_details[] = sprintf(_('CPU: +%s%% -> projected %s%%'),
					$r['cpu_trend'], round($cpu_projected, 1));
			}
		}

		if ($r['ram_trend'] !== null && $r['ram_trend'] > 0) {
			$ram_projected = $r['ram_avg'] + $r['ram_trend'];
			if ($ram_projected >= self::RAM_AVG_THRESHOLD) {
				$growth_blocking = true;
				$growth_details[] = sprintf(_('RAM: +%s%% -> projected %s%%'),
					$r['ram_trend'], round($ram_projected, 1));
			}
		}

		$disk_saturated = ($r['disk_avg'] !== null && $r['disk_avg'] >= self::DISK_HIGH_THRESHOLD);
		$net_high = ($r['net_in_avg'] !== null && $r['net_in_avg'] > 100000000)
			|| ($r['net_out_avg'] !== null && $r['net_out_avg'] > 100000000);

		$cpu_peak = $r['cpu_p95'] ?? $r['cpu_max'];
		$ram_peak = $r['ram_p95'] ?? $r['ram_max'];

		$cpu_avg_low = ($r['cpu_avg'] < self::CPU_AVG_THRESHOLD);
		$ram_avg_low = ($r['ram_avg'] < self::RAM_AVG_THRESHOLD);
		$cpu_peak_ok = ($cpu_peak !== null && $cpu_peak < self::CPU_MAX_THRESHOLD);
		$ram_peak_ok = ($ram_peak !== null && $ram_peak < self::RAM_MAX_THRESHOLD);

		if (!$cpu_avg_low || !$ram_avg_low) {
			return _('Resource usage is within acceptable range. No action needed.');
		}

		if ($disk_saturated || $net_high) {
			return _('Server with low CPU/RAM usage, however other resources are under high utilization. Reduction not recommended.');
		}

		if ($growth_blocking) {
			return _('Workload growing toward thresholds. Resource reduction not recommended at this time.')
				.' ('.implode('; ', $growth_details).')';
		}

		if ($cpu_peak_ok && $ram_peak_ok) {
			return _('Server with low resource utilization. Consider reducing CPU and memory for this machine.');
		}

		$spike_parts = [];
		if (!$cpu_peak_ok) {
			$spike_parts[] = sprintf(_('CPU P95 peak: %s%%'), $cpu_peak);
		}
		if (!$ram_peak_ok) {
			$spike_parts[] = sprintf(_('RAM P95 peak: %s%%'), $ram_peak);
		}

		return _('Server mostly idle but with periodic load spikes. Investigate spike patterns before downsizing.')
			.' ('.implode('; ', $spike_parts).')';
	}

	private function calculateRightSizing(array $r): array {
		if ($r['cpu_p95'] !== null && $r['cpu_p95'] > 0 && $r['cpu_count'] !== null && $r['cpu_count'] > 0) {
			$recommended = max(1, (int) floor($r['cpu_count'] * self::RIGHT_SIZE_FACTOR));
			$actual_need = ($r['cpu_p95'] / 100) * $r['cpu_count'];

			if ($recommended >= $actual_need && $recommended < $r['cpu_count']) {
				$r['cpu_recommended'] = $recommended;
			}
		}

		if ($r['ram_p95'] !== null && $r['ram_p95'] > 0 && $r['ram_total_gb'] !== null && $r['ram_total_gb'] > 0) {
			$recommended = max(2, round($r['ram_total_gb'] * self::RIGHT_SIZE_FACTOR, 1));
			$actual_need = ($r['ram_p95'] / 100) * $r['ram_total_gb'];

			if ($recommended >= $actual_need && $recommended < $r['ram_total_gb']) {
				$r['ram_recommended_gb'] = $recommended;
			}
		}

		return $r;
	}

	private function dbIn(array $values): string {
		return implode(',', array_map('zbx_dbstr', $values));
	}
}
