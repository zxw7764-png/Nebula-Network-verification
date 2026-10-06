function num(v) {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
}

export function fmtNum(n) {
    n = num(n);
    if (Math.abs(n) >= 100000000) return (n / 100000000).toFixed(2) + '亿';
    if (Math.abs(n) >= 10000) return (n / 10000).toFixed(2) + '万';
    // 小数（如刻度 0.30000000000000004）统一保留 2 位，避免可视化出现浮点尾巴
    if (!Number.isInteger(n)) return String(Math.round(n * 100) / 100);
    return String(n);
}

function escXml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

function axisScale(values) {
    let max = Math.max(1, ...values.map(num));
    let min = 0;

    max = max * 1.1;
    const rawStep = max / 4;
    const mag = Math.pow(10, Math.floor(Math.log10(rawStep) || 0));
    const step = Math.ceil(rawStep / mag) * mag;
    max = step * 4;
    const ticks = [];
    for (let i = 0; i <= 4; i++) {
        // 浮点累加会产出 0.30000000000000004；这里收敛到 6 位小数，消除尾巴
        ticks.push(Math.round((min + step * i) * 1e6) / 1e6);
    }
    return { min, max, step, ticks };
}

export function areaChart(data, opts = {}) {
    const w = opts.w || 660;
    const h = opts.h || 200;
    const padL = 46, padR = 12, padT = 14, padB = 26;
    const color = opts.color || 'var(--primary-strong)';
    const fill = opts.fill || 'rgba(79,70,229,.14)';
    const unit = opts.unit || '';

    if (!data || !data.length) {
        return `<div style="height:${h}px;display:flex;align-items:center;justify-content:center;color:var(--text-faint);font-size:13px">暂无数据</div>`;
    }

    const innerW = w - padL - padR;
    const innerH = h - padT - padB;
    const scale = axisScale(data.map(d => d.value));

    const xy = data.map((d, i) => {
        const x = padL + (data.length === 1 ? innerW / 2 : i * innerW / (data.length - 1));
        const y = padT + innerH - (num(d.value) - scale.min) / (scale.max - scale.min) * innerH;
        return [x, y];
    });

    const line = xy.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
    const area = `${line} L${xy[xy.length - 1][0].toFixed(1)},${(padT + innerH).toFixed(1)} `
        + `L${xy[0][0].toFixed(1)},${(padT + innerH).toFixed(1)} Z`;

    let grid = '';
    scale.ticks.forEach(t => {
        const y = padT + innerH - (t - scale.min) / (scale.max - scale.min) * innerH;
        grid += `<line x1="${padL}" y1="${y.toFixed(1)}" x2="${w - padR}" y2="${y.toFixed(1)}" `
              + `stroke="var(--chart-grid)" stroke-width="1"/>`
              + `<text x="${padL - 7}" y="${(y + 4).toFixed(1)}" text-anchor="end" `
              + `font-size="11" fill="var(--text-faint)">${fmtNum(t)}</text>`;
    });

    const stepX = Math.max(1, Math.ceil(data.length / 8));
    let xlabels = '';
    data.forEach((d, i) => {
        if (i % stepX !== 0 && i !== data.length - 1) return;
        xlabels += `<text x="${xy[i][0].toFixed(1)}" y="${h - 7}" text-anchor="middle" `
                 + `font-size="11" fill="var(--text-faint)">${escXml(d.label)}</text>`;
    });

    const dots = xy.map((p, i) => (i === xy.length - 1 ? ''
        : '') + `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="2" fill="${color}"/>`).join('');

    const last = data[data.length - 1];

    return `
    <svg viewBox="0 0 ${w} ${h}" width="100%" height="${h}" preserveAspectRatio="none" style="display:block">
        ${grid}
        <path d="${area}" fill="${fill}" stroke="none"/>
        <path d="${line}" fill="none" stroke="${color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
        ${dots}
        ${xlabels}
        <text x="${padL}" y="${padT - 2}" font-size="11" fill="var(--text-faint)">
            ${escXml((opts.seriesName || '数值') + '：' + fmtNum(last.value) + unit)}
        </text>
    </svg>`;
}

