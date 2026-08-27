import { Controller } from '@hotwired/stimulus';

const DOT_STATE = { pending: 'bg-ink-muted animate-pulse', up: 'bg-signal', down: 'bg-alert' };
const LABEL_COLOR = { pending: 'text-ink-muted', up: 'text-signal-dark', down: 'text-alert' };
const BAR_COLOR = { up: 'bg-signal', pending: 'bg-ink-muted animate-pulse', down: 'bg-alert' };

function cell(text, className) {
    const td = document.createElement('td');
    td.className = className;
    td.textContent = text;
    return td;
}

function badgeCell(text, badgeClass) {
    const td = document.createElement('td');
    td.className = 'px-6 py-3';
    const span = document.createElement('span');
    span.className = `px-2 py-0.5 rounded text-[10px] font-semibold ${badgeClass}`;
    span.textContent = text;
    td.appendChild(span);
    return td;
}

function pill(label, passed) {
    const span = document.createElement('span');
    span.className = `px-1 py-0.5 rounded ${passed ? 'bg-signal/10 text-signal-dark' : 'bg-paper-dim text-ink-muted'}`;
    span.textContent = label;
    return span;
}

/*
 * Polls the monitor detail page's status/chart/history-table/incident-log
 * sections together from one JSON snapshot (DashboardController::live()),
 * so they can never disagree with each other — e.g. a status badge showing
 * a check the table below doesn't have yet.
 *
 * Rendering never uses innerHTML: check/SMTP error messages are arbitrary,
 * untrusted text (HTTP response snippets, regex-failure messages, SMTP
 * bounce text), always set via .textContent.
 */
export default class extends Controller {
    static targets = ['status', 'chart', 'table', 'incidents'];
    static values = { url: String, interval: { type: Number, default: 15000 } };

