
import React, { useState, useEffect } from 'react';
import { api } from '../../services/api';
import Card from '../ui/Card';
import Button from '../ui/Button';

interface Cohort {
    id: string;
    title: string;
    description: string;
    category: string;
    objectives: string;
    duration_days: number;
    start_date: string;
    end_date?: string;
    max_participants: number;
    participant_count: number;
    is_public: number;
    image?: string;
    image_url?: string;
    price?: number;
}

interface Participant {
    user_id: string;
    user_name: string;
    persona: string;
    current_day: number;
    performance_score: number;
    completion_rate?: number;
    last_active: string;
}

export const CohortManagement: React.FC = () => {
    const [activeTab, setActiveTab] = useState<'active' | 'upcoming' | 'past'>('active');
    const [cohorts, setCohorts] = useState<Cohort[]>([]);
    const [loading, setLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);
    const [editingCohort, setEditingCohort] = useState<Cohort | null>(null);
    const [selectedCohortId, setSelectedCohortId] = useState<string | null>(null);
    const [participants, setParticipants] = useState<Participant[]>([]);
    const [insights, setInsights] = useState<any>(null);
    const [generatingInsight, setGeneratingInsight] = useState<string | null>(null);

    useEffect(() => {
        fetchCohorts();
    }, [activeTab]);

    const fetchCohorts = async () => {
        setLoading(true);
        try {
            const res = await api.get<any>(`/cohorts?status=${activeTab}`);
            setCohorts(res.cohorts || []);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const fetchParticipants = async (cohortId: string) => {
        try {
            const res = await api.get<any>(`/cohorts/${cohortId}/participants`);
            setParticipants(res.participants || []);
            setSelectedCohortId(cohortId);
        } catch (err) {
            console.error(err);
        }
    };

    const handleDelete = async (id: string) => {
        if (!window.confirm('Are you sure you want to delete this cohort?')) return;
        try {
            await api.delete(`/cohorts/${id}`);
            fetchCohorts();
        } catch (err) {
            alert('Failed to delete cohort');
        }
    };

    const handleSave = async (e: React.FormEvent) => {
        e.preventDefault();
        const formData = new FormData(e.target as HTMLFormElement);
        const data = Object.fromEntries(formData.entries());

        // Add default duration if missing (for task calculation)
        if (!data.duration_days) data.duration_days = "21";

        try {
            if (editingCohort) {
                await api.put(`/cohorts/${editingCohort.id}`, data);
            } else {
                await api.post('/cohorts', data);
            }
            setShowModal(false);
            fetchCohorts();
        } catch (err) {
            alert('Failed to save cohort');
        }
    };

    const generateInsight = async (userId: string) => {
        if (!selectedCohortId) return;
        setGeneratingInsight(userId);
        try {
            const res = await api.get<any>(`/cohorts/${selectedCohortId}/participants/${userId}/insights`);
            setInsights({ userId, text: res.insights });
        } catch (err) {
            console.error(err);
        } finally {
            setGeneratingInsight(null);
        }
    };

    return (
        <div className="space-y-6 animate-in fade-in duration-500">
            <div className="flex justify-between items-center">
                <h2 className="text-2xl font-serif text-stone-900">Cohort Management</h2>
                <Button onClick={() => { setEditingCohort(null); setShowModal(true); }}>
                    <i className="fa-solid fa-plus mr-2"></i> New Cohort
                </Button>
            </div>

            {/* Tabs */}
            <div className="flex gap-2 border-b border-stone-200 mb-6">
                {(['active', 'upcoming', 'past'] as const).map((tab) => (
                    <button
                        key={tab}
                        onClick={() => { setActiveTab(tab); setSelectedCohortId(null); }}
                        className={`px-4 py-2 text-sm font-bold uppercase tracking-wider border-b-2 transition-colors ${activeTab === tab ? 'border-brand-500 text-brand-600' : 'border-transparent text-stone-400 hover:text-stone-600'
                            }`}
                    >
                        {tab}
                    </button>
                ))}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Cohort List */}
                <div className="space-y-4">
                    {loading ? (
                        <p className="text-stone-400">Loading cohorts...</p>
                    ) : cohorts.length === 0 ? (
                        <p className="text-stone-400">No {activeTab} cohorts found.</p>
                    ) : (
                        cohorts.map(cohort => (
                            <Card
                                key={cohort.id}
                                className={`cursor-pointer transition-all hover:border-brand-300 ${selectedCohortId === cohort.id ? 'border-brand-500 ring-1 ring-brand-500' : ''}`}
                                onClick={() => fetchParticipants(cohort.id)}
                            >
                                <div className="flex justify-between items-start mb-2">
                                    <h3 className="font-bold text-lg text-stone-800">{cohort.title}</h3>
                                    <div className="flex gap-2">
                                        <button onClick={(e) => { e.stopPropagation(); setEditingCohort(cohort); setShowModal(true); }} className="text-stone-400 hover:text-brand-500">
                                            <i className="fa-solid fa-pen"></i>
                                        </button>
                                        <button onClick={(e) => { e.stopPropagation(); handleDelete(cohort.id); }} className="text-stone-400 hover:text-rose-500">
                                            <i className="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <p className="text-sm text-stone-500 mb-3 line-clamp-2">{cohort.description}</p>
                                <div className="flex justify-between text-xs text-stone-400 uppercase tracking-wider font-bold">
                                    <span><i className="fa-solid fa-users mr-1"></i> {cohort.participant_count}/{cohort.max_participants}</span>
                                    <span>{cohort.price ? `$${(cohort.price / 100).toFixed(2)}` : 'FREE'}</span>
                                    <span><i className="fa-regular fa-calendar mr-1"></i> {new Date(cohort.start_date).toLocaleDateString()}</span>
                                </div>
                            </Card>
                        ))
                    )}
                </div>

                {/* Participant Detail */}
                <div className="lg:col-span-2">
                    {selectedCohortId ? (
                        <Card className="min-h-[500px]">
                            <div className="flex justify-between items-center mb-6">
                                <h3 className="text-xl font-serif text-stone-800">Participants</h3>
                                <span className="text-sm text-stone-500">{participants.length} members</span>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-left border-collapse">
                                    <thead>
                                        <tr className="text-xs font-bold text-stone-400 uppercase tracking-widest border-b border-stone-100">
                                            <th className="pb-3 pl-2">Member</th>
                                            <th className="pb-3">Day</th>
                                            <th className="pb-3">Score</th>
                                            <th className="pb-3">Last Active</th>
                                            <th className="pb-3 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="text-sm">
                                        {participants.map(p => (
                                            <React.Fragment key={p.user_id}>
                                                <tr className="border-b border-stone-50 hover:bg-stone-50/50">
                                                    <td className="py-3 pl-2 font-medium text-stone-800">
                                                        {p.user_name}
                                                        <span className="block text-xs text-stone-400 font-normal">{p.persona}</span>
                                                    </td>
                                                    <td className="py-3 text-stone-600">Day {p.current_day}</td>
                                                    <td className="py-3">
                                                        <span className={`px-2 py-1 rounded text-xs font-bold ${p.performance_score >= 80 ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>
                                                            {p.performance_score}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 text-stone-400 text-xs">
                                                        {p.last_active ? new Date(p.last_active).toLocaleDateString() : 'Never'}
                                                    </td>
                                                    <td className="py-3 text-right">
                                                        <button
                                                            onClick={() => generateInsight(p.user_id)}
                                                            className="text-xs bg-brand-50 text-brand-600 px-3 py-1 rounded hover:bg-brand-100 transition-colors"
                                                            disabled={generatingInsight === p.user_id}
                                                        >
                                                            {generatingInsight === p.user_id ? <i className="fa-solid fa-spinner animate-spin"></i> : <i className="fa-solid fa-wand-magic-sparkles"></i>} Insight
                                                        </button>
                                                    </td>
                                                </tr>
                                                {insights?.userId === p.user_id && (
                                                    <tr>
                                                        <td colSpan={5} className="p-4 bg-brand-50/30">
                                                            <div className="p-4 bg-white rounded-xl border border-brand-100 shadow-sm relative">
                                                                <i className="fa-solid fa-robot absolute top-3 right-3 text-brand-200 text-xl"></i>
                                                                <h5 className="font-bold text-brand-800 text-xs uppercase tracking-widest mb-2">AI Analysis</h5>
                                                                <p className="text-stone-600 text-sm leading-relaxed whitespace-pre-wrap">{insights.text}</p>
                                                                <button onClick={() => setInsights(null)} className="text-xs text-stone-400 hover:text-stone-600 mt-2 underline">Close</button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                )}
                                            </React.Fragment>
                                        ))}
                                    </tbody>
                                </table>
                                {participants.length === 0 && (
                                    <div className="text-center py-12 text-stone-400">
                                        <p>No participants enrolled yet.</p>
                                    </div>
                                )}
                            </div>
                        </Card>
                    ) : (
                        <div className="flex flex-col items-center justify-center h-full text-stone-300 border-2 border-dashed border-stone-200 rounded-3xl p-12">
                            <i className="fa-solid fa-users-viewfinder text-4xl mb-4"></i>
                            <p>Select a cohort to view details</p>
                        </div>
                    )}
                </div>
            </div>

            {/* Create/Edit Modal */}
            {showModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-stone-900/50 backdrop-blur-sm">
                    <Card className="w-full max-w-lg max-h-[90vh] overflow-y-auto">
                        <h3 className="text-xl font-serif text-stone-800 mb-6">
                            {editingCohort ? 'Edit Cohort' : 'Create New Cohort'}
                        </h3>
                        <form onSubmit={handleSave} className="space-y-4">
                            <div>
                                <label className="block text-xs font-bold text-stone-500 uppercase mb-1">Title</label>
                                <input name="title" defaultValue={editingCohort?.title} required className="w-full p-2 border border-stone-200 rounded-lg" />
                            </div>
                            <div>
                                <label className="block text-xs font-bold text-stone-500 uppercase mb-1">Description</label>
                                <textarea name="description" defaultValue={editingCohort?.description} className="w-full p-2 border border-stone-200 rounded-lg h-24" />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-bold text-stone-500 uppercase mb-1">Category</label>
                                    <select name="category" defaultValue={editingCohort?.category || 'wellness'} className="w-full p-2 border border-stone-200 rounded-lg">
                                        <option value="wellness">Wellness</option>
                                        <option value="fitness">Fitness</option>
                                        <option value="mindfulness">Mindfulness</option>
                                        <option value="nutrition">Nutrition</option>
                                    </select>
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-xs font-bold text-stone-500 uppercase mb-1">Duration (Days)</label>
                                        <input type="number" name="duration_days" defaultValue={editingCohort?.duration_days ?? 21} className="w-full p-2 border border-stone-200 rounded-lg" />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-bold text-stone-500 uppercase mb-1">Price (Cents)</label>
                                        <input type="number" name="price" defaultValue={editingCohort?.price ?? 0} className="w-full p-2 border border-stone-200 rounded-lg" />
                                    </div>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-bold text-stone-500 uppercase mb-1">Start Date</label>
                                    <input type="date" name="start_date" defaultValue={editingCohort?.start_date} required className="w-full p-2 border border-stone-200 rounded-lg" />
                                </div>
                                <div>
                                    <label className="block text-xs font-bold text-stone-500 uppercase mb-1">End Date</label>
                                    <input type="date" name="end_date" defaultValue={editingCohort?.end_date} className="w-full p-2 border border-stone-200 rounded-lg" />
                                </div>
                            </div>
                            <div>
                                <label className="block text-xs font-bold text-stone-500 uppercase mb-1">Max Users</label>
                                <input type="number" name="max_participants" defaultValue={editingCohort?.max_participants || 100} className="w-full p-2 border border-stone-200 rounded-lg" />
                            </div>
                            <div>
                                <label className="block text-xs font-bold text-stone-500 uppercase mb-1">Image URL</label>
                                <input type="url" name="image_url" defaultValue={editingCohort?.image} placeholder="https://example.com/image.jpg" className="w-full p-2 border border-stone-200 rounded-lg text-sm" />
                                <p className="text-[10px] text-stone-400 mt-1">Leave empty for auto-generated image</p>
                            </div>
                            <div>
                                <label className="block text-xs font-bold text-stone-500 uppercase mb-1">AI Objectives Hint</label>
                                <textarea name="objectives" defaultValue={editingCohort?.objectives} placeholder="Guide AI on what this cohort aims to achieve..." className="w-full p-2 border border-stone-200 rounded-lg h-20 text-sm" />
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t border-stone-100">
                                <Button variant="secondary" onClick={() => setShowModal(false)} type="button">Cancel</Button>
                                <Button type="submit">Save Cohort</Button>
                            </div>
                        </form>
                    </Card>
                </div>
            )}
        </div>
    );
};
