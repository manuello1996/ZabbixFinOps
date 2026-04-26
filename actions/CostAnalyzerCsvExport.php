<?php declare(strict_types = 0);

namespace Modules\ZabbixFinOpsToolkit\Actions;

use CController,
	CControllerResponseData,
	CRoleHelper;

require_once __DIR__.'/CostAnalyzerProfile.php';
require_once __DIR__.'/CostAnalyzerService.php';

class CostAnalyzerCsvExport extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput([
			'filter_groupids' => 'array',
			'filter_host' => 'string',
			'filter_status' => 'in -1,'.HOST_STATUS_MONITORED.','.HOST_STATUS_NOT_MONITORED,
			'filter_show_maintenance' => 'in '.HOST_MAINTENANCE_STATUS_OFF.','.HOST_MAINTENANCE_STATUS_ON,
			'filter_evaltype' => 'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'filter_tags' => 'array'
		]);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
	}

	protected function doAction(): void {
		$filters = $this->hasFilterInput()
			? CostAnalyzerProfile::normalize($this->getFilterInput())
			: CostAnalyzerProfile::load();

		$response = new CControllerResponseData([
			'results' => (new CostAnalyzerService())->analyzeForExport($filters)
		]);
		$response->setFileName('finops_cost_analysis_'.date('Y-m-d').'.csv');
		$this->setResponse($response);
	}

	private function hasFilterInput(): bool {
		foreach (['filter_groupids', 'filter_host', 'filter_status', 'filter_show_maintenance', 'filter_tags'] as $field) {
			if ($this->hasInput($field)) {
				return true;
			}
		}

		return false;
	}

	private function getFilterInput(): array {
		return [
			'groupids' => $this->getInput('filter_groupids', []),
			'host' => $this->getInput('filter_host', ''),
			'status' => $this->getInput('filter_status', -1),
			'show_maintenance' => $this->getInput('filter_show_maintenance', HOST_MAINTENANCE_STATUS_ON),
			'evaltype' => $this->getInput('filter_evaltype', TAG_EVAL_TYPE_AND_OR),
			'tags' => $this->getInput('filter_tags', [])
		];
	}
}
