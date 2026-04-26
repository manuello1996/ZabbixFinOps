<?php declare(strict_types = 0);

namespace Modules\ZabbixFinOpsToolkit\Actions;

use CController,
	CControllerResponseData,
	CRoleHelper,
	CWebUser;

require_once __DIR__.'/CostAnalyzerProfile.php';
require_once __DIR__.'/CostAnalyzerService.php';

class CostAnalyzer extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_groupids' => 'array',
			'filter_host' => 'string',
			'filter_status' => 'in -1,'.HOST_STATUS_MONITORED.','.HOST_STATUS_NOT_MONITORED,
			'filter_show_maintenance' => 'in '.HOST_MAINTENANCE_STATUS_OFF.','.HOST_MAINTENANCE_STATUS_ON,
			'filter_evaltype' => 'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'filter_tags' => 'array',
			'filter_rst' => 'in 1',
			'filter_set' => 'in 1',
			'sort' => 'in waste_score,efficiency_score,cpu_avg,ram_avg,host_name',
			'sortorder' => 'in ASC,DESC',
			'page' => 'ge 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['error' => _('Invalid input parameters.')]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
	}

	protected function doAction(): void {
		if ($this->hasInput('filter_set')) {
			CostAnalyzerProfile::save($this->getFilterInput());
		}
		elseif ($this->hasInput('filter_rst')) {
			CostAnalyzerProfile::reset();
		}

		$filters = CostAnalyzerProfile::load();
		$sort = $this->getInput('sort', 'host_name');
		$sortorder = $this->getInput('sortorder', 'ASC');
		$page = (int) $this->getInput('page', 1);
		$page_size = max(1, (int) CWebUser::$data['rows_per_page']);

		$service = new CostAnalyzerService();
		$analysis = $service->analyzePage($filters, $sort, $sortorder, $page, $page_size);

		$data = [
			'results' => $analysis['results'],
			'summary' => $analysis['summary'],
			'host_groups' => $service->getHostGroups(),
			'filter_groupids' => $filters['groupids'],
			'filter_host' => $filters['host'],
			'filter_status' => $filters['status'],
			'filter_show_maintenance' => $filters['show_maintenance'],
			'filter_evaltype' => $filters['evaltype'],
			'filter_tags' => $filters['tags'],
			'sort' => $sort,
			'sortorder' => $sortorder,
			'page' => $analysis['page'],
			'page_count' => $analysis['page_count'],
			'page_size' => $analysis['page_size'],
			'total_hosts' => $analysis['total_hosts'],
			'filter_profile' => CostAnalyzerProfile::PROFILE_FILTER,
			'active_tab' => 1
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Infrastructure Cost Analyzer'));
		$this->setResponse($response);
	}

	private function getFilterInput(): array {
		return [
			'groupids' => $this->getInput('filter_groupids', []),
			'host' => $this->getInput('filter_host', ''),
			'status' => $this->getInput('filter_status', -1),
			'show_maintenance' => $this->getInput('filter_show_maintenance', HOST_MAINTENANCE_STATUS_OFF),
			'evaltype' => $this->getInput('filter_evaltype', TAG_EVAL_TYPE_AND_OR),
			'tags' => $this->getInput('filter_tags', [])
		];
	}
}
