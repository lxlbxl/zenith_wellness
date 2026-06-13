import React, { useEffect, useState, useCallback, useRef } from 'react';
import { api } from '../../services/api';
import {
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
    LineChart, Line, Legend, Cell
} from 'recharts';

// ─── Types ───────────────────────────────────────────────────────

interface Experiment {
    id: string;
    key_slug: string;
    name: string;
    surface: string;
    status: string;
    allocation_mode: string;
    primary_goal_event: string;
    exploration_floor: string;
    min_samples_per_variant: number;
    confidence_threshold: string;
    holdout: number;
    auto_promote: number;
    started_at: string | null;
    ended_at: string | null;
    created_at: string;
    updated_at: string;
    variant_count: number;
    assignment_count: number;
    description?: string;
    variants?: Variant[];
}

interface Variant {
    id: string;
    key_slug: string;
    name: string;
    is_control: number;
    config: Record<string, unknown> | null;
    fixed_weight: number | null;
    is_active: number;
    created_at: string;
}

interface VariantReport {
    variant_id: string;
    key_slug: string;
    name: string;
    is_control: number;
    exposures: number;
    conversions: number;
    conversion_rate: number;
    revenue_cents: number;
    revenue_per_visitor: number;
    alpha: number;
    beta: number;
    prob_best: number;
    funnel_breakdown: Record<string, number>;
}

interface ComparisonReport {
    variant_id: string;
    key_slug: string;
    name: string;
    control_rate: number;
    variant_rate: number;
    absolute_difference: number;
    relative_uplift: number | null;
    uplift_ci_lower: number | null;
    uplift_ci_upper: number | null;
    expected_loss: number;
    z_test_p_value: number;
    prob_win: number;
}

interface Report {
    experiment: {
        id: string;
        key_slug: string;
        name: string;
        surface: string;
        status: string;
        allocation_mode: string;
        primary_goal_event: string;
        started_at: string | null;
        ended_at: string | null;
    };
    segment_key: string;
    variants: VariantReport[];
    comparisons: ComparisonReport[];
}

interface DecisionEntry {
    type: 'decision';
    timestamp: string;
    decision_type: string;
    actor: string;
    winning_variant: { key_slug: string; name: string } | null;
    rationale: Record<string, unknown> | null;
}

interface EventMilestoneEntry {
    type: 'event_milestone';
    event_type: string;
    is_goal: number;
    count: number;
    first_at: string;
    last_at: string;
}

type TimelineEntry = DecisionEntry | EventMilestoneEntry;

interface SegmentStats {
    segment_key: string;
    variants: Array<{
        key_slug: string;
        name: string;
        is_control: number;
        exposures: number;
        conversions: number;
        conversion_rate: number;
        prob_best: number;
        revenue_per_visitor: number;
    }>;
}

// ─── Helpers ────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-600',
    running: 'bg-emerald-100 text-emerald-700',
    paused: 'bg-amber-100 text-amber-700',
    shipped: 'bg-indigo-100 text-indigo-700',
};

const STATUS_ICONS: Record<string, string> = {
    draft: 'fa-pen-ruler',
    running: 'fa-play',
    paused: 'fa-pause',
    shipped: 'fa-rocket',
};

function daysRunning(experiment: Experiment): number | null {
    if (!experiment.started_at) return null;
    const start = new Date(experiment.started_at);
    const end = experiment.ended_at ? new Date(experiment.ended_at) : new Date();
    return Math.max(1, Math.floor((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24)));
}

function formatPercent(v: number | null | undefined): string {
    if (v === null || v === undefined) return '—';
    return `${(v * 100).toFixed(1)}%`;
}

function formatUplift(v: number | null | undefined): string {
    if (v === null || v === undefined) return '—';
    const sign = v >= 0 ? '+' : '';
    return `${sign}${v.toFixed(2)}%`;
}

