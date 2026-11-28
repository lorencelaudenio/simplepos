/* Sales history: filters, summaries, Chart.js chart, receipts and Excel export. */
(function () {
  'use strict';

  const $ = function (id) { return document.getElementById(id); };
  let chart = null;
  let filter = 'today';

  function esc(value) { return window.POS.esc(value); }
  function money(value) { return window.POS.money(value); }

  function query() {
    const params = { action: 'dashboard', filter: filter };
    if (filter === 'custom') {
      params.from = $('date-from').value;
      params.to = $('date-to').value;
    }
    return window.POS.parseQuery(params);
  }

  function renderChart(labels, values) {
    const canvas = $('sales-chart');
    if (typeof window.Chart === 'undefined') return;
    if (chart) chart.destroy();
    chart = new window.Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Daily Sales',
          data: values,
          backgroundColor: 'rgba(21, 128, 61, .78)',
          borderColor: '#15803d',
          borderRadius: 6,
          maxBarThickness: 46
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function (context) { return money(context.parsed.y); } } }
        },
        scales: {
          y: { beginAtZero: true, ticks: { callback: function (value) { return money(value); } } },
          x: { grid: { display: false } }
        }
      }
    });
  }

  function renderSales(sales) {
    const body = $('sales-list');
    $('sales-count').textContent = sales.length + ' receipt' + (sales.length === 1 ? '' : 's');
    if (!sales.length) {
      body.innerHTML = '<tr><td colspan="7" class="center muted">No sales found for this period.</td></tr>';
      return;
    }
    body.innerHTML = sales.map(function (sale) {
      return '<tr><td><strong>' + esc(sale.receipt_number) + '</strong></td>'
        + '<td>' + esc(String(sale.created_at).replace('T', ' ')) + '</td>'
        + '<td>' + esc(sale.item_count) + '</td>'
        + '<td><strong>' + money(sale.total_amount) + '</strong></td>'
        + '<td>' + money(sale.cash_received) + '</td>'
        + '<td>' + money(sale.change_amount) + '</td>'
        + '<td><button class="btn btn-sm" data-id="' + esc(sale.id) + '" type="button"><i class="bi bi-receipt"></i> View Receipt</button></td></tr>';
    }).join('');
  }

  async function loadDashboard() {
    if (filter === 'custom' && (!$('date-from').value || !$('date-to').value)) {
      $('sales-list').innerHTML = '<tr><td colspan="7" class="center muted">Choose a custom date range.</td></tr>';
      return;
    }
    try {
      const data = await window.POS.api('api/reports?' + query());
      $('sum-total').textContent = money(data.summary.total);
      $('sum-receipts').textContent = String(data.summary.receipts);
      $('sum-average').textContent = money(data.summary.average);
      renderChart(data.chart.labels, data.chart.values);
      renderSales(data.sales);
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  function init() {
    if (!$('sales-filters')) return;
    document.querySelectorAll('#sales-filters .filter-btn').forEach(function (button) {
      button.addEventListener('click', function () {
        filter = button.dataset.filter;
        document.querySelectorAll('#sales-filters .filter-btn').forEach(function (item) {
          item.classList.toggle('active', item === button);
        });
        $('custom-range').classList.toggle('hidden', filter !== 'custom');
        if (filter === 'custom') {
          const today = new Date().toISOString().slice(0, 10);
          if (!$('date-from').value) $('date-from').value = today;
          if (!$('date-to').value) $('date-to').value = today;
        }
        loadDashboard();
      });
    });
    $('apply-custom').addEventListener('click', loadDashboard);
    $('sales-list').addEventListener('click', function (event) {
      const button = event.target.closest('button[data-id]');
      if (button && window.Cashier) window.Cashier.loadReceipt(Number(button.dataset.id));
    });
    $('export-sales-btn').addEventListener('click', function () {
      const params = { action: 'export', filter: filter };
      if (filter === 'custom') {
        params.from = $('date-from').value;
        params.to = $('date-to').value;
      }
      window.POS.downloadFile('api/reports?' + window.POS.parseQuery(params))
        .catch(function (error) { window.POS.toast(error.message, 'error'); });
    });
  }

  window.Sales = { init: init, onShow: loadDashboard };
})();