    connect() {
        this.pollSeq = 0;
        this.timer = setInterval(() => this.poll(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async poll() {
        const seq = ++this.pollSeq;
        let response;
        try {
            response = await fetch(this.urlValue);
        } catch {
            return;
        }
        if (!response.ok) {
            return;
        }
        const data = await response.json();
        if (seq !== this.pollSeq) {
            return;
        }
        this.render(data);
    }

    render(data) {
        this.renderStatus(data.status, data.monitorType);
        this.renderChart(data.chart);
        this.renderTable(data.monitorType, data.monitorType === 'smtp' ? data.smtpTests : data.checks);
        this.renderIncidents(data.incidents);
    }

    renderStatus(status, monitorType) {
        const target = this.statusTarget;
        target.replaceChildren();

        const dot = document.createElement('span');
        let dotClass = `h-4 w-4 rounded-full ${DOT_STATE[status.state]}`;
        if (status.state === 'down' && monitorType === 'http') {
            dotClass += ' animate-ping';
        }
        dot.className = dotClass;

        const label = document.createElement('span');
        label.className = `text-lg font-body font-bold ${LABEL_COLOR[status.state]}`;
        label.textContent = status.label;

        target.append(dot, label);

        if (status.meta) {
            const meta = document.createElement('span');
            meta.className = 'text-ink-muted text-xs';
            meta.textContent = status.meta;
            target.appendChild(meta);
        }

        if (status.errorMessage) {
            const error = document.createElement('span');
            error.className = 'text-xs text-alert bg-alert/5 px-2 py-0.5 rounded ml-2';
            error.textContent = status.errorMessage;
            target.appendChild(error);
        }
    }

    renderChart(bars) {
        const target = this.chartTarget;
        target.replaceChildren();

        if (bars.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-sm text-ink-muted text-center py-6';
            empty.textContent = 'Insufficient check data to render metrics.';
            target.appendChild(empty);
            return;
        }

        const barsContainer = document.createElement('div');
        barsContainer.className = 'flex items-end gap-1 h-32 pt-4 border-b border-line';
        for (const bar of bars) {
            const barEl = document.createElement('div');
            barEl.className = `${BAR_COLOR[bar.state]} rounded-t flex-1`;
            barEl.style.height = `${bar.heightPercent}%`;
            barEl.title = bar.title;
            barsContainer.appendChild(barEl);
        }

        const labels = document.createElement('div');
        labels.className = 'flex justify-between text-xs text-ink-muted mt-2';
        const older = document.createElement('span');
        older.textContent = 'Older';
        const latest = document.createElement('span');
        latest.textContent = 'Latest';
        labels.append(older, latest);

        target.append(barsContainer, labels);
    }

    renderTable(monitorType, rows) {
        const tbody = this.tableTarget;
        tbody.replaceChildren();

        for (const row of rows) {
            const tr = document.createElement('tr');

            if (monitorType === 'http') {
                tr.appendChild(cell(row.checkedAt, 'px-6 py-3 font-mono text-ink-muted'));
                tr.appendChild(badgeCell(row.status.toUpperCase(), row.status === 'up' ? 'bg-signal/10 text-signal-dark' : 'bg-alert/10 text-alert'));
                tr.appendChild(cell(`${row.responseTimeMs} ms`, 'px-6 py-3 font-mono text-ink'));
                tr.appendChild(cell(row.errorMessage || '--', 'px-6 py-3 text-ink-muted truncate max-w-xs'));
            } else {
                tr.appendChild(cell(row.sentAt, 'px-6 py-3 font-mono text-ink-muted'));
                tr.appendChild(cell(row.receivedAt || '--', 'px-6 py-3 font-mono text-ink-muted'));
                tr.appendChild(cell(row.deliveryTimeSeconds ? `${row.deliveryTimeSeconds} s` : '--', 'px-6 py-3 font-mono text-ink'));

                const badgeClass = row.status === 'delivered'
                    ? 'bg-signal/10 text-signal-dark'
                    : (row.status === 'sent' ? 'bg-paper-dim text-ink-muted' : 'bg-alert/10 text-alert');
                tr.appendChild(badgeCell(row.status.toUpperCase(), badgeClass));

                const headersTd = document.createElement('td');
                headersTd.className = 'px-6 py-3 text-ink-muted';
                const wrap = document.createElement('div');
                wrap.className = 'flex gap-2';
                wrap.append(pill('SPF', row.spfPassed === true), pill('DKIM', row.dkimPassed === true));
                headersTd.appendChild(wrap);
                tr.appendChild(headersTd);
            }

            tbody.appendChild(tr);
        }
    }

    renderIncidents(incidents) {
        const target = this.incidentsTarget;
        target.replaceChildren();

        if (incidents.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-xs text-ink-muted';
            empty.textContent = 'No recent alert events logged.';
            target.appendChild(empty);
            return;
        }

        const ul = document.createElement('ul');
        ul.className = 'space-y-3';

        for (const inc of incidents) {
            const li = document.createElement('li');
            li.className = 'text-[10px] bg-paper-dim p-2 rounded border border-line';

            const row1 = document.createElement('div');
            row1.className = 'flex justify-between items-center mb-1';
            const idSpan = document.createElement('span');
            idSpan.className = 'font-mono font-bold text-alert';
            idSpan.textContent = `INCIDENT #${inc.id}`;
            const triggerSpan = document.createElement('span');
            triggerSpan.className = 'font-semibold text-ink-muted';
            triggerSpan.textContent = `Trigger: ${inc.conditionType}`;
            row1.append(idSpan, triggerSpan);

            const row2 = document.createElement('div');
            row2.className = 'text-ink-muted mb-1';
            row2.textContent = `Triggered at ${inc.triggeredAt}`;

            const row3 = document.createElement('div');
            row3.className = 'flex justify-between items-center text-[9px] text-ink-muted';
            const notifiedSpan = document.createElement('span');
            notifiedSpan.textContent = `Notified: ${inc.notified ? 'Yes' : 'No'}`;
            const statusSpan = document.createElement('span');
            statusSpan.className = `font-mono ${inc.status === 'resolved' ? 'text-signal-dark font-bold' : 'text-ink-muted font-bold'}`;
            statusSpan.textContent = `${inc.status.toUpperCase()}${inc.resolvedAt ? ` (at ${inc.resolvedAt})` : ''}`;
            row3.append(notifiedSpan, statusSpan);

            li.append(row1, row2, row3);
            ul.appendChild(li);
        }

        target.appendChild(ul);
    }
}
