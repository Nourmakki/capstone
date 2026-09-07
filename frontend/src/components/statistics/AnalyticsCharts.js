import React from "react";
import { RefreshCw } from "lucide-react";

export const CHART_COLORS = ["#00c2d1", "#8b5cf6", "#f59e0b", "#00b894", "#ef5da8", "#60a5fa"];

export function AnalyticsHeader({ title, description, updatedAt, onRefresh, refreshing }) {
    const updated = updatedAt
        ? new Date(updatedAt).toLocaleString([], { dateStyle: "medium", timeStyle: "short" })
        : null;

    return (
        <div className="mb-8 flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p className="text-xs font-bold uppercase tracking-[0.24em] text-[#00b0c2]">Analytics</p>
                <h1 className="mt-2 text-3xl font-black text-white lg:text-5xl">{title}</h1>
                <p className="mt-3 max-w-3xl text-gray-400">{description}</p>
            </div>

            <div className="flex items-center gap-3">
                {updated && <span className="text-xs text-gray-500">Updated {updated}</span>}
                <button
                    type="button"
                    onClick={onRefresh}
                    disabled={refreshing}
                    className="inline-flex items-center gap-2 rounded-xl border border-[#0097a7]/30 bg-[#0097a7]/10 px-4 py-2 text-sm font-semibold text-[#39d7e4] transition hover:bg-[#0097a7]/20 disabled:opacity-50"
                >
                    <RefreshCw size={15} className={refreshing ? "animate-spin" : ""} />
                    Refresh
                </button>
            </div>
        </div>
    );
}

export function ChartCard({ title, description, children, className = "" }) {
    return (
        <section className={`rounded-3xl border border-white/10 bg-[#090d12]/90 p-5 shadow-[0_24px_70px_rgba(0,0,0,0.25)] sm:p-7 ${className}`}>
            <div className="mb-6">
                <h2 className="text-lg font-bold text-white">{title}</h2>
                {description && <p className="mt-1 text-sm text-gray-500">{description}</p>}
            </div>
            {children}
        </section>
    );
}

export function LineChart({ data, colors = CHART_COLORS }) {
    const labels = data?.labels ?? [];
    const series = data?.series ?? [];

    if (labels.length === 0 || series.length === 0) return <EmptyChart />;

    const width = 760;
    const height = 290;
    const left = 45;
    const right = 18;
    const top = 18;
    const bottom = 48;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const maximum = niceMaximum(Math.max(1, ...series.flatMap((line) => line.values ?? [])));
    const x = (index) => left + (labels.length === 1 ? plotWidth / 2 : (index / (labels.length - 1)) * plotWidth);
    const y = (value) => top + plotHeight - (Number(value ?? 0) / maximum) * plotHeight;
    const shownLabelEvery = Math.max(1, Math.ceil(labels.length / 6));

    return (
        <div>
            <div className="overflow-x-auto">
                <svg viewBox={`0 0 ${width} ${height}`} className="min-w-[620px] w-full" role="img" aria-label="Time-series chart">
                    {[0, 1, 2, 3, 4].map((step) => {
                        const value = Math.round((maximum / 4) * (4 - step));
                        const gridY = top + (plotHeight / 4) * step;
                        return (
                            <g key={step}>
                                <line x1={left} y1={gridY} x2={width - right} y2={gridY} stroke="#24303a" strokeWidth="1" />
                                <text x={left - 10} y={gridY + 4} textAnchor="end" fill="#6b7280" fontSize="11">{value}</text>
                            </g>
                        );
                    })}

                    {labels.map((label, index) => (
                        (index % shownLabelEvery === 0 || index === labels.length - 1) && (
                            <text key={`${label}-${index}`} x={x(index)} y={height - 16} textAnchor="middle" fill="#6b7280" fontSize="10">
                                {label}
                            </text>
                        )
                    ))}

                    {series.map((line, lineIndex) => {
                        const points = (line.values ?? []).map((value, index) => `${x(index)},${y(value)}`).join(" ");
                        const color = colors[lineIndex % colors.length];
                        return (
                            <g key={line.name}>
                                <polyline points={points} fill="none" stroke={color} strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
                                {(line.values ?? []).map((value, index) => (
                                    <circle key={index} cx={x(index)} cy={y(value)} r="4" fill="#090d12" stroke={color} strokeWidth="2">
                                        <title>{`${line.name} · ${labels[index]}: ${value}`}</title>
                                    </circle>
                                ))}
                            </g>
                        );
                    })}
                </svg>
            </div>
            <Legend items={series.map((line, index) => ({ label: line.name, color: colors[index % colors.length] }))} />
        </div>
    );
}

export function ColumnChart({ items, series, maximum: requestedMaximum }) {
    if (!items || items.length === 0) return <EmptyChart />;

    const width = 780;
    const height = 330;
    const left = 48;
    const right = 16;
    const top = 16;
    const bottom = 82;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const values = items.flatMap((item) => series.map((entry) => Number(item[entry.key] ?? 0)));
    const maximum = requestedMaximum ?? niceMaximum(Math.max(1, ...values));
    const groupWidth = plotWidth / items.length;
    const barWidth = Math.min(32, (groupWidth * 0.72) / series.length);

    return (
        <div>
            <div className="overflow-x-auto">
                <svg viewBox={`0 0 ${width} ${height}`} className="min-w-[650px] w-full" role="img" aria-label="Column chart">
                    {[0, 1, 2, 3, 4].map((step) => {
                        const value = Math.round((maximum / 4) * (4 - step));
                        const gridY = top + (plotHeight / 4) * step;
                        return (
                            <g key={step}>
                                <line x1={left} y1={gridY} x2={width - right} y2={gridY} stroke="#24303a" />
                                <text x={left - 10} y={gridY + 4} textAnchor="end" fill="#6b7280" fontSize="11">{value}</text>
                            </g>
                        );
                    })}

                    {items.map((item, itemIndex) => {
                        const groupX = left + itemIndex * groupWidth + groupWidth / 2;
                        return (
                            <g key={`${item.label}-${itemIndex}`}>
                                {series.map((entry, seriesIndex) => {
                                    const value = Number(item[entry.key] ?? 0);
                                    const barHeight = (value / maximum) * plotHeight;
                                    const barX = groupX - (barWidth * series.length) / 2 + seriesIndex * barWidth;
                                    return (
                                        <rect
                                            key={entry.key}
                                            x={barX + 2}
                                            y={top + plotHeight - barHeight}
                                            width={Math.max(2, barWidth - 4)}
                                            height={barHeight}
                                            rx="5"
                                            fill={entry.color}
                                        >
                                            <title>{`${item.label} · ${entry.label}: ${value}`}</title>
                                        </rect>
                                    );
                                })}
                                <text
                                    x={groupX}
                                    y={height - 62}
                                    transform={`rotate(-35 ${groupX} ${height - 62})`}
                                    textAnchor="end"
                                    fill="#8b949e"
                                    fontSize="10"
                                >
                                    {shortLabel(item.label, 18)}
                                </text>
                            </g>
                        );
                    })}
                </svg>
            </div>
            <Legend items={series} />
        </div>
    );
}

export function DonutChart({ items, centerLabel = "Total", colors = CHART_COLORS }) {
    const total = (items ?? []).reduce((sum, item) => sum + Number(item.value ?? 0), 0);
    if (!items || items.length === 0 || total === 0) return <EmptyChart />;

    const radius = 72;
    const circumference = 2 * Math.PI * radius;
    let offset = 0;

    return (
        <div className="grid items-center gap-8 sm:grid-cols-[230px_1fr]">
            <svg viewBox="0 0 220 220" className="mx-auto h-56 w-56" role="img" aria-label={`${centerLabel}: ${total}`}>
                <circle cx="110" cy="110" r={radius} fill="none" stroke="#1d2730" strokeWidth="24" />
                <g transform="rotate(-90 110 110)">
                    {items.map((item, index) => {
                        const length = (Number(item.value ?? 0) / total) * circumference;
                        const circle = (
                            <circle
                                key={item.label}
                                cx="110"
                                cy="110"
                                r={radius}
                                fill="none"
                                stroke={colors[index % colors.length]}
                                strokeWidth="24"
                                strokeDasharray={`${Math.max(0, length - 2)} ${circumference}`}
                                strokeDashoffset={-offset}
                            >
                                <title>{`${item.label}: ${item.value}`}</title>
                            </circle>
                        );
                        offset += length;
                        return circle;
                    })}
                </g>
                <text x="110" y="105" textAnchor="middle" fill="#ffffff" fontSize="30" fontWeight="800">{total}</text>
                <text x="110" y="128" textAnchor="middle" fill="#8b949e" fontSize="12">{centerLabel}</text>
            </svg>

            <div className="space-y-3">
                {items.map((item, index) => (
                    <div key={item.label} className="flex items-center justify-between gap-4 text-sm">
                        <span className="flex min-w-0 items-center gap-2 text-gray-400">
                            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: colors[index % colors.length] }} />
                            <span className="truncate">{item.label}</span>
                        </span>
                        <span className="font-bold text-white">{item.value}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export function ScatterChart({ items, xKey, yKey, sizeKey, xLabel, yLabel }) {
    if (!items || items.length === 0) return <EmptyChart />;

    const width = 760;
    const height = 310;
    const left = 55;
    const right = 24;
    const top = 20;
    const bottom = 52;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const maxX = niceMaximum(Math.max(1, ...items.map((item) => Number(item[xKey] ?? 0))));
    const maxY = niceMaximum(Math.max(1, ...items.map((item) => Number(item[yKey] ?? 0))));

    return (
        <div className="overflow-x-auto">
            <svg viewBox={`0 0 ${width} ${height}`} className="min-w-[620px] w-full" role="img" aria-label={`${yLabel} by ${xLabel}`}>
                {[0, 1, 2, 3, 4].map((step) => {
                    const gridY = top + (plotHeight / 4) * step;
                    const value = Math.round((maxY / 4) * (4 - step));
                    return (
                        <g key={step}>
                            <line x1={left} y1={gridY} x2={width - right} y2={gridY} stroke="#24303a" />
                            <text x={left - 10} y={gridY + 4} textAnchor="end" fill="#6b7280" fontSize="11">{value}</text>
                        </g>
                    );
                })}
                {[0, 1, 2, 3, 4].map((step) => {
                    const gridX = left + (plotWidth / 4) * step;
                    const value = Math.round((maxX / 4) * step);
                    return (
                        <g key={step}>
                            <line x1={gridX} y1={top} x2={gridX} y2={top + plotHeight} stroke="#1a232c" />
                            <text x={gridX} y={height - 24} textAnchor="middle" fill="#6b7280" fontSize="11">{value}</text>
                        </g>
                    );
                })}

                {items.map((item, index) => {
                    const cx = left + (Number(item[xKey] ?? 0) / maxX) * plotWidth;
                    const cy = top + plotHeight - (Number(item[yKey] ?? 0) / maxY) * plotHeight;
                    const radiusValue = Math.min(17, 7 + Math.sqrt(Number(item[sizeKey] ?? 0)) * 1.8);
                    return (
                        <circle key={`${item.label}-${index}`} cx={cx} cy={cy} r={radiusValue} fill={CHART_COLORS[index % CHART_COLORS.length]} fillOpacity="0.75" stroke="#ffffff" strokeOpacity="0.5">
                            <title>{`${item.label} · ${xLabel}: ${item[xKey]}, ${yLabel}: ${item[yKey]}, completions: ${item[sizeKey]}`}</title>
                        </circle>
                    );
                })}

                <text x={left + plotWidth / 2} y={height - 3} textAnchor="middle" fill="#8b949e" fontSize="11">{xLabel}</text>
                <text x="13" y={top + plotHeight / 2} textAnchor="middle" fill="#8b949e" fontSize="11" transform={`rotate(-90 13 ${top + plotHeight / 2})`}>{yLabel}</text>
            </svg>
        </div>
    );
}

export function AnalyticsState({ loading, error, children }) {
    if (loading) return <div className="py-28 text-center text-gray-400">Preparing live analytics...</div>;
    if (error) return <div className="rounded-2xl border border-red-500/20 bg-red-500/5 p-5 text-red-300">{error}</div>;
    return children;
}

function Legend({ items }) {
    return (
        <div className="mt-5 flex flex-wrap justify-center gap-x-5 gap-y-2">
            {items.map((item, index) => (
                <span key={`${item.label}-${index}`} className="flex items-center gap-2 text-xs text-gray-400">
                    <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: item.color }} />
                    {item.label}
                </span>
            ))}
        </div>
    );
}

function EmptyChart() {
    return <div className="grid h-64 place-items-center rounded-2xl border border-dashed border-white/10 text-sm text-gray-500">Not enough recorded activity yet.</div>;
}

function niceMaximum(value) {
    if (value <= 5) return 5;
    if (value <= 10) return 10;
    if (value <= 25) return 25;
    if (value <= 50) return 50;
    if (value <= 100) return 100;
    return Math.ceil(value / 100) * 100;
}

function shortLabel(value, length) {
    if (!value || value.length <= length) return value;
    return `${value.slice(0, length - 1)}…`;
}
