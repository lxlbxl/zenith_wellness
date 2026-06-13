import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../../services/api';

// ---- Types ----

interface Brief {
  id: string;
  surface: string;
  updated_at: string;
  value_prop_count: number;
  forbidden_count: number;
}

interface Candidate {
  id: string;
  generation_id: string;
  experiment_id: string | null;
  config: Record<string, any>;
  rationale: string | null;
  pattern_tags: string[] | null;
  safety_flag: 'clean' | 'needs_review' | 'rejected';
  safety_notes: string | null;
  review_status: 'pending' | 'approved' | 'rejected' | 'edited';
  edited_config: Record<string, any> | null;
  promoted_variant_id: string | null;
  reviewed_by: string | null;
  reviewed_at: string | null;
  created_at: string;
  surface: string;
  segment_key: string | null;
  model: string;
  tokens_used: number;
}

interface Insight {
  id: string;
  surface: string;
  segment_key: string | null;
  pattern_tag: string;
  direction: 'lifts' | 'hurts' | 'neutral';
  effect_size: number | null;
  confidence: number | null;
  sample_size: number | null;
  summary: string;
  weight: number;
  is_active: number;
  created_at: string;
  experiment_count: number;
}

// ---- Helpers ----

const SURFACES = [
  { value: 'sales_trust', label: 'Sales / Trust' },
  { value: 'quiz_landing', label: 'Quiz Landing' },
  { value: 'checkout', label: 'Checkout' },
  { value: 'pricing', label: 'Pricing' },
  { value: 'signup', label: 'Signup' },
];

const SAFETY_BADGE: Record<string, { color: string; icon: string; label: string }> = {
  clean: { color: 'bg-emerald-100 text-emerald-700', icon: 'fa-shield-check', label: 'Clean' },
  needs_review: { color: 'bg-amber-100 text-amber-700', icon: 'fa-triangle-exclamation', label: 'Needs Review' },
  rejected: { color: 'bg-rose-100 text-rose-700', icon: 'fa-ban', label: 'Flagged' },
};

const DIRECTION_ICON: Record<string, string> = {
  lifts: 'fa-arrow-up text-emerald-500',
  hurts: 'fa-arrow-down text-rose-500',
  neutral: 'fa-minus text-slate-400',
};

// ---- Component ----

const VariantStudio: React.FC = () => {
  const [activeTab, setActiveTab] = useState<'generate' | 'review' | 'insights'>('generate');

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-xl font-black text-slate-900">AI Variant Studio</h3>
        <p className="text-sm text-slate-500">Generate, review, and manage A/B test variants with AI</p>
      </div>

      <div className="flex gap-4 border-b border-slate-100 pb-2">
        {([
          { id: 'generate', label: 'Generate', icon: 'fa-wand-magic-sparkles' },
          { id: 'review', label: 'Review Queue', icon: 'fa-list-check' },
          { id: 'insights', label: 'Insights', icon: 'fa-brain' },
        ] as const).map(tab => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`text-sm font-bold pb-2 px-3 flex items-center gap-2 ${
              activeTab === tab.id
                ? 'text-indigo-600 border-b-2 border-indigo-600'
                : 'text-slate-400 hover:text-slate-600'
            }`}
          >
            <i className={`fa-solid ${tab.icon}`}></i>
            {tab.label}
          </button>
        ))}
      </div>

      {activeTab === 'generate' && <GeneratePanel />}
      {activeTab === 'review' && <ReviewQueue />}
      {activeTab === 'insights' && <InsightsPanel />}
    </div>
  );
};

// =============================================
// GENERATE PANEL
// =============================================

