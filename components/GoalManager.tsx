import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import { User, AppTab } from '../types';
import MultiStepForm from './ui/MultiStepForm';
import Card from './ui/Card';
import Button from './ui/Button';
import {
    XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, AreaChart, Area
} from 'recharts';
import { format } from 'date-fns';

interface Goal {
    id: string;
    user_id: string;
    title: string;
    description?: string;
    category: string;
    target_value: number;
    current_value: number;
    unit?: string;
    start_date: string;
    target_date?: string;
    status: 'active' | 'completed' | 'abandoned';
    priority: 'low' | 'medium' | 'high';
    created_at: string;
    updated_at: string;
    completed_at?: string;
    progress_percent: number;
    total_milestones?: number;
    achieved_milestones?: number;
    milestones?: Milestone[];
}

interface Milestone {
    id: string;
    goal_id: string;
    title: string;
    target_value: number;
    achieved: number;
    achieved_at?: string;
}

interface GoalStats {
    total_goals: number;
    active_goals: number;
    completed_goals: number;
    abandoned_goals: number;
    completion_rate: number;
    by_category: { category: string; count: number }[];
}

interface ProgressLog {
    id: string;
    value: number;
    notes: string;
    logged_at: string;
}

interface GoalManagerProps {
    user: User;
    onNavigate?: (tab: AppTab) => void;
}

const categoryIcons: Record<string, string> = {
    fitness: 'fa-person-running',
    wellness: 'fa-spa',
    nutrition: 'fa-apple-whole',
    cycle: 'fa-venus',
    custom: 'fa-bullseye'
};

const categoryColors: Record<string, { bg: string; text: string; border: string; highlight: string }> = {
    fitness: { bg: 'bg-emerald-50', text: 'text-emerald-600', border: 'border-emerald-200', highlight: 'bg-emerald-500' },
    wellness: { bg: 'bg-purple-50', text: 'text-purple-600', border: 'border-purple-200', highlight: 'bg-purple-500' },
    nutrition: { bg: 'bg-orange-50', text: 'text-orange-600', border: 'border-orange-200', highlight: 'bg-orange-500' },
    cycle: { bg: 'bg-rose-50', text: 'text-rose-600', border: 'border-rose-200', highlight: 'bg-rose-500' },
    custom: { bg: 'bg-indigo-50', text: 'text-indigo-600', border: 'border-indigo-200', highlight: 'bg-indigo-500' }
};