export function multiLineChart(data, series, opts = {}) {
    const w = opts.w || 660;
    const h = opts.h || 210;
    const padL = 46, padR = 12, padT = 30, padB = 26;

    if (!data || !data.length) {
        return `<div style="height:${h}px;display:flex;align-items:center;justify-content:center;color:var(--text-faint);font-size:13px">暂无数据</div>`;
    }

    const all = [];
    data.forEach(d => series.forEach(s => all.push(num(d[s.key]))));
    const innerW = w - padL - padR;
    const innerH = h - padT - padB;
    const scale = axisScale(all);

    const px = i => padL + (data.length === 1 ? innerW / 2 : i * innerW / (data.length - 1));
    const py = v => padT + innerH - (num(v) - scale.min) / (scale.max - scale.min) * innerH;

    let grid = '';
    scale.ticks.forEach(t => {
        const y = py(t);
        grid += `<line x1="${padL}" y1="${y.toFixed(1)}" x2="${w - padR}" y2="${y.toFixed(1)}" `
              + `stroke="var(--chart-grid)" stroke-width="1"/>`
              + `<text x="${padL - 7}" y="${(y + 4).toFixed(1)}" text-anchor="end" `
              + `font-size="11" fill="var(--text-faint)">${fmtNum(t)}</text>`;
    });

    const stepX = Math.max(1, Math.ceil(data.length / 8));
    let xlabels = '';
    data.forEach((d, i) => {
        if (i % stepX !== 0 && i !== data.length - 1) return;
        xlabels += `<text x="${px(i).toFixed(1)}" y="${h - 7}" text-anchor="middle" `
                 + `font-size="11" fill="var(--text-faint)">${escXml(d.label)}</text>`;
    });

    let paths = '';
    series.forEach(s => {
        const d = data.map((row, i) => (i ? 'L' : 'M') + px(i).toFixed(1) + ',' + py(row[s.key]).toFixed(1)).join(' ');
        paths += `<path d="${d}" fill="none" stroke="${s.color}" stroke-width="2" `
               + `stroke-linejoin="round" stroke-linecap="round"/>`;
    });

    let legend = '';
    series.forEach((s, i) => {
        const x = padL + i * 108;
        legend += `<rect x="${x}" y="8" width="10" height="10" rx="2" fill="${s.color}"/>`
                + `<text x="${x + 15}" y="17" font-size="11" fill="var(--text-sub)">${escXml(s.name)}</text>`;
    });

    return `
    <svg viewBox="0 0 ${w} ${h}" width="100%" height="${h}" preserveAspectRatio="none" style="display:block">
        ${grid}${paths}${xlabels}${legend}
    </svg>`;
}

export function rankingBars(items, opts = {}) {
    if (!items || !items.length) {
        return `<div class="empty" style="padding:24px 0">暂无数据</div>`;
    }
    const max = Math.max(1, ...items.map(i => num(i.value)));
    const color = opts.color || 'var(--primary-strong)';
    return items.map((it, idx) => {
        const pct = num(it.value) / max * 100;
        return `
        <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;gap:10px">
                <span style="display:flex;gap:7px;align-items:center;min-width:0">
                    <b style="color:var(--text-faint);font-weight:600;width:16px;flex:none">${idx + 1}</b>
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escXml(it.name)}</span>
                </span>
                <span class="mono" style="color:var(--text);flex:none">${escXml(it.sub != null ? it.sub : fmtNum(it.value))}</span>
            </div>
            <div style="height:7px;background:var(--chart-grid);border-radius:4px;overflow:hidden">
                <i style="display:block;height:100%;width:${pct.toFixed(1)}%;background:${color};border-radius:4px"></i>
            </div>
        </div>`;
    }).join('');
}

export function donutChart(items, opts = {}) {
    const size = opts.size || 168;
    const stroke = opts.stroke || 22;
    const total = items.reduce((s, i) => s + num(i.value), 0);
    if (!items || !total) {
        return `<div style="height:${size}px;display:flex;align-items:center;justify-content:center;color:var(--text-faint);font-size:13px">暂无数据</div>`;
    }
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    let offset = 0;
    let arcs = '';
    items.forEach(it => {
        const frac = num(it.value) / total;
        const len = frac * c;
        arcs += `<circle cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none"
                    stroke="${it.color}" stroke-width="${stroke}"
                    stroke-dasharray="${len.toFixed(2)} ${(c - len).toFixed(2)}"
                    stroke-dashoffset="${(-offset).toFixed(2)}"
                    transform="rotate(-90 ${size / 2} ${size / 2})"/>`;
        offset += len;
    });

    const legend = items.map(it => `
        <div style="display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:7px">
            <i style="width:9px;height:9px;border-radius:2px;background:${it.color};display:inline-block;flex:none"></i>
            <span style="color:var(--text-sub);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escXml(it.name)}</span>
            <b class="mono" style="color:var(--text)">${fmtNum(it.value)}</b>
        </div>`).join('');

    return `
    <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
        <div style="position:relative;flex:none">
            <svg viewBox="0 0 ${size} ${size}" width="${size}" height="${size}">
                <circle cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none" stroke="var(--chart-grid)" stroke-width="${stroke}"/>
                ${arcs}
            </svg>
            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
                <b style="font-size:20px;color:var(--text)">${fmtNum(total)}</b>
                <span style="font-size:11px;color:var(--text-faint)">${escXml(opts.centerLabel || '合计')}</span>
            </div>
        </div>
        <div style="flex:1;min-width:150px">${legend}</div>
    </div>`;
}

export const PALETTE = ['var(--primary-strong)', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16'];
