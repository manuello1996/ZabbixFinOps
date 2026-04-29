<?php declare(strict_types = 0);

namespace Modules\ZabbixFinOpsToolkit\Actions;

use CProfile;

class CostAnalyzerProfile {

	public const PROFILE_FILTER = 'web.zabbixfinops.costanalyzer.filter';

	public static function save(array $filters): void {
		$filters = self::normalize($filters);

		CProfile::updateArray(self::PROFILE_FILTER.'.groupids', $filters['groupids'], PROFILE_TYPE_ID);
		CProfile::updateArray(self::PROFILE_FILTER.'.hostids', $filters['hostids'], PROFILE_TYPE_ID);
		CProfile::delete(self::PROFILE_FILTER.'.host');
		CProfile::update(self::PROFILE_FILTER.'.status', $filters['status'], PROFILE_TYPE_INT);
		CProfile::update(self::PROFILE_FILTER.'.show_maintenance',
			$filters['show_maintenance'], PROFILE_TYPE_INT
		);
		CProfile::update(self::PROFILE_FILTER.'.evaltype', $filters['evaltype'], PROFILE_TYPE_INT);

		$filter_tags = ['tags' => [], 'values' => [], 'operators' => []];
		foreach ($filters['tags'] as $tag) {
			$tag += ['tag' => '', 'operator' => TAG_OPERATOR_LIKE, 'value' => ''];

			if (trim((string) $tag['tag']) === '' && trim((string) $tag['value']) === '') {
				continue;
			}

			$filter_tags['tags'][] = trim((string) $tag['tag']);
			$filter_tags['values'][] = trim((string) $tag['value']);
			$filter_tags['operators'][] = (int) $tag['operator'];
		}

		CProfile::updateArray(self::PROFILE_FILTER.'.tags.tag', $filter_tags['tags'], PROFILE_TYPE_STR);
		CProfile::updateArray(self::PROFILE_FILTER.'.tags.value', $filter_tags['values'], PROFILE_TYPE_STR);
		CProfile::updateArray(self::PROFILE_FILTER.'.tags.operator', $filter_tags['operators'], PROFILE_TYPE_INT);
	}

	public static function reset(): void {
		CProfile::deleteIdx(self::PROFILE_FILTER.'.groupids');
		CProfile::deleteIdx(self::PROFILE_FILTER.'.hostids');
		CProfile::delete(self::PROFILE_FILTER.'.host');
		CProfile::delete(self::PROFILE_FILTER.'.status');
		CProfile::delete(self::PROFILE_FILTER.'.show_maintenance');
		CProfile::delete(self::PROFILE_FILTER.'.evaltype');
		CProfile::deleteIdx(self::PROFILE_FILTER.'.tags.tag');
		CProfile::deleteIdx(self::PROFILE_FILTER.'.tags.value');
		CProfile::deleteIdx(self::PROFILE_FILTER.'.tags.operator');
	}

	public static function load(): array {
		$tags = [];
		foreach (CProfile::getArray(self::PROFILE_FILTER.'.tags.tag', []) as $index => $tag) {
			$tags[] = [
				'tag' => $tag,
				'value' => CProfile::get(self::PROFILE_FILTER.'.tags.value', '', $index),
				'operator' => CProfile::get(self::PROFILE_FILTER.'.tags.operator', TAG_OPERATOR_LIKE, $index)
			];
		}

		return [
			'groupids' => CProfile::getArray(self::PROFILE_FILTER.'.groupids', []),
			'hostids' => CProfile::getArray(self::PROFILE_FILTER.'.hostids', []),
			'status' => CProfile::get(self::PROFILE_FILTER.'.status', -1),
			'show_maintenance' => CProfile::get(self::PROFILE_FILTER.'.show_maintenance',
				HOST_MAINTENANCE_STATUS_ON
			),
			'evaltype' => CProfile::get(self::PROFILE_FILTER.'.evaltype', TAG_EVAL_TYPE_AND_OR),
			'tags' => $tags
		];
	}

	public static function normalize(array $filters): array {
		$filters += [
			'groupids' => [],
			'hostids' => [],
			'status' => -1,
			'show_maintenance' => HOST_MAINTENANCE_STATUS_ON,
			'evaltype' => TAG_EVAL_TYPE_AND_OR,
			'tags' => []
		];

		return [
			'groupids' => array_values((array) $filters['groupids']),
			'hostids' => array_values((array) $filters['hostids']),
			'status' => (int) $filters['status'],
			'show_maintenance' => (int) $filters['show_maintenance'],
			'evaltype' => (int) $filters['evaltype'],
			'tags' => (array) $filters['tags']
		];
	}
}