function formatCurrency(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

function formatDateTime(ts: string): string {
    return new Date(ts).toLocaleString();
}

function pValueLabel(p: number): string {
    if (p < 0.001) return 'p < 0.001***';
    if (p < 0.01) return 'p < 0.01**';
    if (p < 0.05) return 'p < 0.05*';
    return `p = ${p.toFixed(3)}`;
}

const FUNNEL_STEPS = ['quiz_start', 'lead_captured', 'checkout_open', 'payment_success'];
const FUNNEL_LABELS: Record<string, string> = {
    quiz_start: 'Quiz Start',
    lead_captured: 'Lead Captured',
    checkout_open: 'Checkout Open',
    payment_success: 'Payment Success',
};

// ─── Sub-components ─────────────────────────────────────────────

function NewExperimentModal({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
    const [form, setForm] = useState({
        key_slug: '',
        name: '',
        surface: '',
        description: '',
        primary_goal_event: '',
        min_samples_per_variant: 300,
        confidence_threshold: 0.95,
        allocation_mode: 'bandit',
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            await api.post('/admin/experiments', form);
            onCreated();
            onClose();
        } catch (err: any) {
            setError(err.message || 'Failed to create experiment');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-3xl p-8 w-full max-w-lg mx-4 shadow-2xl border border-slate-100" onClick={(e) => e.stopPropagation()}>
                <div className="flex justify-between items-center mb-6">
                    <h3 className="text-xl font-black text-slate-900">New Experiment</h3>
                    <button onClick={onClose} className="w-8 h-8 rounded-xl bg-slate-100 text-slate-500 hover:bg-slate-200 transition-all">
                        <i className="fa-solid fa-xmark"></i>
                    </button>
                </div>

                {error && (
                    <div className="mb-4 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm font-medium">
                        <i className="fa-solid fa-circle-exclamation mr-2"></i>{error}
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-bold text-slate-700 mb-1">Key Slug *</label>
                        <input
                            type="text" required placeholder="e.g. trust_hero_v2"
                            className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none"
                            value={form.key_slug} onChange={(e) => setForm({ ...form, key_slug: e.target.value })}
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-bold text-slate-700 mb-1">Name *</label>
                        <input
                            type="text" required placeholder="e.g. Trust Hero V2"
                            className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none"
                            value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-bold text-slate-700 mb-1">Surface *</label>
                        <input
                            type="text" required placeholder="e.g. sales_page"
                            className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none"
                            value={form.surface} onChange={(e) => setForm({ ...form, surface: e.target.value })}
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-bold text-slate-700 mb-1">Description</label>
                        <textarea
                            placeholder="What are you testing?"
                            className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none resize-none h-20"
                            value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })}
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-bold text-slate-700 mb-1">Primary Goal Event *</label>
                        <input
                            type="text" required placeholder="e.g. payment_success"
                            className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none"
                            value={form.primary_goal_event} onChange={(e) => setForm({ ...form, primary_goal_event: e.target.value })}
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-bold text-slate-700 mb-1">Min Samples</label>
                            <input
                                type="number" min={50} max={100000}
                                className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none"
                                value={form.min_samples_per_variant}
                                onChange={(e) => setForm({ ...form, min_samples_per_variant: parseInt(e.target.value) || 300 })}
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-bold text-slate-700 mb-1">Confidence</label>
                            <select
                                className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none bg-white"
                                value={form.confidence_threshold} onChange={(e) => setForm({ ...form, confidence_threshold: parseFloat(e.target.value) })}
                            >
                                <option value={0.90}>90%</option>
                                <option value={0.95}>95%</option>
                                <option value={0.99}>99%</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-bold text-slate-700 mb-1">Allocation Mode</label>
                        <select
                            className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none bg-white"
                            value={form.allocation_mode} onChange={(e) => setForm({ ...form, allocation_mode: e.target.value })}
                        >
                            <option value="bandit">Bandit (adaptive)</option>
                            <option value="fixed">Fixed (static split)</option>
                        </select>
                    </div>
                    <div className="flex gap-3 pt-2">
                        <button type="button" onClick={onClose}
                            className="flex-1 px-6 py-3 rounded-xl border border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50 transition-all">
                            Cancel
                        </button>
                        <button type="submit" disabled={saving}
                            className="flex-1 px-6 py-3 rounded-xl bg-slate-900 text-white font-bold text-sm hover:bg-slate-800 transition-all shadow-lg shadow-slate-200 disabled:opacity-50">
                            {saving ? <><i className="fa-solid fa-circle-notch fa-spin mr-2"></i>Creating...</> : 'Create Experiment'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function StatusBadge({ status }: { status: string }) {
    const color = STATUS_COLORS[status] || 'bg-slate-100 text-slate-600';
    const icon = STATUS_ICONS[status] || 'fa-circle';
    return (
        <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider ${color}`}>
            <i className={`fa-solid ${icon} text-[9px]`}></i>
            {status}
        </span>
    );
}

function ConfidenceBar({ prob, className }: { prob: number; className?: string }) {
    const pct = Math.round(prob * 100);
    const color = prob >= 0.95 ? 'bg-emerald-500' : prob >= 0.8 ? 'bg-amber-500' : 'bg-slate-300';
    return (
        <div className={`flex items-center gap-2 ${className || ''}`}>
            <div className="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">
                <div className={`h-full rounded-full ${color} transition-all duration-500`} style={{ width: `${pct}%` }} />
            </div>
            <span className="text-xs font-bold text-slate-600 w-10 text-right">{pct}%</span>
        </div>
    );
}

function SRMBadge({ hasSRM }: { hasSRM: boolean }) {
    if (!hasSRM) return null;
    return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-red-100 text-red-700 text-[10px] font-bold uppercase tracking-wider">
            <i className="fa-solid fa-triangle-exclamation"></i> SRM
        </span>
    );
}

function VariantColor(i: number): string {
    const colors = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];
    return colors[i % colors.length];
}

// ─── Main Component ──────────────────────────────────────────────

const ExperimentsDashboard: React.FC = () => {
    const [experiments, setExperiments] = useState<Experiment[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [showNewModal, setShowNewModal] = useState(false);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // Detail state
    const [report, setReport] = useState<Report | null>(null);
    const [timeline, setTimeline] = useState<TimelineEntry[]>([]);
    const [timeseries, setTimeseries] = useState<any[]>([]);
    const [segments, setSegments] = useState<SegmentStats[]>([]);
    const [detailLoading, setDetailLoading] = useState(false);
    const [actionLoading, setActionLoading] = useState<string | null>(null);

    // ─── Fetch list ─────────────────────────────────────────────

    const fetchList = useCallback(async () => {
        try {
            const data = await api.get<{ experiments: Experiment[] }>('/admin/experiments');
            setExperiments(data.experiments || []);
            setError('');
        } catch (err: any) {
            setError(err.message || 'Failed to load experiments');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchList();
    }, [fetchList]);

    // ─── 30s poll while tab is open ─────────────────────────────

    useEffect(() => {
        if (pollRef.current) clearInterval(pollRef.current);
        pollRef.current = setInterval(() => {
            fetchList();
            if (selectedId) {
                fetchDetail(selectedId);
            }
        }, 30000);
        return () => {
            if (pollRef.current) clearInterval(pollRef.current);
        };
    }, [selectedId]); // eslint-disable-line react-hooks/exhaustive-deps

    // ─── Fetch detail ──────────────────────────────────────────

    const fetchDetail = useCallback(async (id: string) => {
        setDetailLoading(true);
        try {
            const [reportData, timelineData, timeseriesData] = await Promise.all([
                api.get<Report>(`/admin/experiments/${id}/report`),
                api.get<{ timeline: TimelineEntry[] }>(`/admin/experiments/${id}/timeline`),
                api.get<{ timeseries: any[] }>(`/admin/experiments/${id}/timeseries`).catch(() => ({ timeseries: [] })),
            ]);
            setReport(reportData);
            setTimeline(timelineData.timeline || []);
            setTimeseries(timeseriesData.timeseries || []);

            // Build segment breakdowns from timeline
            // For a full implementation, we'd have a /segments endpoint
            // Here we derive from the report and any timeline segment data
            const segmentVariants = reportData.variants.map((v) => ({
                key_slug: v.key_slug,
                name: v.name,
                is_control: v.is_control,
                exposures: v.exposures,
                conversions: v.conversions,
                conversion_rate: v.conversion_rate,
                prob_best: v.prob_best,
                revenue_per_visitor: v.revenue_per_visitor,
            }));
            setSegments([{ segment_key: 'global', variants: segmentVariants }]);
        } catch (err: any) {
            console.error('Failed to load experiment detail', err);
        } finally {
            setDetailLoading(false);
        }
    }, []);

    useEffect(() => {
        if (selectedId) {
            fetchDetail(selectedId);
        }
    }, [selectedId, fetchDetail]);

    // ─── Actions ────────────────────────────────────────────────

    const performAction = async (action: string, body?: Record<string, unknown>) => {
        if (!selectedId) return;
        setActionLoading(action);
        try {
            await api.post(`/admin/experiments/${selectedId}/${action}`, body || {});
            await fetchList();
            await fetchDetail(selectedId);
        } catch (err: any) {
            alert(err.message || `Failed to ${action} experiment`);
        } finally {
            setActionLoading(null);
        }
    };

    const canShip = report && report.comparisons.length > 0;
    const isShipped = report?.experiment.status === 'shipped';

    // ─── Determine leading variant ─────────────────────────────

    function leadingVariant(exp: Experiment): { name: string; prob: number } | null {
        if (!report || report.experiment.id !== exp.id) return null;
        const best = [...report.variants].sort((a, b) => b.prob_best - a.prob_best)[0];
        return best && best.prob_best > 0.5 ? { name: best.name, prob: best.prob_best } : null;
    }

    function hasSRMFlag(exp: Experiment): boolean {
        return exp.status === 'running' && report?.experiment.id === exp.id && report.comparisons.some((c) => c.z_test_p_value < 0.001);
    }

    // ─── Render ─────────────────────────────────────────────────

    if (loading) {
        return (
            <div className="p-12 text-center text-slate-400">
                <i className="fa-solid fa-flask fa-spin text-3xl mb-4"></i>
                <p>Loading experiments...</p>
            </div>
        );
    }

    // ─── Detail View ─────────────────────────────────────────────

    if (selectedId) {
        const exp = experiments.find((e) => e.id === selectedId);
        if (!exp) {
            return (
                <div className="p-12 text-center text-slate-400">
                    <p>Experiment not found.</p>
                    <button onClick={() => setSelectedId(null)} className="mt-4 text-indigo-600 font-bold hover:underline">Back to list</button>
                </div>
            );
        }

        const leading = leadingVariant(exp);
        const bestComparison = report?.comparisons?.length ? report.comparisons.sort((a, b) => b.prob_win - a.prob_win)[0] : null;

        return (
            <div className="space-y-6">
                {/* Back button + header */}
                <div className="flex items-start justify-between">
                    <div>
                        <button onClick={() => { setSelectedId(null); setReport(null); setTimeline([]); setTimeseries([]); }}
                            className="text-sm text-slate-500 hover:text-slate-700 font-medium mb-2 flex items-center gap-1">
                            <i className="fa-solid fa-arrow-left"></i> Back to Experiments
                        </button>
                        <h3 className="text-2xl font-black text-slate-900">{exp.name}</h3>
                        <p className="text-slate-500 text-sm mt-1">
                            <span className="font-mono text-xs bg-slate-100 px-2 py-0.5 rounded">{exp.key_slug}</span>
                            <span className="mx-2">·</span>
                            {exp.surface}
                            <span className="mx-2">·</span>
                            {exp.primary_goal_event}
                        </p>
                    </div>
                    <StatusBadge status={exp.status} />
                </div>

                {/* 1. Controls */}
                <div className="bg-white p-5 rounded-3xl border border-slate-100 shadow-sm">
                    <div className="flex flex-wrap gap-3">
                        {exp.status === 'draft' && (
                            <button onClick={() => performAction('start')} disabled={actionLoading === 'start'}
                                className="px-5 py-2.5 rounded-xl bg-emerald-600 text-white font-bold text-sm hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-200 disabled:opacity-50 flex items-center gap-2">
                                {actionLoading === 'start' ? <i className="fa-solid fa-circle-notch fa-spin"></i> : <i className="fa-solid fa-play"></i>} Start
                            </button>
                        )}
                        {exp.status === 'running' && (
                            <button onClick={() => performAction('pause')} disabled={actionLoading === 'pause'}
                                className="px-5 py-2.5 rounded-xl bg-amber-600 text-white font-bold text-sm hover:bg-amber-700 transition-all shadow-lg shadow-amber-200 disabled:opacity-50 flex items-center gap-2">
                                {actionLoading === 'pause' ? <i className="fa-solid fa-circle-notch fa-spin"></i> : <i className="fa-solid fa-pause"></i>} Pause
                            </button>
                        )}
                        {exp.status === 'paused' && (
                            <button onClick={() => performAction('start')} disabled={actionLoading === 'start'}
                                className="px-5 py-2.5 rounded-xl bg-emerald-600 text-white font-bold text-sm hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-200 disabled:opacity-50 flex items-center gap-2">
                                {actionLoading === 'start' ? <i className="fa-solid fa-circle-notch fa-spin"></i> : <i className="fa-solid fa-play"></i>} Resume
                            </button>
                        )}
                        {(exp.status === 'running' || exp.status === 'paused') && (
                            <button onClick={() => performAction('ship', { winning_variant_id: bestComparison?.variant_id })} disabled={!canShip || actionLoading === 'ship'}
                                className="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold text-sm hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                title={!canShip ? 'Insufficient data to ship a winner' : 'Ship the winning variant'}>
                                {actionLoading === 'ship' ? <i className="fa-solid fa-circle-notch fa-spin"></i> : <i className="fa-solid fa-rocket"></i>} Ship Winner
                            </button>
                        )}
                        {isShipped && (
                            <button onClick={() => performAction('rollback')} disabled={actionLoading === 'rollback'}
                                className="px-5 py-2.5 rounded-xl bg-red-600 text-white font-bold text-sm hover:bg-red-700 transition-all shadow-lg shadow-red-200 disabled:opacity-50 flex items-center gap-2">
                                {actionLoading === 'rollback' ? <i className="fa-solid fa-circle-notch fa-spin"></i> : <i className="fa-solid fa-rotate-left"></i>} Rollback
                            </button>
                        )}
                    </div>
                    <p className="text-xs text-slate-400 mt-3">
                        Min samples required: {exp.min_samples_per_variant} per variant ·
                        Confidence threshold: {(parseFloat(exp.confidence_threshold as string) * 100).toFixed(0)}%
                        {exp.allocation_mode === 'fixed' && ' · Fixed allocation'}
                    </p>
                </div>

                {detailLoading && (
                    <div className="p-12 text-center text-slate-400">
                        <i className="fa-solid fa-circle-notch fa-spin text-3xl mb-4"></i>
                        <p>Loading report...</p>
                    </div>
                )}

                {!detailLoading && report && (
                    <>
                        {/* 2. Decision Panel */}
                        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                            <h4 className="text-lg font-black text-slate-800 mb-4 flex items-center gap-2">
                                <i className="fa-solid fa-gavel text-indigo-500"></i> Decision Panel
                            </h4>
                            {bestComparison ? (
                                <div className="space-y-3">
                                    <div className="flex items-start gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-100">
                                        <div className="w-12 h-12 rounded-2xl bg-indigo-100 flex items-center justify-center flex-shrink-0">
                                            <i className="fa-solid fa-trophy text-indigo-600 text-xl"></i>
                                        </div>
                                        <div>
                                            <p className="font-bold text-slate-900 text-lg">{bestComparison.name}</p>
                                            <p className="text-slate-600 text-sm mt-1">
                                                {bestComparison.prob_win >= 0.95
                                                    ? 'Strong confidence — this variant is very likely the best performer.'
                                                    : bestComparison.prob_win >= 0.8
                                                        ? 'Moderate confidence — trending positive but more data may help.'
                                                        : 'Low confidence — keep running to gather more data.'}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                                        <div className="bg-indigo-50 rounded-2xl p-4 text-center">
                                            <div className="text-xs font-bold text-indigo-600 uppercase tracking-wider mb-1">Prob Best</div>
                                            <div className="text-2xl font-black text-indigo-900">{(bestComparison.prob_win * 100).toFixed(1)}%</div>
                                        </div>
                                        <div className="bg-emerald-50 rounded-2xl p-4 text-center">
                                            <div className="text-xs font-bold text-emerald-600 uppercase tracking-wider mb-1">Uplift</div>
                                            <div className={`text-2xl font-black ${(bestComparison.relative_uplift ?? 0) >= 0 ? 'text-emerald-700' : 'text-red-600'}`}>
                                                {formatUplift(bestComparison.relative_uplift)}
                                            </div>
                                            <div className="text-[10px] text-slate-500 mt-1">
                                                CI: {formatUplift(bestComparison.uplift_ci_lower)} – {formatUplift(bestComparison.uplift_ci_upper)}
                                            </div>
                                        </div>
                                        <div className="bg-slate-50 rounded-2xl p-4 text-center">
                                            <div className="text-xs font-bold text-slate-600 uppercase tracking-wider mb-1">Significance</div>
                                            <div className="text-lg font-black text-slate-900">{pValueLabel(bestComparison.z_test_p_value)}</div>
                                        </div>
                                        <div className="bg-amber-50 rounded-2xl p-4 text-center">
                                            <div className="text-xs font-bold text-amber-600 uppercase tracking-wider mb-1">Expected Loss</div>
                                            <div className="text-lg font-black text-amber-800">{bestComparison.expected_loss.toFixed(2)}%</div>
                                        </div>
                                        <div className="bg-slate-50 rounded-2xl p-4 text-center">
                                            <div className="text-xs font-bold text-slate-600 uppercase tracking-wider mb-1">Conv. Rates</div>
                                            <div className="text-sm font-black text-slate-900">
                                                {(bestComparison.control_rate * 100).toFixed(2)}% → {(bestComparison.variant_rate * 100).toFixed(2)}%
                                            </div>
                                            <div className="text-[10px] text-slate-500 mt-1">control → variant</div>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="text-center py-8 text-slate-400">
                                    <i className="fa-solid fa-hourglass-half text-3xl mb-3"></i>
                                    <p>Not enough data yet. Keep the experiment running.</p>
                                </div>
                            )}
                        </div>

                        {/* 3. Variant Cards */}
                        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                            <h4 className="text-lg font-black text-slate-800 mb-4 flex items-center gap-2">
                                <i className="fa-solid fa-layer-group text-indigo-500"></i> Variants
                            </h4>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {report.variants.map((v, i) => {
                                    const color = VariantColor(i);
                                    const isBest = bestComparison?.variant_id === v.variant_id && v.prob_best === Math.max(...report.variants.map((x) => x.prob_best));
                                    const totalExposures = report.variants.reduce((s, x) => s + x.exposures, 0);
                                    const trafficShare = totalExposures > 0 ? (v.exposures / totalExposures) * 100 : 0;
                                    return (
                                        <div key={v.variant_id} className={`relative rounded-2xl border-2 p-5 transition-all ${v.is_control ? 'border-slate-200 bg-slate-50/50' : isBest ? 'border-emerald-300 bg-emerald-50/30' : 'border-slate-100 bg-white'}`}>
                                            {v.is_control === 1 && (
                                                <span className="absolute top-3 right-3 px-2 py-0.5 rounded-lg bg-slate-200 text-slate-600 text-[10px] font-bold uppercase tracking-wider">Control</span>
                                            )}
                                            {isBest && !v.is_control && (
                                                <span className="absolute top-3 right-3 px-2 py-0.5 rounded-lg bg-emerald-200 text-emerald-800 text-[10px] font-bold uppercase tracking-wider">Leading</span>
                                            )}
                                            <div className="flex items-center gap-3 mb-3">
                                                <div className="w-3 h-3 rounded-full flex-shrink-0" style={{ backgroundColor: color }} />
                                                <div>
                                                    <span className="font-black text-slate-900">{v.name}</span>
                                                    <span className="text-xs text-slate-400 ml-2 font-mono">{v.key_slug}</span>
                                                </div>
                                            </div>
                                            <div className="grid grid-cols-2 gap-3 text-sm">
                                                <div>
                                                    <span className="text-xs text-slate-500">Exposures</span>
                                                    <div className="font-bold text-slate-800">{v.exposures.toLocaleString()}</div>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-slate-500">Conv. Rate</span>
                                                    <div className="font-bold text-slate-800">{(v.conversion_rate * 100).toFixed(2)}%</div>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-slate-500">Rev/Visitor</span>
                                                    <div className="font-bold text-slate-800">{formatCurrency(v.revenue_per_visitor)}</div>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-slate-500">Traffic Share</span>
                                                    <div className="font-bold text-slate-800">{trafficShare.toFixed(1)}%</div>
                                                </div>
                                            </div>
                                            <div className="mt-3">
                                                <span className="text-xs text-slate-500">Prob Best</span>
                                                <ConfidenceBar prob={v.prob_best} className="mt-1" />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        {/* 4. Full-Funnel Breakdown */}
                        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                            <h4 className="text-lg font-black text-slate-800 mb-4 flex items-center gap-2">
                                <i className="fa-solid fa-funnel-dollar text-indigo-500"></i> Funnel Breakdown
                            </h4>
                            {report.variants.some((v) => Object.keys(v.funnel_breakdown).length > 0) ? (
                                <div className="h-72">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={FUNNEL_STEPS.map((step) => ({
                                            step: FUNNEL_LABELS[step] || step,
                                            ...Object.fromEntries(
                                                report.variants.map((v) => [v.key_slug, v.funnel_breakdown[step] || 0])
                                            ),
                                        }))}>
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
                                            <XAxis dataKey="step" tick={{ fontSize: 11, fill: '#94a3b8' }} />
                                            <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} />
                                            <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }} />
                                            <Legend />
                                            {report.variants.map((v, i) => (
                                                <Bar key={v.variant_id} dataKey={v.key_slug} fill={VariantColor(i)} radius={[4, 4, 0, 0]} name={v.name} />
                                            ))}
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : (
                                <div className="text-center py-8 text-slate-400">
                                    <p>No funnel data available yet.</p>
                                </div>
                            )}
                        </div>

                        {/* 5. Allocation & Performance Timeline */}
                        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                            <h4 className="text-lg font-black text-slate-800 mb-4 flex items-center gap-2">
                                <i className="fa-solid fa-chart-line text-indigo-500"></i> Allocation & Performance Timeline
                            </h4>
                            {timeseries.length > 0 ? (
                                <div>
                                    <div className="h-72">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <LineChart data={(() => {
                                                const dates = [...new Set(timeseries.map((t: any) => t.date))].sort();
                                                return dates.map((date) => {
                                                    const point: Record<string, any> = { date };
                                                    const dayPoints = timeseries.filter((t: any) => t.date === date);
                                                    for (const dp of dayPoints) {
                                                        point[`${dp.key_slug}_traffic`] = dp.traffic_share;
                                                        point[`${dp.key_slug}_conv`] = parseFloat((dp.conversion_rate * 100).toFixed(2));
                                                    }
                                                    return point;
                                                });
                                            })()}>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
                                                <XAxis dataKey="date" tick={{ fontSize: 10, fill: '#94a3b8' }} tickFormatter={(val) => new Date(val).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} />
                                                <YAxis yAxisId="left" tick={{ fontSize: 10, fill: '#94a3b8' }} />
                                                <YAxis yAxisId="right" orientation="right" tick={{ fontSize: 10, fill: '#94a3b8' }} tickFormatter={(v) => `${v}%`} />
                                                <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }} />
                                                <Legend />
                                                {report.variants.map((v, i) => (
                                                    <React.Fragment key={v.variant_id}>
                                                        <Line yAxisId="left" type="monotone" dataKey={`${v.key_slug}_conv`}
                                                            stroke={VariantColor(i)} strokeWidth={2} dot={false}
                                                            name={`${v.name} Conv. Rate`} />
                                                        <Line yAxisId="right" type="monotone" dataKey={`${v.key_slug}_traffic`}
                                                            stroke={VariantColor(i)} strokeWidth={1.5} strokeDasharray="4 4" dot={false}
                                                            name={`${v.name} Traffic %`} />
                                                    </React.Fragment>
                                                ))}
                                            </LineChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="flex items-center gap-4 mt-3 text-xs text-slate-500">
                                        <span className="flex items-center gap-1"><span className="w-3 h-0.5 bg-slate-400 inline-block"></span> Conversion rate</span>
                                        <span className="flex items-center gap-1"><span className="w-3 h-0 border-t border-dashed border-slate-400 inline-block"></span> Traffic share</span>
                                    </div>
                                </div>
                            ) : (
                                <div className="text-center py-8 text-slate-400">
                                    <i className="fa-solid fa-chart-line text-3xl mb-3"></i>
                                    <p>No time-series data yet. Data appears once the experiment starts accumulating events.</p>
                                </div>
                            )}
                        </div>

                        {/* 6. Segment Breakdown */}
                        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                            <h4 className="text-lg font-black text-slate-800 mb-4 flex items-center gap-2">
                                <i className="fa-solid fa-chart-pie text-indigo-500"></i> Segment Breakdown
                            </h4>
                            {segments.length > 0 ? (
                                <div className="space-y-4">
                                    {segments.map((seg) => (
                                        <div key={seg.segment_key}>
                                            <h5 className="text-sm font-bold text-slate-600 uppercase tracking-wider mb-3">{seg.segment_key}</h5>
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-sm">
                                                    <thead>
                                                        <tr className="border-b border-slate-100">
                                                            <th className="text-left py-2 px-3 text-slate-500 font-bold text-xs uppercase">Variant</th>
                                                            <th className="text-right py-2 px-3 text-slate-500 font-bold text-xs uppercase">Exposures</th>
                                                            <th className="text-right py-2 px-3 text-slate-500 font-bold text-xs uppercase">Conversion</th>
                                                            <th className="text-right py-2 px-3 text-slate-500 font-bold text-xs uppercase">Conv. Rate</th>
                                                            <th className="text-right py-2 px-3 text-slate-500 font-bold text-xs uppercase">Prob Best</th>
                                                            <th className="text-right py-2 px-3 text-slate-500 font-bold text-xs uppercase">Rev/Vis</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {seg.variants.map((v) => (
                                                            <tr key={v.key_slug} className="border-b border-slate-50 hover:bg-slate-50/50">
                                                                <td className="py-2 px-3 font-medium text-slate-800">
                                                                    {v.name}
                                                                    {v.is_control === 1 && <span className="ml-2 text-[10px] text-slate-400">(control)</span>}
                                                                </td>
                                                                <td className="py-2 px-3 text-right text-slate-600">{v.exposures.toLocaleString()}</td>
                                                                <td className="py-2 px-3 text-right text-slate-600">{v.conversions.toLocaleString()}</td>
                                                                <td className="py-2 px-3 text-right font-medium text-slate-800">{(v.conversion_rate * 100).toFixed(2)}%</td>
                                                                <td className="py-2 px-3 text-right">
                                                                    <span className={`font-bold ${v.prob_best >= 0.95 ? 'text-emerald-600' : v.prob_best >= 0.8 ? 'text-amber-600' : 'text-slate-500'}`}>
                                                                        {(v.prob_best * 100).toFixed(1)}%
                                                                    </span>
                                                                </td>
                                                                <td className="py-2 px-3 text-right text-slate-600">{formatCurrency(v.revenue_per_visitor)}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8 text-slate-400">
                                    <p>No segment data available.</p>
                                </div>
                            )}
                        </div>

                        {/* 7. Decision Log */}
                        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                            <h4 className="text-lg font-black text-slate-800 mb-4 flex items-center gap-2">
                                <i className="fa-solid fa-clock-rotate-left text-indigo-500"></i> Decision Log
                            </h4>
                            {timeline.length > 0 ? (
                                <div className="space-y-3 max-h-80 overflow-y-auto">
                                    {timeline.filter((e) => e.type === 'decision').map((entry, i) => {
                                        const d = entry as DecisionEntry;
                                        const icon = d.decision_type === 'shipped' ? 'fa-rocket' :
                                            d.decision_type === 'started' ? 'fa-play' :
                                                d.decision_type === 'paused' ? 'fa-pause' :
                                                    d.decision_type === 'rolled_back' ? 'fa-rotate-left' :
                                                        d.decision_type === 'auto_promote_suggested' ? 'fa-robot' :
                                                            'fa-circle';
                                        const color = d.decision_type === 'shipped' ? 'bg-indigo-100 text-indigo-600' :
                                            d.decision_type === 'rolled_back' ? 'bg-red-100 text-red-600' :
                                                d.decision_type === 'auto_promote_suggested' ? 'bg-purple-100 text-purple-600' :
                                                    'bg-slate-100 text-slate-600';
                                        return (
                                            <div key={i} className="flex items-start gap-3 p-3 rounded-2xl bg-slate-50 border border-slate-100">
                                                <div className={`w-8 h-8 rounded-xl ${color} flex items-center justify-center flex-shrink-0`}>
                                                    <i className={`fa-solid ${icon}`}></i>
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-bold text-slate-800 text-sm capitalize">{d.decision_type.replace(/_/g, ' ')}</span>
                                                        <span className="text-xs text-slate-400">by {d.actor}</span>
                                                    </div>
                                                    {d.winning_variant && (
                                                        <p className="text-xs text-slate-600 mt-0.5">Winner: {d.winning_variant.name} ({d.winning_variant.key_slug})</p>
                                                    )}
                                                    <p className="text-[11px] text-slate-400 mt-1">{formatDateTime(d.timestamp)}</p>
                                                </div>
                                            </div>
                                        );
                                    })}
                                    {timeline.filter((e) => e.type === 'decision').length === 0 && (
                                        <p className="text-center text-slate-400 py-4">No decisions recorded yet.</p>
                                    )}
                                </div>
                            ) : (
                                <div className="text-center py-8 text-slate-400">
                                    <i className="fa-solid fa-clock text-3xl mb-3"></i>
                                    <p>No timeline data yet.</p>
                                </div>
                            )}
                        </div>
                    </>
                )}
            </div>
        );
    }

    // ─── List View ───────────────────────────────────────────────

    return (
        <div className="space-y-6">
            {error && (
                <div className="p-4 rounded-2xl bg-red-50 border border-red-200 text-red-700 text-sm font-medium flex items-center gap-2">
                    <i className="fa-solid fa-circle-exclamation"></i> {error}
                </div>
            )}

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h3 className="text-xl font-black text-slate-900 flex items-center gap-2">
                        <i className="fa-solid fa-flask text-indigo-500"></i> Experiments
                    </h3>
                    <p className="text-sm text-slate-500 mt-1">
                        {experiments.length} experiment{experiments.length !== 1 ? 's' : ''} — {experiments.filter((e) => e.status === 'running').length} running
                    </p>
                </div>
                <button onClick={() => setShowNewModal(true)}
                    className="px-5 py-2.5 rounded-xl bg-slate-900 text-white font-bold text-sm hover:bg-slate-800 transition-all shadow-lg shadow-slate-200 flex items-center gap-2">
                    <i className="fa-solid fa-plus"></i> New Experiment
                </button>
            </div>

            {/* Table */}
            {experiments.length === 0 ? (
                <div className="bg-white rounded-3xl border border-slate-100 shadow-sm p-12 text-center">
                    <i className="fa-solid fa-flask text-4xl text-slate-300 mb-4"></i>
                    <p className="text-slate-500 font-medium">No experiments yet</p>
                    <p className="text-slate-400 text-sm mt-1">Create your first A/B test to start optimizing.</p>
                    <button onClick={() => setShowNewModal(true)}
                        className="mt-4 px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold text-sm hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200">
                        Create Experiment
                    </button>
                </div>
            ) : (
                <div className="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50/50">
                                    <th className="text-left py-3 px-4 text-slate-500 font-bold text-xs uppercase tracking-wider">Name</th>
                                    <th className="text-left py-3 px-4 text-slate-500 font-bold text-xs uppercase tracking-wider">Surface</th>
                                    <th className="text-left py-3 px-4 text-slate-500 font-bold text-xs uppercase tracking-wider">Status</th>
                                    <th className="text-right py-3 px-4 text-slate-500 font-bold text-xs uppercase tracking-wider">Days</th>
                                    <th className="text-left py-3 px-4 text-slate-500 font-bold text-xs uppercase tracking-wider">Leading</th>
                                    <th className="text-right py-3 px-4 text-slate-500 font-bold text-xs uppercase tracking-wider">Prob Best</th>
                                    <th className="text-right py-3 px-4 text-slate-500 font-bold text-xs uppercase tracking-wider">Uplift</th>
                                    <th className="text-center py-3 px-4 text-slate-500 font-bold text-xs uppercase tracking-wider">SRM</th>
                                    <th className="text-right py-3 px-4 text-slate-500 font-bold text-xs uppercase tracking-wider">Assignments</th>
                                </tr>
                            </thead>
                            <tbody>
                                {experiments.map((exp) => {
                                    const days = daysRunning(exp);
                                    const hasSRM = hasSRMFlag(exp);
                                    return (
                                        <tr key={exp.id}
                                            onClick={() => setSelectedId(exp.id)}
                                            className="border-b border-slate-50 hover:bg-indigo-50/30 cursor-pointer transition-colors">
                                            <td className="py-3 px-4">
                                                <div className="font-bold text-slate-800">{exp.name}</div>
                                                <div className="text-xs text-slate-400 font-mono mt-0.5">{exp.key_slug}</div>
                                            </td>
                                            <td className="py-3 px-4 text-slate-600">
                                                <span className="px-2 py-0.5 rounded-lg bg-slate-100 text-slate-600 text-[11px] font-medium">{exp.surface}</span>
                                            </td>
                                            <td className="py-3 px-4">
                                                <StatusBadge status={exp.status} />
                                            </td>
                                            <td className="py-3 px-4 text-right text-slate-600 font-medium">{days !== null ? `${days}d` : '—'}</td>
                                            <td className="py-3 px-4 text-slate-700 font-medium">
                                                {exp.status === 'shipped'
                                                    ? <span className="text-indigo-600"><i className="fa-solid fa-rocket mr-1"></i>Shipped</span>
                                                    : leadingVariant(exp)?.name || <span className="text-slate-400">—</span>
                                                }
                                            </td>
                                            <td className="py-3 px-4">
                                                <span className="text-slate-400 text-xs">—</span>
                                            </td>
                                            <td className="py-3 px-4 text-right font-medium">
                                                {exp.status === 'shipped'
                                                    ? <span className="text-indigo-600">Shipped</span>
                                                    : <span className="text-slate-400">—</span>
                                                }
                                            </td>
                                            <td className="py-3 px-4 text-center"><SRMBadge hasSRM={hasSRM} /></td>
                                            <td className="py-3 px-4 text-right text-slate-600">{exp.assignment_count.toLocaleString()}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {showNewModal && <NewExperimentModal onClose={() => setShowNewModal(false)} onCreated={fetchList} />}
        </div>
    );
};

export default ExperimentsDashboard;
