import 'datatables.net-bs5';
import 'datatables.net-responsive-bs5';

import {
    genericDataTablesActionButton,
    initializeDeleteButtonListener,
} from '../components/dataTableHelper';

import { __, getDataTablesLanguageOptions } from '../i18n';

const dataTableSelector = '#table';

window.table = $(dataTableSelector).DataTable({
    language: getDataTablesLanguageOptions() || undefined,

    data: piggyBanks,
    columns: [
        {
            data: 'name',
            title: __('Name'),
            render: function (data, type, row) {
                if (type === 'display') {
                    return `<a href="${route('piggy-bank.show', row.id)}">${data}</a>`;
                }
                return data;
            },
        },
        {
            data: 'target_amount',
            title: __('Target'),
            className: 'text-end',
            type: 'num',
            render: function (data, type) {
                if (type === 'display') {
                    return data.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                return data;
            },
        },
        {
            data: 'current_amount',
            title: __('Saved'),
            className: 'text-end',
            type: 'num',
            render: function (data, type) {
                if (type === 'display') {
                    return data.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                return data;
            },
        },
        {
            data: 'percentage',
            title: __('Progress'),
            render: function (data, type) {
                if (type === 'display') {
                    const pct = Math.min(100, parseFloat(data) || 0);
                    const barClass = pct >= 100 ? 'bg-success' : 'bg-primary';
                    return `<div class="progress" style="min-width:80px">
                        <div class="progress-bar ${barClass}" role="progressbar"
                            style="width:${pct}%"
                            aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100">
                            ${pct}%
                        </div>
                    </div>`;
                }
                return data;
            },
            className: 'align-middle',
            type: 'num',
        },
        {
            data: 'target_date',
            title: __('Target date'),
            className: 'text-center',
            render: function (data, type) {
                if (type === 'display' && data) {
                    return data;
                }
                return data ?? '';
            },
        },
        {
            data: 'active',
            title: __('Active'),
            className: 'text-center',
            render: function (data, type) {
                if (type === 'display') {
                    return data
                        ? '<i class="fa fa-check text-success"></i>'
                        : '<i class="fa fa-times text-danger"></i>';
                }
                return data ? 1 : 0;
            },
        },
        {
            data: 'id',
            title: __('Actions'),
            render: function (data) {
                return (
                    `<a href="${route('piggy-bank.show', data)}" class="btn btn-xs btn-success" title="${__('View')}"><i class="fa fa-fw fa-eye"></i></a> ` +
                    genericDataTablesActionButton(data, 'edit', 'piggy-bank.edit') +
                    genericDataTablesActionButton(data, 'delete')
                );
            },
            className: 'dt-nowrap',
            orderable: false,
            searchable: false,
        },
    ],
    order: [[0, 'asc']],
    deferRender: true,
    scrollY: '500px',
    scrollCollapse: true,
    stateSave: false,
    processing: true,
    paging: false,
});

initializeDeleteButtonListener(dataTableSelector, 'piggy-bank.destroy');

// Filter listeners
$('input[name=table_filter_active]').on('change', function () {
    table.column(5).search(this.value).draw();
});
$('#table_filter_search_text').keyup(function () {
    table.search($(this).val()).draw();
});
