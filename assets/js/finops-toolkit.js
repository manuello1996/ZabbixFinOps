/**
 * Zabbix FinOps Toolkit - Neo-Brutalist Edition
 * Minimal, technical interactivity
 */

(function() {
    'use strict';

    /**
     * Table keyboard navigation
     */
    function initKeyboardNav() {
        const table = document.querySelector('.finops-table');
        if (!table) return;

        const rows = table.querySelectorAll('tbody tr');
        let currentRow = -1;

        table.setAttribute('tabindex', '0');

        table.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentRow = Math.min(currentRow + 1, rows.length - 1);
                focusRow(rows[currentRow]);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentRow = Math.max(currentRow - 1, 0);
                focusRow(rows[currentRow]);
            }
        });

        function focusRow(row) {
            rows.forEach(r => r.style.outline = 'none');
            if (row) {
                row.style.outline = '2px solid #dc2626';
                row.style.outlineOffset = '-2px';
                row.scrollIntoView({ block: 'nearest' });
            }
        }
    }

    /**
     * Sort feedback - simple visual only
     */
    function initSortFeedback() {
        document.querySelectorAll('.finops-table th a').forEach(link => {
            link.addEventListener('click', function() {
                this.style.opacity = '0.5';
            });
        });
    }

    /**
     * Export button state
     */
    function initExportBtn() {
        const btn = document.querySelector('.finops-btn-accent');
        if (!btn) return;

        btn.addEventListener('click', function() {
            const text = this.textContent;
            this.textContent = '...';
            setTimeout(() => {
                this.textContent = text;
            }, 1500);
        });
    }

    /**
     * Native Zabbix tag filter row handling.
     */
    function initTagFilter() {
        if (!window.jQuery || !jQuery.fn.dynamicRows || !document.querySelector('#filter-tags')) {
            return;
        }

        jQuery('#filter-tags')
            .dynamicRows({template: '#filter-tag-row-tmpl'})
            .on('afteradd.dynamicRows', function() {
                const rows = this.querySelectorAll('.form_row');

                if (window.CTagFilterItem) {
                    new CTagFilterItem(rows[rows.length - 1]);
                }
            });

        if (window.CTagFilterItem) {
            document.querySelectorAll('#filter-tags .form_row').forEach(row => {
                new CTagFilterItem(row);
            });
        }
    }

    /**
     * Host menu popup compatibility.
     *
     * Zabbix host context menus call view.editHost() for the Configuration -> Host
     * entry. Module pages do not get the monitoring host page's view object, so
     * provide the small method needed by the native menu.
     */
    function initHostPopupActions() {
        window.view = window.view || {};

        if (typeof window.view.editHost === 'function') {
            return;
        }

        window.view.editHost = function(arg, hostid) {
            if (arg instanceof Event) {
                arg.preventDefault();
            }

            const resolvedHostid = hostid || arg;

            if (!resolvedHostid || typeof window.PopUp !== 'function') {
                return;
            }

            window.PopUp('popup.host.edit', {hostid: resolvedHostid}, {
                dialogueid: 'host_edit',
                dialogue_class: 'modal-popup-large',
                prevent_navigation: true
            });
        };
    }

    /**
     * Initialize
     */
    function init() {
        if (!document.querySelector('.finops-container')) {
            return;
        }

        initKeyboardNav();
        initSortFeedback();
        initExportBtn();
        initTagFilter();
        initHostPopupActions();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
