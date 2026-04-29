<?php declare(strict_types = 0);
/**
 * Zabbix FinOps Toolkit - Infrastructure Cost Analyzer View
 * Clean Minimal Design
 *
 * @var CView $this
 * @var array $data
 */

$this->addJsFile('class.tagfilteritem.js');

$selected_groups = [];
if (!empty($data['filter_groupids'])) {
    foreach ($data['host_groups'] as $group) {
        if (in_array($group['groupid'], $data['filter_groupids'])) {
            $selected_groups[] = [
                'id'   => $group['groupid'],
                'name' => $group['name']
            ];
        }
    }
}

$filter_tags = $data['filter_tags'];
if (!$filter_tags) {
    $filter_tags = [['tag' => '', 'operator' => TAG_OPERATOR_LIKE, 'value' => '']];
}

$filter = (new CFilter())
    ->addVar('action', 'finops.costanalyzer.view')
    ->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'finops.costanalyzer.view'))
    ->setProfile($data['filter_profile'])
    ->setActiveTab($data['active_tab'])
    ->addFilterTab(_('Filter'), [
        (new CFormGrid())
            ->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
            ->addItem([
                new CLabel(_('Host'), 'filter_hostids__ms'),
                new CFormField(
                    (new CMultiSelect([
                        'name' => 'filter_hostids[]',
                        'object_name' => 'hosts',
                        'data' => $data['hosts'],
                        'popup' => [
                            'filter_preselect' => [
                                'id' => 'filter_groupids_',
                                'submit_as' => 'groupid'
                            ],
                            'parameters' => [
                                'srctbl' => 'hosts',
                                'srcfld1' => 'hostid',
                                'dstfrm' => 'zbx_filter',
                                'dstfld1' => 'filter_hostids_'
                            ]
                        ]
                    ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
                )
            ])
            ->addItem([
                new CLabel(_('Host groups'), 'filter_groupids__ms'),
                new CFormField(
                    (new CMultiSelect([
                        'name' => 'filter_groupids[]',
                        'object_name' => 'hostGroup',
                        'data' => $selected_groups,
                        'popup' => [
                            'parameters' => [
                                'srctbl' => 'host_groups',
                                'srcfld1' => 'groupid',
                                'dstfrm' => 'zbx_filter',
                                'dstfld1' => 'filter_groupids_',
                                'with_hosts' => true,
                                'enrich_parent_groups' => true
                            ]
                        ]
                    ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
                )
            ]),
        (new CFormGrid())
            ->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
            ->addItem([
                new CLabel(_('Status'), 'filter_status'),
                new CFormField(
                    (new CRadioButtonList('filter_status', (int) $data['filter_status']))
                        ->addValue(_('Any'), -1)
                        ->addValue(_('Enabled'), HOST_STATUS_MONITORED)
                        ->addValue(_('Disabled'), HOST_STATUS_NOT_MONITORED)
                        ->setModern(true)
                )
            ])
            ->addItem([
                new CLabel(_('Show hosts in maintenance'), 'filter_show_maintenance'),
                new CFormField(
                    (new CCheckBox('filter_show_maintenance', HOST_MAINTENANCE_STATUS_ON))
                        ->setId('filter_show_maintenance')
                        ->setChecked((int) $data['filter_show_maintenance'] == HOST_MAINTENANCE_STATUS_ON)
                        ->setUncheckedValue(HOST_MAINTENANCE_STATUS_OFF)
                )
            ])
            ->addItem([
                new CLabel(_('Tags')),
                new CFormField(
                    CTagFilterFieldHelper::getTagFilterField([
                        'evaltype' => $data['filter_evaltype'],
                        'tags' => $filter_tags
                    ])
                )
            ])
    ]);

// CSV export link.
$csv_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'finops.costanalyzer.csv');

finops_add_filter_arguments($csv_url, $data);

// Calculate summary statistics.
$results = $data['results'];
$summary_data = $data['summary'] ?? [];
$total_hosts = $data['total_hosts'] ?? ($summary_data['total_hosts'] ?? count($results));
$oversized_count = $summary_data['oversized_count'] ?? 0;
$high_waste_count = $summary_data['high_waste_count'] ?? 0;
$page = $data['page'] ?? 1;
$page_count = $data['page_count'] ?? 1;
$page_size = $data['page_size'] ?? max(1, count($results));

// Summary cards grid.
$summary = (new CDiv([
    // Total Hosts Card
    (new CDiv([
        (new CSpan(_('Hosts Found')))->addClass('finops-stat-label'),
        (new CSpan((string)$total_hosts))->addClass('finops-stat-value')
    ]))->addClass('finops-stat-card'),

    // Oversized Card
    (new CDiv([
        (new CSpan(_('Oversized')))->addClass('finops-stat-label'),
        (new CSpan((string)$oversized_count))->addClass('finops-stat-value')
    ]))->addClass('finops-stat-card finops-stat-card--warning'),

    // High Waste Card
    (new CDiv([
        (new CSpan(_('Critical Waste')))->addClass('finops-stat-label'),
        (new CSpan((string)$high_waste_count))->addClass('finops-stat-value')
    ]))->addClass('finops-stat-card finops-stat-card--critical'),

    ]))->addClass('finops-summary-grid');

// Table sorting setup.
$sort = $data['sort'];
$sortorder = $data['sortorder'];

$base_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'finops.costanalyzer.view');

finops_add_filter_arguments($base_url, $data);

function finops_add_filter_arguments($url, array $data) {
    if (!empty($data['filter_groupids'])) {
        foreach (array_values($data['filter_groupids']) as $index => $gid) {
            $url->setArgument('filter_groupids['.$index.']', $gid);
        }
    }

    if (!empty($data['filter_hostids'])) {
        foreach (array_values($data['filter_hostids']) as $index => $hostid) {
            $url->setArgument('filter_hostids['.$index.']', $hostid);
        }
    }

    if (($data['filter_status'] ?? -1) != -1) {
        $url->setArgument('filter_status', $data['filter_status']);
    }

    if (($data['filter_show_maintenance'] ?? HOST_MAINTENANCE_STATUS_ON) != HOST_MAINTENANCE_STATUS_ON) {
        $url->setArgument('filter_show_maintenance', $data['filter_show_maintenance']);
    }

    if (($data['filter_evaltype'] ?? TAG_EVAL_TYPE_AND_OR) != TAG_EVAL_TYPE_AND_OR) {
        $url->setArgument('filter_evaltype', $data['filter_evaltype']);
    }

    foreach (array_values($data['filter_tags'] ?? []) as $index => $tag) {
        $tag += ['tag' => '', 'operator' => TAG_OPERATOR_LIKE, 'value' => ''];
        $tag_name = trim((string) $tag['tag']);
        $tag_value = trim((string) $tag['value']);

        if ($tag_name === '' && $tag_value === '') {
            continue;
        }

        $url->setArgument('filter_tags['.$index.'][tag]', $tag_name);
        $url->setArgument('filter_tags['.$index.'][operator]', $tag['operator']);
        $url->setArgument('filter_tags['.$index.'][value]', $tag_value);
    }
}

function finops_url_with_state($base_url, $sort, $sortorder, $page) {
    return (new CUrl($base_url->getUrl()))
        ->setArgument('sort', $sort)
        ->setArgument('sortorder', $sortorder)
        ->setArgument('page', $page);
}

// Helper: Create sortable header.
function make_brutalist_header($label, $field, $current_sort, $current_order, $base_url) {
    $is_sorted = ($current_sort === $field);
    $next_order = ($is_sorted && $current_order === 'DESC') ? 'ASC' : 'DESC';

    $url = finops_url_with_state($base_url, $field, $next_order, 1);

    $class = '';
    if ($is_sorted) {
        $class = 'sort-' . strtolower($current_order);
    }

    $indicator = $is_sorted
        ? (new CSpan($current_order === 'DESC' ? ' ▼' : ' ▲'))->addClass('sort-indicator')
        : (new CSpan(' ▼'))->addClass('sort-indicator');

    $link = (new CLink([$label, $indicator], $url->getUrl()))
        ->addClass($is_sorted ? 'sortable' : '');

    $header = new CColHeader([
        $link,
    ]);

    return ($class !== '') ? $header->addClass($class) : $header;
}

// Table headers.
$header = [
    new CColHeader(_('Host')),
    new CColHeader(_('Group')),
    make_brutalist_header(_('CPU'), 'cpu_avg', $sort, $sortorder, $base_url),
    make_brutalist_header(_('RAM'), 'ram_avg', $sort, $sortorder, $base_url),
    (new CColHeader(_('Network')))->addClass('finops-text-right'),
    (new CColHeader(_('Load')))->addClass('finops-text-right'),
    make_brutalist_header(_('Waste'), 'waste_score', $sort, $sortorder, $base_url),
    make_brutalist_header(_('Efficiency'), 'efficiency_score', $sort, $sortorder, $base_url),
    (new CColHeader(_('Sizing')))->addClass('finops-text-right'),
    new CColHeader(_('Trend')),
    new CColHeader(_('Recommendation')),
];

$table = (new CTableInfo())
    ->setHeader($header)
    ->addClass('finops-table');

// Helper: Format network values.
function formatNetworkBrutalist($bytes_per_sec) {
    if ($bytes_per_sec === null) {
        return (new CSpan('N/A'))->addClass('finops-cell-metric--na');
    }

    $val = (float) $bytes_per_sec;

    if ($val >= 1073741824) {
        $result = round($val / 1073741824, 2) . ' GB';
    } elseif ($val >= 1048576) {
        $result = round($val / 1048576, 2) . ' MB';
    } elseif ($val >= 1024) {
        $result = round($val / 1024, 2) . ' KB';
    } else {
        $result = round($val, 2) . ' B';
    }

    $class = 'finops-cell-metric';
    if ($val > 100000000) {
        $class .= ' finops-cell-metric--high';
    } elseif ($val > 10000000) {
        $class .= ' finops-cell-metric--medium';
    } else {
        $class .= ' finops-cell-metric--low';
    }

    return (new CSpan($result))->addClass($class);
}

// Helper: Format percentage.
function formatPctBrutalist($value, $low_thresh = 30, $high_thresh = 80) {
    if ($value === null) {
        return (new CSpan('N/A'))->addClass('finops-cell-metric--na');
    }

    $class = 'finops-cell-metric';
    if ($value < $low_thresh) {
        $class .= ' finops-cell-metric--low';
    } elseif ($value > $high_thresh) {
        $class .= ' finops-cell-metric--high';
    } else {
        $class .= ' finops-cell-metric--medium';
    }

    return (new CSpan(round($value, 1) . '%'))->addClass($class);
}

function formatCompactMetric($label, $value, $low_thresh = 30, $high_thresh = 80) {
    return (new CDiv([
        (new CSpan($label))->addClass('finops-metric-label'),
        formatPctBrutalist($value, $low_thresh, $high_thresh)
    ]))->addClass('finops-metric-line');
}

function formatCompactValue($label, $value, $unit = '') {
    $metric = ($value !== null)
        ? (new CSpan($value.$unit))->addClass('finops-cell-metric')
        : (new CSpan('N/A'))->addClass('finops-cell-metric--na');

    return (new CDiv([
        (new CSpan($label))->addClass('finops-metric-label'),
        $metric
    ]))->addClass('finops-metric-line');
}

// Helper: Get waste badge class.
function getWasteBadgeClass($level): string {
    switch ($level) {
        case 'HIGH':   return 'finops-badge--high';
        case 'MEDIUM': return 'finops-badge--medium';
        case 'LOW':    return 'finops-badge--low';
        default:       return 'finops-badge--healthy';
    }
}

// Helper: Get trend indicator.
function getTrendBrutalist($cpu_trend, $ram_trend) {
    $items = [];

    if ($cpu_trend !== null) {
        $sign = $cpu_trend >= 0 ? '+' : '';
        $class = $cpu_trend >= 0 ? 'finops-trend-up' : 'finops-trend-down';
        $items[] = (new CDiv([
            (new CSpan('CPU:'))->addClass('finops-text-muted'),
            ' ',
            (new CSpan($sign . round($cpu_trend, 1) . '%'))->addClass($class)
        ]))->addClass('finops-trend-item');
    }

    if ($ram_trend !== null) {
        $sign = $ram_trend >= 0 ? '+' : '';
        $class = $ram_trend >= 0 ? 'finops-trend-up' : 'finops-trend-down';
        $items[] = (new CDiv([
            (new CSpan('RAM:'))->addClass('finops-text-muted'),
            ' ',
            (new CSpan($sign . round($ram_trend, 1) . '%'))->addClass($class)
        ]))->addClass('finops-trend-item');
    }

    if (empty($items)) {
        return (new CSpan('N/A'))->addClass('finops-cell-metric--na');
    }

    return (new CDiv($items))->addClass('finops-trend');
}

// Build table rows.
foreach ($results as $r) {
    $waste_level_raw = ($r['waste_score'] !== null)
        ? (($r['waste_score'] >= 80) ? 'HIGH' : (($r['waste_score'] >= 60) ? 'MEDIUM' : (($r['waste_score'] >= 40) ? 'LOW' : 'HEALTHY')))
        : 'HEALTHY';

    $row_class = '';
    if ($waste_level_raw === 'HIGH') {
        $row_class = 'finops-row-high-waste';
    }

    // Waste badge.
    $waste_badge = ($r['waste_score'] !== null)
        ? (new CSpan([
            $r['waste_score'],
            ' (',
            $r['waste_level'],
            ')'
        ]))->addClass('finops-badge ' . getWasteBadgeClass($waste_level_raw))
        : (new CSpan('N/A'))->addClass('finops-cell-metric--na');

    // Efficiency badge.
    $eff_class = 'finops-badge--healthy';
    if ($r['efficiency_score'] !== null) {
        if ($r['efficiency_score'] < 40) {
            $eff_class = 'finops-badge--high';
        } elseif ($r['efficiency_score'] < 70) {
            $eff_class = 'finops-badge--medium';
        } else {
            $eff_class = 'finops-badge--healthy';
        }
    }

    $eff_badge = ($r['efficiency_score'] !== null)
        ? (new CSpan([
            $r['efficiency_score'],
            '%'
        ]))->addClass('finops-badge ' . $eff_class)
        : (new CSpan('N/A'))->addClass('finops-cell-metric--na');

    $host_link = (new CLinkAction($r['host_name']))
        ->setMenuPopup(CMenuPopupHelper::getHost($r['hostid']));

    $row = new CRow([
        (new CCol($host_link))->addClass('finops-cell-host'),
        (new CCol($r['host_groups']))->addClass('finops-cell-group'),
        (new CCol([
            formatCompactMetric(_('Avg'), $r['cpu_avg'], 20, 60),
            formatCompactMetric(_('Max'), $r['cpu_max'], 60, 85),
            formatCompactMetric(_('P95'), $r['cpu_p95'] ?? null, 60, 85)
        ]))->addClass('finops-cell-compact'),
        (new CCol([
            formatCompactMetric(_('Avg'), $r['ram_avg'], 40, 80),
            formatCompactMetric(_('Max'), $r['ram_max'], 80, 95),
            formatCompactMetric(_('P95'), $r['ram_p95'] ?? null, 80, 95)
        ]))->addClass('finops-cell-compact'),
        (new CCol([
            (new CDiv([
                (new CSpan(_('In')))->addClass('finops-metric-label'),
                formatNetworkBrutalist($r['net_in_avg'])
            ]))->addClass('finops-metric-line'),
            (new CDiv([
                (new CSpan(_('Out')))->addClass('finops-metric-label'),
                formatNetworkBrutalist($r['net_out_avg'])
            ]))->addClass('finops-metric-line')
        ]))->addClass('finops-cell-compact'),
        (new CCol(
            ($r['load_avg'] !== null)
                ? (new CSpan($r['load_avg']))->addClass('finops-cell-metric')
                : (new CSpan('N/A'))->addClass('finops-cell-metric--na')
        ))->addClass('finops-cell-metric'),
        (new CCol($waste_badge))->addClass('finops-text-center'),
        (new CCol($eff_badge))->addClass('finops-text-center'),
        (new CCol([
            formatCompactValue(_('CPU'), $r['cpu_count'], ' vCPU'),
            formatCompactValue(_('CPU rec.'), $r['cpu_recommended'], ' vCPU'),
            formatCompactValue(_('RAM'), $r['ram_total_gb'], ' GB'),
            formatCompactValue(_('RAM rec.'), $r['ram_recommended_gb'], ' GB')
        ]))->addClass('finops-cell-compact'),
        (new CCol(getTrendBrutalist($r['cpu_trend'], $r['ram_trend']))),
        (new CCol(
            (new CSpan($r['recommendation']))->addClass('finops-recommendation')
        )),
    ]);

    if ($row_class) {
        $row->addClass($row_class);
    }

    $table->addRow($row);
}

// Empty state.
if (empty($results)) {
    $table->addRow(
        (new CCol(
            (new CDiv([
                (new CTag('h3', true, _('No Data Available')))->addClass('finops-empty-title'),
                (new CSpan(_('Select a host, host group, or tag filter to see cost analysis.')))->addClass('finops-empty-text')
            ]))->addClass('finops-empty-state')
        ))->setColSpan(11)
    );
}

$pager_links = [];
$range_from = ($total_hosts > 0) ? (($page - 1) * $page_size + 1) : 0;
$range_to = min($total_hosts, $page * $page_size);

if ($page > 1) {
    $prev_url = finops_url_with_state($base_url, $sort, $sortorder, $page - 1);
    $pager_links[] = (new CLink('<', $prev_url->getUrl()))
        ->addClass('finops-page-link');
}
else {
    $pager_links[] = (new CSpan('<'))->addClass('finops-page-link finops-page-link-disabled');
}

$pager_window_start = max(1, $page - 2);
$pager_window_end = min($page_count, $page + 2);

if ($pager_window_start > 1) {
    $first_url = finops_url_with_state($base_url, $sort, $sortorder, 1);
    $pager_links[] = (new CLink('1', $first_url->getUrl()))->addClass('finops-page-link');

    if ($pager_window_start > 2) {
        $pager_links[] = (new CSpan('...'))->addClass('finops-page-ellipsis');
    }
}

for ($page_num = $pager_window_start; $page_num <= $pager_window_end; $page_num++) {
    if ($page_num == $page) {
        $pager_links[] = (new CSpan((string) $page_num))->addClass('finops-page-link finops-page-link-current');
    }
    else {
        $page_url = finops_url_with_state($base_url, $sort, $sortorder, $page_num);
        $pager_links[] = (new CLink((string) $page_num, $page_url->getUrl()))->addClass('finops-page-link');
    }
}

if ($pager_window_end < $page_count) {
    if ($pager_window_end < $page_count - 1) {
        $pager_links[] = (new CSpan('...'))->addClass('finops-page-ellipsis');
    }

    $last_url = finops_url_with_state($base_url, $sort, $sortorder, $page_count);
    $pager_links[] = (new CLink((string) $page_count, $last_url->getUrl()))->addClass('finops-page-link');
}

if ($page < $page_count) {
    $next_url = finops_url_with_state($base_url, $sort, $sortorder, $page + 1);
    $pager_links[] = (new CLink('>', $next_url->getUrl()))
        ->addClass('finops-page-link');
}
else {
    $pager_links[] = (new CSpan('>'))->addClass('finops-page-link finops-page-link-disabled');
}

$pager = (new CDiv([
    (new CDiv($pager_links))->addClass('finops-page-list'),
    (new CDiv(sprintf(_('Displaying %1$s to %2$s of %3$s found'), $range_from, $range_to, $total_hosts)))
        ->addClass('finops-page-label')
]))->addClass('finops-table-pager');

// Build page structure.
$page = (new CHtmlPage())
    ->setTitle(_('Infrastructure Cost Analyzer'))
    ->setControls(
        (new CTag('nav', true,
            (new CList())
                ->addItem(
                    (new CRedirectButton(_('Export CSV'), $csv_url))
                        ->setId('export_csv')
                )
                ->addItem(
                    (new CLink(null, 'https://www.zabbix.com/documentation/'))
                        ->addClass(ZBX_STYLE_BTN_ICON)
                        ->addClass(ZBX_ICON_HELP)
                        ->setTitle(_('Help'))
                        ->setTarget('_blank')
                )
        ))->setAttribute('aria-label', _('Content controls'))
    );

$tag_filter_template = (new CTag('script', true, new CObject(CTagFilterFieldHelper::getTemplate())))
    ->setAttribute('type', 'text/x-jquery-tmpl')
    ->setAttribute('id', 'filter-tag-row-tmpl');

// Header section.
$header_section = (new CDiv([
    (new CDiv([
        (new CDiv([
            (new CDiv('Fn'))->addClass('finops-logo'),
            (new CDiv([
                (new CTag('h1', true, _('Infrastructure Cost Analyzer')))->addClass('finops-title'),
                (new CSpan(_('Resource utilization & cost optimization analysis')))->addClass('finops-subtitle')
            ]))->addClass('finops-title-group')
        ]))->addClass('finops-title-block')
    ]))->addClass('finops-header-inner')
]))->addClass('finops-header');

// Summary section.
$summary_section = (new CDiv($summary))->addClass('finops-summary-section');

// Table section with header.
$table_section = (new CDiv([
    (new CDiv([
        (new CSpan(_('Analysis Results')))->addClass('finops-section-title'),
        (new CSpan($total_hosts . ' ' . _('hosts')))->addClass('finops-section-meta')
    ]))->addClass('finops-section-header'),
    (new CDiv($table))->addClass('finops-table-wrapper'),
    $pager
]))->addClass('finops-table-section');

// Main container.
$container = (new CDiv([
    $header_section,
    $filter,
    $summary_section,
    $table_section
]))->addClass('finops-container');

$page
    ->addItem($tag_filter_template)
    ->addItem($container);
$page->show();