const GoalManager: React.FC<GoalManagerProps> = ({ user, onNavigate }) => {
    const [activeView, setActiveView] = useState<'active' | 'completed' | 'new'>('active');
    const [goals, setGoals] = useState<Goal[]>([]);
    const [stats, setStats] = useState<GoalStats | null>(null);
    const [loading, setLoading] = useState(true);
    const [selectedGoal, setSelectedGoal] = useState<Goal | null>(null);
    const [showProgressModal, setShowProgressModal] = useState(false);
    const [showHistoryModal, setShowHistoryModal] = useState(false);
    const [progressHistory, setProgressHistory] = useState<ProgressLog[]>([]);

    // Form states
    const [newGoal, setNewGoal] = useState({
        title: '',
        description: '',
        category: 'fitness',
        target_value: 0,
        unit: '',
        target_date: '',
        priority: 'medium' as 'low' | 'medium' | 'high'
    });
    const [progressValue, setProgressValue] = useState(0);
    const [progressNotes, setProgressNotes] = useState('');

    useEffect(() => {
        if (activeView !== 'new') {
            fetchData();
        }
    }, [user.id, activeView]);

    const fetchData = async () => {
        setLoading(true);
        try {
            const status = activeView === 'new' ? null : activeView;
            const [goalsRes, statsRes] = await Promise.all([
                api.get<Goal[]>(`/goals/user/${user.id}${status ? `?status=${status}` : ''}`),
                api.get<GoalStats>(`/goals/stats/${user.id}`)
            ]);
            setGoals(goalsRes || []);
            setStats(statsRes);
        } catch (err) {
            console.error('Failed to fetch goals', err);
        } finally {
            setLoading(false);
        }
    };

    const fetchHistory = async (goalId: string) => {
        try {
            const history = await api.get<ProgressLog[]>(`/goals/history/${goalId}`);
            const sorted = (history || []).sort((a, b) => new Date(a.logged_at).getTime() - new Date(b.logged_at).getTime());
            setProgressHistory(sorted);
        } catch (err) {
            console.error('Failed to fetch history', err);
        }
    };

    const handleCreateGoal = async () => {
        if (!newGoal.title.trim()) {
            alert('Please enter a goal title');
            return;
        }

        try {
            await api.post(`/goals/user/${user.id}`, newGoal);
            setNewGoal({
                title: '',
                description: '',
                category: 'fitness',
                target_value: 0,
                unit: '',
                target_date: '',
                priority: 'medium'
            });
            setActiveView('active');
        } catch (err) {
            console.error('Failed to create goal', err);
            alert('Failed to create goal. Please try again.');
        }
    };

    const handleLogProgress = async () => {
        if (!selectedGoal) return;

        try {
            await api.post(`/goals/progress/${selectedGoal.id}`, {
                value: progressValue,
                notes: progressNotes
            });
            // Dispatch XP Event for logging
            window.dispatchEvent(new CustomEvent('xp-gain', {
                detail: { amount: 10, reason: 'Goal Progress Logged' }
            }));
            setShowProgressModal(false);
            setProgressValue(0);
            setProgressNotes('');
            setSelectedGoal(null);
            fetchData();
        } catch (err) {
            console.error('Failed to log progress', err);
        }
    };

    const handleDeleteGoal = async (goalId: string) => {
        if (!confirm('Delete this goal? All progress will be lost.')) return;

        try {
            await api.delete(`/goals/goal/${goalId}`);
            fetchData();
        } catch (err) {
            console.error('Failed to delete goal', err);
        }
    };

    const handleCompleteGoal = async (goalId: string) => {
        try {
            await api.put(`/goals/goal/${goalId}`, { status: 'completed' });
            // Dispatch Major XP Event
            window.dispatchEvent(new CustomEvent('xp-gain', {
                detail: { amount: 100, reason: 'Epic Milestone Achieved' }
            }));
            fetchData();
        } catch (err) {
            console.error('Failed to complete goal', err);
        }
    };

    const getIntegrationAction = (category: string) => {
        if (!onNavigate) return null;

        switch (category) {
            case 'nutrition':
                return (
                    <Button variant="ghost" onClick={() => onNavigate(AppTab.MEAL_TRACKER)} className="w-full mt-2 text-xs !py-2 bg-orange-50 hover:bg-orange-100 text-orange-700">
                        <i className="fa-solid fa-utensils mr-2"></i> Log Meal
                    </Button>
                );
            case 'fitness':
            case 'wellness':
                return (
                    <Button variant="ghost" onClick={() => onNavigate(AppTab.ROUTINES)} className="w-full mt-2 text-xs !py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-700">
                        <i className="fa-solid fa-list-check mr-2"></i> Go to Routines
                    </Button>
                );
            case 'cycle':
                return (
                    <Button variant="ghost" onClick={() => onNavigate(AppTab.CYCLE_TRACKER)} className="w-full mt-2 text-xs !py-2 bg-rose-50 hover:bg-rose-100 text-rose-700">
                        <i className="fa-solid fa-calendar mr-2"></i> Check Cycle
                    </Button>
                );
            default:
                return null;
        }
    };

    // Steps for MultiStepForm
    const createSteps = [
        {
            title: 'Goal Basics',
            description: 'What do you want to achieve?',
            isValid: !!newGoal.title,
            component: (
                <div className="space-y-6">
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Goal Title</label>
                        <input
                            type="text"
                            value={newGoal.title}
                            onChange={(e) => setNewGoal({ ...newGoal, title: e.target.value })}
                            placeholder="e.g., Run 5k, Meditate daily"
                            className="w-full px-5 py-4 bg-stone-50 border border-stone-200 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500"
                            autoFocus
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Description (Optional)</label>
                        <textarea
                            value={newGoal.description}
                            onChange={(e) => setNewGoal({ ...newGoal, description: e.target.value })}
                            placeholder="Why is this important to you?"
                            rows={3}
                            className="w-full px-5 py-4 bg-stone-50 border border-stone-200 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 resize-none"
                        />
                    </div>
                </div>
            )
        },
        {
            title: 'Categorize',
            description: 'Help us organize your wellness journey.',
            component: (
                <div className="space-y-6">
                    <div className="grid grid-cols-2 gap-4">
                        {Object.keys(categoryIcons).map((cat) => (
                            <div
                                key={cat}
                                onClick={() => setNewGoal({ ...newGoal, category: cat })}
                                className={`cursor-pointer p-4 rounded-xl border-2 transition-all flex flex-col items-center gap-2 ${newGoal.category === cat
                                    ? 'border-brand-500 bg-brand-50 text-brand-700 shadow-md'
                                    : 'border-stone-100 bg-white text-stone-400 hover:border-stone-200 hover:bg-stone-50'
                                    }`}
                            >
                                <i className={`fa-solid ${categoryIcons[cat]} text-2xl`}></i>
                                <span className="text-sm font-bold capitalize">{cat}</span>
                            </div>
                        ))}
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Priority Level</label>
                        <div className="flex gap-3">
                            {['low', 'medium', 'high'].map((p) => (
                                <button
                                    key={p}
                                    onClick={() => setNewGoal({ ...newGoal, priority: p as any })}
                                    className={`flex-1 py-3 rounded-xl font-bold text-sm capitalize transition-all ${newGoal.priority === p
                                        ? 'bg-stone-900 text-white shadow-lg'
                                        : 'bg-stone-100 text-stone-500 hover:bg-stone-200'
                                        }`}
                                >
                                    {p}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            )
        },
        {
            title: 'Set Targets',
            description: 'Define success metrics.',
            isValid: newGoal.target_value > 0,
            component: (
                <div className="space-y-6">
                    <div className="grid grid-cols-2 gap-6">
                        <div>
                            <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Target Value</label>
                            <input
                                type="number"
                                value={newGoal.target_value}
                                onChange={(e) => setNewGoal({ ...newGoal, target_value: parseFloat(e.target.value) || 0 })}
                                className="w-full px-5 py-4 bg-stone-50 border border-stone-200 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Unit</label>
                            <input
                                type="text"
                                value={newGoal.unit}
                                onChange={(e) => setNewGoal({ ...newGoal, unit: e.target.value })}
                                placeholder="kg, mins, etc."
                                className="w-full px-5 py-4 bg-stone-50 border border-stone-200 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Deadline (Optional)</label>
                        <input
                            type="date"
                            value={newGoal.target_date}
                            onChange={(e) => setNewGoal({ ...newGoal, target_date: e.target.value })}
                            className="w-full px-4 py-3 bg-stone-50 border border-stone-200 rounded-xl text-stone-600 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                        />
                    </div>
                </div>
            )
        }
    ];

    if (loading && activeView !== 'new') {
        return (
            <div className="flex items-center justify-center h-64">
                <i className="fa-solid fa-circle-notch fa-spin text-3xl text-brand-500"></i>
            </div>
        );
    }

    return (
        <div className="space-y-8 animate-in fade-in duration-500 max-w-6xl mx-auto pb-12">
            {/* Header */}
            <div className="flex flex-col md:flex-row justify-between items-end gap-6 border-b border-stone-100 pb-8">
                <div>
                    <h1 className="text-4xl font-serif text-stone-900 mb-2">Goal Manager</h1>
                    <p className="text-stone-500 text-lg">Design your ideal future, one step at a time.</p>
                </div>

                {/* View Toggles */}
                <div className="flex bg-stone-100 p-1.5 rounded-xl shadow-inner">
                    {[
                        { id: 'active', label: 'In Progress' },
                        { id: 'completed', label: 'History' },
                        { id: 'new', label: 'New Goal', icon: 'fa-plus' }
                    ].map(tab => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveView(tab.id as any)}
                            className={`px-6 py-3 rounded-lg text-sm font-bold transition-all flex items-center gap-2 ${activeView === tab.id
                                ? 'bg-white text-stone-900 shadow-md transform scale-105'
                                : 'text-stone-500 hover:text-stone-700 hover:bg-stone-200/50'
                                }`}
                        >
                            {tab.icon && <i className={`fa-solid ${tab.icon}`}></i>}
                            {tab.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* Stats Overview */}
            {stats && activeView !== 'new' && (
                <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
                    {[
                        { label: 'Active Goals', value: stats.active_goals, color: 'text-brand-600', icon: 'fa-person-running', bg: 'bg-brand-50' },
                        { label: 'Completed', value: stats.completed_goals, color: 'text-emerald-600', icon: 'fa-trophy', bg: 'bg-emerald-50' },
                        { label: 'Total Goals', value: stats.total_goals, color: 'text-stone-800', icon: 'fa-list', bg: 'bg-stone-50' },
                        { label: 'Success Rate', value: `${stats.completion_rate}%`, color: 'text-amber-600', icon: 'fa-chart-pie', bg: 'bg-amber-50' }
                    ].map((stat, i) => (
                        <Card key={i} className="!p-6 border-none shadow-sm flex items-center gap-4 hover:-translate-y-1 transition-transform cursor-default">
                            <div className={`w-12 h-12 rounded-xl flex items-center justify-center text-xl ${stat.bg} ${stat.color}`}>
                                <i className={`fa-solid ${stat.icon}`}></i>
                            </div>
                            <div>
                                <p className="text-[10px] font-bold text-stone-400 uppercase tracking-widest leading-none mb-1">{stat.label}</p>
                                <p className={`text-2xl font-serif font-black ${stat.color}`}>{stat.value}</p>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            {/* Content Area */}
            {activeView === 'new' ? (
                <div className="max-w-3xl mx-auto py-8">
                    <MultiStepForm
                        steps={createSteps}
                        onComplete={handleCreateGoal}
                        onCancel={() => setActiveView('active')}
                        submitLabel="Create Goal"
                    />
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {goals.length === 0 ? (
                        <div className="col-span-full py-16 text-center bg-stone-50 rounded-[2.5rem] border border-stone-100 border-dashed">
                            <div className="w-20 h-20 bg-white rounded-full flex items-center justify-center mx-auto mb-6 text-brand-200 text-3xl shadow-sm">
                                <i className="fa-solid fa-seedling"></i>
                            </div>
                            <h3 className="text-2xl font-serif text-stone-700 mb-2">Start Your Journey</h3>
                            <p className="text-stone-400 mb-8 max-w-sm mx-auto">"A goal without a plan is just a wish." Create your first goal to begin tracking your progress.</p>
                            <Button onClick={() => setActiveView('new')} className="shadow-lg shadow-brand-500/20">
                                <i className="fa-solid fa-plus mr-2"></i> Create First Goal
                            </Button>
                        </div>
                    ) : (
                        goals.map(goal => {
                            const colors = categoryColors[goal.category] || categoryColors.custom;
                            const progress = Math.min(goal.progress_percent, 100);

                            return (
                                <Card key={goal.id} className="group hover:border-brand-200 transition-all duration-300 flex flex-col h-full">
                                    <div className="flex justify-between items-start mb-6">
                                        <div className="flex gap-4">
                                            <div className={`w-14 h-14 rounded-2xl flex items-center justify-center text-2xl shadow-sm ${colors.bg} ${colors.text}`}>
                                                <i className={`fa-solid ${categoryIcons[goal.category] || 'fa-bullseye'}`}></i>
                                            </div>
                                            <div>
                                                <div className="flex items-center gap-2 mb-1">
                                                    <span className={`text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded-md ${colors.bg} ${colors.text}`}>{goal.priority} Priority</span>
                                                    <span className="text-[10px] font-bold text-stone-400 uppercase tracking-widest">{goal.category}</span>
                                                </div>
                                                <h3 className="font-serif text-xl text-stone-800 leading-tight">{goal.title}</h3>
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            {activeView === 'active' && (
                                                <>
                                                    <button
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setSelectedGoal(goal);
                                                            fetchHistory(goal.id);
                                                            setShowHistoryModal(true);
                                                        }}
                                                        className="w-8 h-8 flex items-center justify-center rounded-full text-stone-300 hover:bg-stone-50 hover:text-stone-500 transition-colors"
                                                        title="View History"
                                                    >
                                                        <i className="fa-solid fa-chart-line"></i>
                                                    </button>
                                                    <button
                                                        onClick={(e) => { e.stopPropagation(); handleDeleteGoal(goal.id); }}
                                                        className="w-8 h-8 flex items-center justify-center rounded-full text-stone-300 hover:bg-rose-50 hover:text-rose-500 transition-colors"
                                                        title="Delete Goal"
                                                    >
                                                        <i className="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </>
                                            )}
                                        </div>
                                    </div>

                                    <div className="space-y-3 mb-8 flex-1">
                                        <div className="flex justify-between items-end">
                                            <div className="text-3xl font-serif text-stone-900 font-bold">
                                                {progress.toFixed(0)}<span className="text-base font-sans font-medium text-stone-400">%</span>
                                            </div>
                                            <div className="text-right">
                                                <span className="text-xs font-bold text-stone-500 block">Current: {goal.current_value} {goal.unit}</span>
                                                <span className="text-[10px] font-bold text-stone-300 uppercase tracking-widest">Target: {goal.target_value} {goal.unit}</span>
                                            </div>
                                        </div>

                                        <div className="h-3 bg-stone-100 rounded-full overflow-hidden p-0.5">
                                            <div
                                                className={`h-full rounded-full transition-all duration-1000 ${progress >= 100 ? 'bg-emerald-500' : colors.highlight}`}
                                                style={{ width: `${progress}%` }}
                                            ></div>
                                        </div>

                                        {goal.target_date && (
                                            <p className="text-xs text-stone-400 italic text-right mt-1">
                                                Deadline: {new Date(goal.target_date).toLocaleDateString()}
                                            </p>
                                        )}
                                    </div>

                                    {activeView === 'active' && (
                                        <div className="space-y-3 pt-4 border-t border-stone-100">
                                            <div className="flex gap-3">
                                                <Button
                                                    variant="primary"
                                                    className="flex-1 text-xs shadow-md"
                                                    onClick={() => {
                                                        setSelectedGoal(goal);
                                                        setProgressValue(goal.current_value);
                                                        setShowProgressModal(true);
                                                    }}
                                                >
                                                    Log Progress
                                                </Button>
                                                <Button
                                                    variant="secondary"
                                                    className={`!px-4 text-emerald-600 bg-emerald-50 hover:bg-emerald-100 border-none ${progress >= 100 ? 'animate-pulse' : ''}`}
                                                    onClick={() => handleCompleteGoal(goal.id)}
                                                    title="Mark Complete"
                                                >
                                                    <i className="fa-solid fa-check"></i>
                                                </Button>
                                            </div>
                                            {getIntegrationAction(goal.category)}
                                        </div>
                                    )}

                                    {activeView === 'completed' && (
                                        <div className="p-4 bg-emerald-50 rounded-xl text-emerald-800 text-sm font-bold text-center border border-emerald-100">
                                            <i className="fa-solid fa-trophy mr-2 text-emerald-600"></i> Goal Achieved!
                                            <p className="text-[10px] text-emerald-600/70 font-normal mt-1">Completed on {goal.completed_at ? new Date(goal.completed_at).toLocaleDateString() : 'Unknown date'}</p>
                                        </div>
                                    )}
                                </Card>
                            );
                        })
                    )}
                </div>
            )}

            {/* History Chart Modal */}
            {showHistoryModal && selectedGoal && (
                <div className="fixed inset-0 z-50 bg-stone-900/60 backdrop-blur-sm flex items-center justify-center p-4 animate-in fade-in">
                    <Card className="w-full max-w-2xl !p-0 shadow-2xl relative overflow-hidden animate-in zoom-in-95 duration-300">
                        <div className="p-8 border-b border-stone-100 flex justify-between items-center bg-stone-50/50">
                            <div>
                                <span className="text-[10px] font-bold uppercase tracking-widest text-stone-400">{selectedGoal.category}</span>
                                <h2 className="text-2xl font-serif text-stone-800 leading-tight mt-1">{selectedGoal.title} Progress</h2>
                            </div>
                            <button onClick={() => setShowHistoryModal(false)} className="w-8 h-8 rounded-full bg-stone-100 flex items-center justify-center text-stone-400 hover:bg-stone-200 transition-colors">
                                <i className="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <div className="p-8 h-80">
                            {progressHistory.length > 0 ? (
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart data={progressHistory}>
                                        <defs>
                                            <linearGradient id="colorValue" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#4f46e5" stopOpacity={0.1} />
                                                <stop offset="95%" stopColor="#4f46e5" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f5f5f4" />
                                        <XAxis
                                            dataKey="logged_at"
                                            tickFormatter={(str) => format(new Date(str), 'MMM d')}
                                            axisLine={false}
                                            tickLine={false}
                                            tick={{ fontSize: 10, fill: '#a8a29e' }}
                                            dy={10}
                                        />
                                        <YAxis
                                            axisLine={false}
                                            tickLine={false}
                                            tick={{ fontSize: 10, fill: '#a8a29e' }}
                                        />
                                        <Tooltip
                                            contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                                            labelFormatter={(l) => format(new Date(l), 'PPP')}
                                        />
                                        <Area
                                            type="monotone"
                                            dataKey="value"
                                            stroke="#4f46e5"
                                            fillOpacity={1}
                                            fill="url(#colorValue)"
                                            strokeWidth={3}
                                        />
                                    </AreaChart>
                                </ResponsiveContainer>
                            ) : (
                                <div className="h-full flex items-center justify-center text-stone-400">
                                    <p>No history yet. Log your first check-in!</p>
                                </div>
                            )}
                        </div>
                    </Card>
                </div>
            )}

            {/* Update Modal */}
            {showProgressModal && selectedGoal && (
                <div className="fixed inset-0 z-50 bg-stone-900/60 backdrop-blur-sm flex items-center justify-center p-4 animate-in fade-in">
                    <Card className="w-full max-w-md !p-0 shadow-2xl relative overflow-hidden animate-in zoom-in-95 duration-300">
                        <div className={`h-2 w-full absolute top-0 left-0 ${categoryColors[selectedGoal.category]?.highlight || 'bg-brand-500'}`}></div>

                        <div className="p-8 pb-0">
                            <div className="flex justify-between items-start mb-6">
                                <div>
                                    <span className="text-[10px] font-bold uppercase tracking-widest text-stone-400">{selectedGoal.category}</span>
                                    <h2 className="text-2xl font-serif text-stone-800 leading-tight mt-1">{selectedGoal.title}</h2>
                                </div>
                                <button onClick={() => setShowProgressModal(false)} className="w-8 h-8 rounded-full bg-stone-100 flex items-center justify-center text-stone-400 hover:bg-stone-200 hover:text-stone-600 transition-colors">
                                    <i className="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>

                        <div className="p-8 pt-2 space-y-6">
                            <div>
                                <p className="text-sm font-bold text-stone-500 mb-3 text-center uppercase tracking-widest">Update Current Value</p>
                                <div className="flex items-center gap-4 justify-center py-8 bg-stone-50 rounded-[2rem] border border-stone-100 relative group hover:border-brand-200 transition-colors">
                                    <button
                                        className="w-10 h-10 rounded-full bg-white shadow-sm text-stone-400 hover:text-brand-600 hover:shadow-md transition-all text-lg"
                                        onClick={() => setProgressValue(Math.max(0, progressValue - 1))}
                                    >
                                        <i className="fa-solid fa-minus"></i>
                                    </button>

                                    <div className="flex flex-col items-center">
                                        <input
                                            type="number"
                                            value={progressValue}
                                            onChange={(e) => setProgressValue(parseFloat(e.target.value) || 0)}
                                            className="text-5xl font-serif bg-transparent text-center w-32 focus:outline-none text-stone-800 placeholder-stone-300 font-bold"
                                            autoFocus
                                        />
                                        <span className="text-stone-400 font-bold uppercase tracking-wider text-xs bg-white px-3 py-1 rounded-full shadow-sm mt-2">{selectedGoal.unit}</span>
                                    </div>

                                    <button
                                        className="w-10 h-10 rounded-full bg-white shadow-sm text-stone-400 hover:text-brand-600 hover:shadow-md transition-all text-lg"
                                        onClick={() => setProgressValue(progressValue + 1)}
                                    >
                                        <i className="fa-solid fa-plus"></i>
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Progress Note (Optional)</label>
                                <textarea
                                    value={progressNotes}
                                    onChange={(e) => setProgressNotes(e.target.value)}
                                    placeholder="What did you achieve today?"
                                    rows={2}
                                    className="w-full px-5 py-3 bg-stone-50 border border-stone-100 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:bg-white transition-colors resize-none"
                                />
                            </div>

                            <Button onClick={handleLogProgress} className="w-full py-4 text-base shadow-xl shadow-brand-500/20">
                                Save Progress
                            </Button>
                        </div>
                    </Card>
                </div>
            )}
        </div>
    );
};

export default GoalManager;