const GeneratePanel: React.FC = () => {
  const [surface, setSurface] = useState('sales_trust');
  const [segmentKey, setSegmentKey] = useState('');
  const [count, setCount] = useState(5);
  const [generating, setGenerating] = useState(false);
  const [result, setResult] = useState<any>(null);
  const [error, setError] = useState('');
  const [briefs, setBriefs] = useState<Brief[]>([]);

  useEffect(() => {
    api.get<Brief[]>('/admin/variants/briefs').then(setBriefs).catch(() => {});
  }, []);

  const handleGenerate = async () => {
    setGenerating(true);
    setError('');
    setResult(null);
    try {
      const res = await api.post<any>('/admin/variants/generate', {
        surface,
        segment_key: segmentKey || null,
        count,
      });
      if (res.success) {
        setResult(res);
      } else {
        setError(res.error || 'Generation failed');
      }
    } catch (err: any) {
      setError(err.message || 'Failed to generate variants');
    } finally {
      setGenerating(false);
    }
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      {/* Generator Controls */}
      <div className="lg:col-span-2 space-y-4">
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
          <h4 className="font-bold text-lg mb-4">Generate Variants</h4>

          {/* Surface */}
          <div className="mb-4">
            <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">Surface</label>
            <select
              value={surface}
              onChange={e => setSurface(e.target.value)}
              className="w-full bg-slate-50 p-3 rounded-xl border-slate-200 font-bold"
            >
              {SURFACES.map(s => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </select>
          </div>

          {/* Segment */}
          <div className="mb-4">
            <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">
              Segment Key <span className="text-slate-300 font-normal">(optional)</span>
            </label>
            <input
              type="text"
              value={segmentKey}
              onChange={e => setSegmentKey(e.target.value)}
              placeholder="e.g. src:meta|dev:mobile"
              className="w-full bg-slate-50 p-3 rounded-xl border-slate-200 text-sm"
            />
            <p className="text-[10px] text-slate-400 mt-1">Leave empty for all users</p>
          </div>

          {/* Count Slider */}
          <div className="mb-6">
            <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">
              Number of Variants: <span className="text-indigo-600">{count}</span>
            </label>
            <input
              type="range"
              min={3}
              max={10}
              value={count}
              onChange={e => setCount(parseInt(e.target.value))}
              className="w-full"
            />
            <div className="flex justify-between text-[10px] text-slate-400">
              <span>3 (minimum)</span>
              <span>10 (maximum)</span>
            </div>
          </div>

          <button
            onClick={handleGenerate}
            disabled={generating}
            className="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold shadow-lg shadow-indigo-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
          >
            {generating ? (
              <><i className="fa-solid fa-spinner fa-spin"></i> Generating...</>
            ) : (
              <><i className="fa-solid fa-wand-magic-sparkles"></i> Generate Variants with AI</>
            )}
          </button>

          {error && (
            <div className="mt-4 p-4 bg-rose-50 border border-rose-200 rounded-xl text-sm text-rose-700">
              <i className="fa-solid fa-circle-exclamation mr-2"></i>{error}
            </div>
          )}
        </div>

        {/* Results */}
        {result && (
          <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
            <div className="flex items-center justify-between mb-4">
              <h4 className="font-bold text-lg text-emerald-700">
                <i className="fa-solid fa-check-circle mr-2"></i>
                Generated {result.candidates?.length || 0} Candidates
              </h4>
              <span className="text-xs text-slate-400">
                {result.insights_used} insights · {result.prior_attempts_consulted} prior attempts
              </span>
            </div>
            <div className="space-y-3">
              {(result.candidates || []).map((c: any, i: number) => (
                <div key={i} className="bg-slate-50 p-4 rounded-2xl border border-slate-100">
                  <div className="flex items-start justify-between mb-2">
                    <span className="text-xs font-bold text-indigo-500 bg-indigo-50 px-2 py-1 rounded">
                      Variant #{i + 1}
                    </span>
                    <span className={`text-[10px] font-bold px-2 py-1 rounded-full ${SAFETY_BADGE[c.safety_flag || 'clean']?.color || 'bg-slate-100 text-slate-600'}`}>
                      <i className={`fa-solid ${SAFETY_BADGE[c.safety_flag || 'clean']?.icon || 'fa-shield'} mr-1`}></i>
                      {SAFETY_BADGE[c.safety_flag || 'clean']?.label || 'Unknown'}
                    </span>
                  </div>
                  <div className="text-xs font-mono bg-white p-3 rounded-xl mb-2 max-h-32 overflow-y-auto">
                    <pre className="text-slate-700 whitespace-pre-wrap">{JSON.stringify(c.config, null, 2)}</pre>
                  </div>
                  {c.rationale && (
                    <p className="text-xs text-slate-500 italic">"{c.rationale}"</p>
                  )}
                  {c.pattern_tags && c.pattern_tags.length > 0 && (
                    <div className="flex flex-wrap gap-1 mt-2">
                      {c.pattern_tags.map((tag: string) => (
                        <span key={tag} className="text-[10px] bg-slate-200 text-slate-600 px-2 py-0.5 rounded-full">{tag}</span>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
            <p className="text-xs text-slate-400 mt-4">
              <i className="fa-solid fa-arrow-right-to-bracket mr-1"></i>
              Go to <button onClick={() => {}} className="text-indigo-600 underline">Review Queue</button> to approve or reject these candidates.
            </p>
          </div>
        )}
      </div>

      {/* Sidebar: Brief Info */}
      <div className="space-y-4">
        <div className="bg-indigo-900 text-white p-6 rounded-3xl shadow-lg">
          <h4 className="font-bold text-lg mb-2">Quick Info</h4>
          <p className="text-xs text-indigo-200 mb-4">
            The AI generates distinct variants using the surface's brief, active insights, and knowledge of what's already been tested.
          </p>
          <ul className="text-xs space-y-2 text-indigo-200">
            <li><i className="fa-solid fa-check text-emerald-400 mr-2"></i>Each variant is schema-validated</li>
            <li><i className="fa-solid fa-check text-emerald-400 mr-2"></i>Forbidden claims are automatically checked</li>
            <li><i className="fa-solid fa-check text-emerald-400 mr-2"></i>Prior attempts are avoided</li>
            <li><i className="fa-solid fa-check text-emerald-400 mr-2"></i>Safety-flags are auto-assigned</li>
          </ul>
        </div>

        <div className="bg-white p-4 rounded-3xl border border-slate-100 shadow-sm">
          <h5 className="text-xs font-bold text-slate-400 uppercase mb-2">Available Briefs</h5>
          {briefs.length === 0 ? (
            <p className="text-xs text-slate-400">Loading...</p>
          ) : (
            <div className="space-y-2">
              {briefs.map(b => (
                <div key={b.surface} className="flex justify-between items-center text-xs">
                  <span className="font-bold text-slate-700 capitalize">{b.surface.replace(/_/g, ' ')}</span>
                  <span className="text-slate-400">{b.value_prop_count} props</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

// =============================================
// REVIEW QUEUE
// =============================================

const ReviewQueue: React.FC = () => {
  const [statusFilter, setStatusFilter] = useState<'pending' | 'approved' | 'rejected' | 'all'>('pending');
  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editConfig, setEditConfig] = useState<string>('');
  const [rejectNote, setRejectNote] = useState('');

  const fetchCandidates = useCallback(() => {
    setLoading(true);
    api.get<Candidate[]>(`/admin/variants/candidates?status=${statusFilter}`)
      .then(setCandidates)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [statusFilter]);

  useEffect(() => { fetchCandidates(); }, [fetchCandidates]);

  const handleApprove = async (id: string) => {
    try {
      await api.post(`/admin/variants/approve/${id}`, {});
      fetchCandidates();
    } catch (err: any) {
      alert('Failed to approve: ' + err.message);
    }
  };

  const handleEditAndApprove = async (id: string) => {
    try {
      const parsed = JSON.parse(editConfig);
      await api.post(`/admin/variants/approve/${id}`, { edited_config: parsed });
      setEditingId(null);
      setEditConfig('');
      fetchCandidates();
    } catch (err: any) {
      alert('Invalid JSON config: ' + err.message);
    }
  };

  const handleReject = async (id: string) => {
    const note = rejectNote.trim() || null;
    try {
      await api.post(`/admin/variants/reject/${id}`, { note });
      setRejectNote('');
      fetchCandidates();
    } catch (err: any) {
      alert('Failed to reject: ' + err.message);
    }
  };

  const toggleSelect = (id: string) => {
    setSelected(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const handleBulkApprove = async () => {
    for (const id of selected) {
      try {
        await api.post(`/admin/variants/approve/${id}`, {});
      } catch { /* skip failed */ }
    }
    setSelected(new Set());
    fetchCandidates();
  };

  const pendingCount = candidates.filter(c => c.review_status === 'pending').length;

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex gap-2">
          {(['pending', 'approved', 'rejected', 'all'] as const).map(s => (
            <button
              key={s}
              onClick={() => setStatusFilter(s)}
              className={`text-xs font-bold px-3 py-1.5 rounded-xl transition-all ${
                statusFilter === s
                  ? 'bg-slate-900 text-white'
                  : 'bg-slate-100 text-slate-500 hover:bg-slate-200'
              }`}
            >
              {s.charAt(0).toUpperCase() + s.slice(1)}
              {s === 'pending' && pendingCount > 0 && (
                <span className="ml-1.5 bg-rose-500 text-white text-[9px] px-1.5 py-0.5 rounded-full">{pendingCount}</span>
              )}
            </button>
          ))}
        </div>

        {selected.size > 0 && statusFilter === 'pending' && (
          <button
            onClick={handleBulkApprove}
            className="text-xs font-bold px-4 py-1.5 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700"
          >
            <i className="fa-solid fa-check-double mr-1"></i>
            Approve {selected.size} Selected
          </button>
        )}
      </div>

      {/* Candidate Cards */}
      {loading ? (
        <div className="text-center py-10 text-slate-400">
          <i className="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
          <p className="text-sm">Loading candidates...</p>
        </div>
      ) : candidates.length === 0 ? (
        <div className="text-center py-16 text-slate-400">
          <i className="fa-solid fa-inbox text-4xl mb-3"></i>
          <p className="font-bold">No {statusFilter === 'all' ? '' : statusFilter} candidates</p>
          <p className="text-xs mt-1">Generate variants to populate the review queue</p>
        </div>
      ) : (
        <div className="space-y-3">
          {candidates.map(c => (
            <div
              key={c.id}
              className={`bg-white rounded-3xl border p-5 shadow-sm transition-all ${
                c.review_status === 'pending' ? 'border-slate-100' :
                c.review_status === 'approved' || c.review_status === 'edited' ? 'border-emerald-200 bg-emerald-50/30' :
                'border-rose-200 bg-rose-50/30'
              }`}
            >
              {/* Header */}
              <div className="flex items-start justify-between mb-3">
                <div className="flex items-center gap-3">
                  {statusFilter === 'pending' && (
                    <input
                      type="checkbox"
                      checked={selected.has(c.id)}
                      onChange={() => toggleSelect(c.id)}
                      className="rounded border-slate-300 mt-1"
                    />
                  )}
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-bold text-indigo-500 bg-indigo-50 px-2 py-0.5 rounded">{c.surface.replace(/_/g, ' ')}</span>
                      <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${SAFETY_BADGE[c.safety_flag]?.color || ''}`}>
                        <i className={`fa-solid ${SAFETY_BADGE[c.safety_flag]?.icon} mr-1`}></i>
                        {SAFETY_BADGE[c.safety_flag]?.label}
                      </span>
                      <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${
                        c.review_status === 'pending' ? 'bg-amber-100 text-amber-700' :
                        c.review_status === 'approved' ? 'bg-emerald-100 text-emerald-700' :
                        c.review_status === 'edited' ? 'bg-blue-100 text-blue-700' :
                        'bg-slate-100 text-slate-600'
                      }`}>
                        {c.review_status}
                      </span>
                    </div>
                    {c.segment_key && (
                      <span className="text-[10px] text-slate-400">Segment: {c.segment_key}</span>
                    )}
                  </div>
                </div>
                <span className="text-[10px] text-slate-400">
                  <i className="fa-regular fa-clock mr-1"></i>
                  {new Date(c.created_at).toLocaleDateString()}
                </span>
              </div>

              {/* Config Preview */}
              <div className="bg-slate-50 p-3 rounded-xl mb-2 max-h-40 overflow-y-auto">
                <pre className="text-xs font-mono text-slate-700 whitespace-pre-wrap">
                  {JSON.stringify(c.config, null, 2)}
                </pre>
              </div>

              {/* Rationale & Tags */}
              {c.rationale && (
                <p className="text-xs text-slate-500 italic mb-2">"{c.rationale}"</p>
              )}
              {c.pattern_tags && c.pattern_tags.length > 0 && (
                <div className="flex flex-wrap gap-1 mb-3">
                  {c.pattern_tags.map((tag: string) => (
                    <span key={tag} className="text-[10px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">{tag}</span>
                  ))}
                </div>
              )}

              {/* Actions */}
              {c.review_status === 'pending' && (
                <div className="space-y-2">
                  <div className="flex gap-2">
                    <button
                      onClick={() => handleApprove(c.id)}
                      className="flex-1 bg-emerald-600 text-white py-2 rounded-xl text-xs font-bold hover:bg-emerald-700"
                    >
                      <i className="fa-solid fa-check mr-1"></i> Approve
                    </button>
                    <button
                      onClick={() => { setEditingId(c.id); setEditConfig(JSON.stringify(c.config, null, 2)); }}
                      className="flex-1 bg-blue-600 text-white py-2 rounded-xl text-xs font-bold hover:bg-blue-700"
                    >
                      <i className="fa-solid fa-pen mr-1"></i> Edit & Approve
                    </button>
                    <button
                      onClick={() => handleReject(c.id)}
                      className="flex-1 bg-rose-100 text-rose-700 py-2 rounded-xl text-xs font-bold hover:bg-rose-200"
                    >
                      <i className="fa-solid fa-xmark mr-1"></i> Reject
                    </button>
                  </div>
                  <div className="flex gap-2">
                    <input
                      type="text"
                      value={rejectNote}
                      onChange={e => setRejectNote(e.target.value)}
                      placeholder="Rejection note (optional)..."
                      className="flex-1 bg-slate-50 p-2 rounded-xl text-xs border-slate-200"
                    />
                  </div>
                </div>
              )}

              {/* Promoted Info */}
              {c.promoted_variant_id && (
                <p className="text-xs text-emerald-600 mt-2">
                  <i className="fa-solid fa-flask mr-1"></i>
                  Promoted to variant: {c.promoted_variant_id}
                  {c.reviewed_by && <> by {c.reviewed_by}</>}
                </p>
              )}
              {c.review_status === 'rejected' && c.safety_notes && (
                <p className="text-xs text-rose-600 mt-2">
                  <i className="fa-solid fa-comment mr-1"></i>
                  {c.safety_notes}
                </p>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Edit Modal */}
      {editingId && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-3xl max-w-2xl w-full max-h-[80vh] overflow-y-auto shadow-2xl">
            <div className="p-6 border-b border-slate-100 flex justify-between items-center">
              <h3 className="font-bold">Edit & Approve Variant</h3>
              <button onClick={() => setEditingId(null)} className="text-slate-400 hover:text-slate-600">
                <i className="fa-solid fa-xmark text-xl"></i>
              </button>
            </div>
            <div className="p-6 space-y-4">
              <p className="text-xs text-slate-500">Edit the config JSON before approving this variant. Changes will be saved as the promoted variant config.</p>
              <textarea
                className="w-full h-64 bg-slate-50 p-4 rounded-xl text-xs font-mono border-slate-200 focus:ring-2 focus:ring-indigo-500 outline-none resize-y"
                value={editConfig}
                onChange={e => setEditConfig(e.target.value)}
              />
              <div className="flex gap-3">
                <button
                  onClick={() => setEditingId(null)}
                  className="flex-1 bg-slate-100 text-slate-600 py-3 rounded-xl text-sm font-bold"
                >
                  Cancel
                </button>
                <button
                  onClick={() => handleEditAndApprove(editingId)}
                  className="flex-1 bg-indigo-600 text-white py-3 rounded-xl text-sm font-bold shadow-lg shadow-indigo-200"
                >
                  <i className="fa-solid fa-check mr-2"></i> Approve with Edits
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

// =============================================
// INSIGHTS PANEL
// =============================================

const InsightsPanel: React.FC = () => {
  const [insights, setInsights] = useState<Insight[]>([]);
  const [loading, setLoading] = useState(false);
  const [surfaceFilter, setSurfaceFilter] = useState('');

  const fetchInsights = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams();
    if (surfaceFilter) params.set('surface', surfaceFilter);
    api.get<Insight[]>(`/admin/variants/insights?${params.toString()}`)
      .then(setInsights)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [surfaceFilter]);

  useEffect(() => { fetchInsights(); }, [fetchInsights]);

  const handleToggle = async (id: string) => {
    try {
      await api.post(`/admin/variants/toggle/${id}`, {});
      fetchInsights();
    } catch (err: any) {
      alert('Failed to toggle insight: ' + err.message);
    }
  };

  return (
    <div className="space-y-4">
      {/* Filter */}
      <div className="flex gap-2 items-center">
        <label className="text-xs font-bold text-slate-400 uppercase">Surface:</label>
        <select
          value={surfaceFilter}
          onChange={e => setSurfaceFilter(e.target.value)}
          className="bg-slate-50 p-2 rounded-xl text-sm border-slate-200"
        >
          <option value="">All Surfaces</option>
          {SURFACES.map(s => (
            <option key={s.value} value={s.value}>{s.label}</option>
          ))}
        </select>
      </div>

      {loading ? (
        <div className="text-center py-10 text-slate-400">
          <i className="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
          <p className="text-sm">Loading insights...</p>
        </div>
      ) : insights.length === 0 ? (
        <div className="text-center py-16 text-slate-400">
          <i className="fa-solid fa-brain text-4xl mb-3"></i>
          <p className="font-bold">No insights yet</p>
          <p className="text-xs mt-1">Insights are generated from completed experiments</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
          {insights.map(i => (
            <div
              key={i.id}
              className={`bg-white p-5 rounded-3xl border shadow-sm transition-all ${
                i.is_active ? 'border-slate-100' : 'border-slate-200 border-dashed opacity-60'
              }`}
            >
              <div className="flex items-start justify-between mb-2">
                <div className="flex items-center gap-2">
                  <span className={`text-xs ${DIRECTION_ICON[i.direction] || ''}`}>
                    <i className={`fa-solid fa-arrow-up`}></i>
                  </span>
                  <span className="text-xs font-bold bg-slate-100 px-2 py-0.5 rounded">{i.pattern_tag}</span>
                  <span className="text-[10px] capitalize text-slate-400">{i.surface.replace(/_/g, ' ')}</span>
                  {i.segment_key && (
                    <span className="text-[10px] text-indigo-500 bg-indigo-50 px-1.5 py-0.5 rounded">{i.segment_key}</span>
                  )}
                </div>
                <button
                  onClick={() => handleToggle(i.id)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${
                    i.is_active ? 'bg-emerald-500' : 'bg-slate-300'
                  }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform ${
                      i.is_active ? 'translate-x-5' : ''
                    }`}
                  ></span>
                </button>
              </div>

              <p className="text-sm text-slate-700 mb-2">{i.summary}</p>

              <div className="flex flex-wrap gap-3 text-[10px] text-slate-500">
                {i.effect_size !== null && (
                  <span className={i.effect_size > 0 ? 'text-emerald-600 font-bold' : ''}>
                    Effect: {i.effect_size > 0 ? '+' : ''}{(i.effect_size * 100).toFixed(1)}%
                  </span>
                )}
                {i.confidence !== null && (
                  <span>Confidence: {(i.confidence * 100).toFixed(0)}%</span>
                )}
                {i.sample_size !== null && (
                  <span>Sample: {i.sample_size.toLocaleString()}</span>
                )}
                {i.experiment_count > 0 && (
                  <span>Experiments: {i.experiment_count}</span>
                )}
              </div>

              {i.weight !== null && (
                <div className="mt-2">
                  <div className="bg-slate-100 h-1.5 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-indigo-500 rounded-full"
                      style={{ width: `${Math.min(100, i.weight * 100)}%` }}
                    ></div>
                  </div>
                  <p className="text-[9px] text-slate-400 mt-0.5">Weight: {i.weight.toFixed(2)}</p>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default VariantStudio;
