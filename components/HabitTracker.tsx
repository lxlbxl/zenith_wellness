
import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import { User, CyclePhase } from '../types';
import Card from './ui/Card';
import Button from './ui/Button';

interface HabitTemplate {
    id: string;
    title: string;
    description?: string;
    category?: string;
    icon?: string;
    recommended_frequency?: string;
}

interface AIRecommendation {
    title: string;
    description: string;
    icon: string;
    frequency: string;
    category: string;
}

interface AIResponse {
    phase: CyclePhase;
    recommendations: AIRecommendation[];
}

interface Habit {
    id: string;
    title: string;
    description?: string;
    category?: string;
    icon?: string;
    frequency: string;
    current_streak: number;
    longest_streak: number;
    total_completions: number;
    is_active: number;
    completed_today?: boolean;
    template_id?: string;
}

interface HabitStats {
    active_habits: number;
    total_completions: number;
    best_streak: number;
    today_completion_rate: number;
    today_completed: number;
    today_total: number;
}

interface HabitTrackerProps {
    user: User;
}

const categoryColors: Record<string, { bg: string; text: string; border: string }> = {
    hydration: { bg: 'bg-blue-50', text: 'text-blue-600', border: 'border-blue-100' },
    movement: { bg: 'bg-emerald-50', text: 'text-emerald-600', border: 'border-emerald-100' },
    self_care: { bg: 'bg-rose-50', text: 'text-rose-600', border: 'border-rose-100' },
    nutrition: { bg: 'bg-orange-50', text: 'text-orange-600', border: 'border-orange-100' },
    mindfulness: { bg: 'bg-brand-50', text: 'text-brand-600', border: 'border-brand-100' },
    other: { bg: 'bg-stone-50', text: 'text-stone-600', border: 'border-stone-200' }
};

const HabitTracker: React.FC<HabitTrackerProps> = ({ user }) => {
    const [activeView, setActiveView] = useState<'today' | 'all' | 'add'>('today');
    const [todayHabits, setTodayHabits] = useState<Habit[]>([]);
    const [allHabits, setAllHabits] = useState<Habit[]>([]);
    const [templates, setTemplates] = useState<HabitTemplate[]>([]);
    const [stats, setStats] = useState<HabitStats | null>(null);
    const [loading, setLoading] = useState(true);

    // AI Recommendations State
    const [showAIModal, setShowAIModal] = useState(false);
    const [recommendations, setRecommendations] = useState<AIRecommendation[]>([]);
    const [aiPhase, setAiPhase] = useState<string | null>(null);
    const [generating, setGenerating] = useState(false);

    useEffect(() => {
        fetchData();
    }, [user.id]);

    const fetchData = async () => {
        setLoading(true);
        try {
            const [todayRes, allRes, templatesRes, statsRes] = await Promise.all([
                api.get<Habit[]>(`/habits/today/${user.id}`),
                api.get<Habit[]>(`/habits/user/${user.id}`),
                api.get<HabitTemplate[]>('/habits/templates'),
                api.get<HabitStats>(`/habits/stats/${user.id}`)
            ]);
            setTodayHabits(todayRes || []);
            setAllHabits(allRes || []);
            setTemplates(templatesRes || []);
            setStats(statsRes);
        } catch (err) {
            console.error('Failed to fetch habits', err);
        } finally {
            setLoading(false);
        }
    };

    const handleToggleHabit = async (habitId: string, isCompleted: boolean) => {
        try {
            if (isCompleted) {
                // Unlog
                await api.delete('/habits/log', { habit_id: habitId, log_date: new Date().toISOString().split('T')[0] });
            } else {
                // Log completion
                await api.post('/habits/log', { habit_id: habitId, log_date: new Date().toISOString().split('T')[0] });
                // Dispatch XP Event
                window.dispatchEvent(new CustomEvent('xp-gain', {
                    detail: { amount: 15, reason: 'Habit Completed' }
                }));
            }
            fetchData();
        } catch (err) {
            console.error('Failed to toggle habit', err);
        }
    };

    const handleAddHabit = async (template: HabitTemplate) => {
        try {
            await api.post(`/habits/user/${user.id}`, {
                template_id: template.id,
                title: template.title,
                description: template.description,
                category: template.category,
                icon: template.icon,
                frequency: template.recommended_frequency
            });
            fetchData();
            setActiveView('today');
        } catch (err) {
            console.error('Failed to add habit', err);
        }
    };

    const handleDeleteHabit = async (habitId: string) => {
        if (!confirm('Delete this habit? All tracking data will be lost.')) return;
        try {
            await api.delete(`/habits/${habitId}`);
            fetchData();
        } catch (err) {
            console.error('Failed to delete habit', err);
        }
    };

    const [aiError, setAiError] = useState<string | null>(null);

    const handleGenerateRecommendations = async () => {
        setGenerating(true);
        setShowAIModal(true);
        setAiError(null);
        try {
            const res = await api.get<AIResponse>(`/habits/recommend/${user.id}`);
            if (res) {
                setRecommendations(res.recommendations);
                setAiPhase(res.phase);
            }
        } catch (err: any) {
            console.error("AI Error", err);
            setAiError(err?.message || "Could not generate recommendations. Please try again.");
        } finally {
            setGenerating(false);
        }
    };

    const handleAddRecommendation = async (rec: AIRecommendation) => {
        try {
            await api.post(`/habits/user/${user.id}`, {
                title: rec.title,
                description: rec.description,
                category: rec.category,
                icon: rec.icon,
                frequency: rec.frequency,
                template_id: 'ai_generated'
            });
            setShowAIModal(false);
            fetchData();
            setActiveView('today');
        } catch (err) {
            console.error('Failed to add recommendation', err);
        }
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center h-64">
                <i className="fa-solid fa-circle-notch fa-spin text-3xl text-brand-500"></i>
            </div>
        );
    }

    const completionPercent = stats ? stats.today_completion_rate : 0;

    return (
        <div className="space-y-8">
            {/* Header & Stats */}
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-2xl font-black text-stone-900 tracking-tight">Consistency</h2>
                    <p className="text-stone-500 text-sm mt-1">Small steps, every single day.</p>
                </div>
            </div>

            {/* Progress Ring Card */}
            {stats && stats.today_total > 0 && (
                <div className="bg-stone-900 rounded-[2.5rem] p-8 text-white relative overflow-hidden shadow-2xl">
                    <div className="absolute top-0 right-0 w-[500px] h-[500px] bg-brand-900/40 rounded-full blur-[100px] -translate-y-1/2 translate-x-1/2 pointer-events-none"></div>

                    <div className="flex flex-col md:flex-row items-center justify-between gap-8 relative z-10">
                        <div className="flex-1">
                            <p className="text-sm text-brand-200 font-bold uppercase tracking-wider mb-2">Today's Progress</p>
                            <h2 className="text-5xl font-serif mb-2">{stats.today_completed} <span className="text-stone-500 text-3xl font-sans">/ {stats.today_total}</span></h2>
                            <p className="text-stone-400 text-sm max-w-xs">You're building momentum. Complete your habits to close the ring.</p>
                        </div>

                        <div className="relative w-32 h-32 flex-shrink-0">
                            <svg className="transform -rotate-90 w-full h-full" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.1)" strokeWidth="8" />
                                <circle
                                    cx="50"
                                    cy="50"
                                    r="40"
                                    fill="none"
                                    stroke="url(#gradient)"
                                    strokeWidth="8"
                                    strokeLinecap="round"
                                    strokeDasharray={`${completionPercent * 2.51} 251`}
                                    className="transition-all duration-1000 ease-out"
                                />
                                <defs>
                                    <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                        <stop offset="0%" stopColor="#d6d3d1" />
                                        <stop offset="100%" stopColor="#a8a29e" />
                                    </linearGradient>
                                </defs>
                            </svg>
                            <div className="absolute inset-0 flex items-center justify-center flex-col">
                                <span className="text-xl font-bold">{completionPercent}%</span>
                            </div>
                        </div>
                    </div>

                    {/* Mini Stats */}
                    <div className="grid grid-cols-3 gap-8 mt-8 pt-8 border-t border-white/10">
                        <div className="text-center md:text-left">
                            <p className="text-2xl font-serif text-brand-200">{stats.best_streak}</p>
                            <p className="text-[10px] text-stone-500 font-bold uppercase tracking-widest">Best Streak</p>
                        </div>
                        <div className="text-center md:text-left">
                            <p className="text-2xl font-serif text-white">{stats.active_habits}</p>
                            <p className="text-[10px] text-stone-500 font-bold uppercase tracking-widest">Active Habits</p>
                        </div>
                        <div className="text-center md:text-left">
                            <p className="text-2xl font-serif text-white">{stats.total_completions}</p>
                            <p className="text-[10px] text-stone-500 font-bold uppercase tracking-widest">Lifetime Wins</p>
                        </div>
                    </div>
                </div>
            )}

            {/* Tabs & AI Button */}
            <div className="flex flex-wrap items-center gap-4">
                <div className="flex gap-2 bg-stone-100 p-1.5 rounded-2xl w-fit">
                    {[
                        { id: 'today', label: 'Today', icon: 'fa-calendar-day' },
                        { id: 'all', label: 'All Habits', icon: 'fa-list' },
                        { id: 'add', label: 'Add New', icon: 'fa-plus' }
                    ].map(tab => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveView(tab.id as any)}
                            className={`px-4 py-2 rounded-lg text-sm font-bold transition-all flex items-center gap-2 ${activeView === tab.id
                                ? 'bg-white text-stone-900 shadow-sm'
                                : 'text-stone-500 hover:text-stone-700'
                                }`}
                        >
                            <i className={`fa-solid ${tab.icon}`}></i>
                            {tab.label}
                        </button>
                    ))}
                </div>

                <Button
                    onClick={handleGenerateRecommendations}
                    className="ml-auto bg-gradient-to-r from-brand-600 to-brand-500 hover:from-brand-500 hover:to-brand-400 border-none shadow-lg shadow-brand-500/20"
                >
                    <i className="fa-solid fa-wand-magic-sparkles mr-2"></i>
                    Smart Concierge
                </Button>
            </div>

            {/* Today View */}
            {activeView === 'today' && (
                <div className="space-y-3">
                    {todayHabits.length === 0 ? (
                        <div className="bg-stone-50 rounded-3xl p-12 text-center border-2 border-dashed border-stone-200">
                            <i className="fa-solid fa-seedling text-4xl text-stone-300 mb-4 block"></i>
                            <h3 className="font-serif text-xl text-stone-600 mb-2">No active habits</h3>
                            <p className="text-stone-400 mb-6">Small habits lead to big changes.</p>
                            <Button
                                onClick={() => setActiveView('add')}
                                className="!px-8"
                            >
                                <i className="fa-solid fa-plus mr-2"></i>
                                Add Your First Habit
                            </Button>
                        </div>
                    ) : (
                        todayHabits.map(habit => {
                            const colors = categoryColors[habit.category || 'other'];
                            return (
                                <button
                                    key={habit.id}
                                    onClick={() => handleToggleHabit(habit.id, habit.completed_today || false)}
                                    className={`w-full flex items-center gap-5 p-5 rounded-[1.5rem] transition-all border group ${habit.completed_today
                                        ? 'bg-stone-900 border-stone-900 shadow-xl'
                                        : `bg-white hover:border-brand-200 hover:shadow-lg ${colors.border}`
                                        }`}
                                >
                                    <div className={`w-14 h-14 rounded-2xl flex items-center justify-center transition-all text-xl ${habit.completed_today
                                        ? 'bg-emerald-500 text-white'
                                        : `${colors.bg} ${colors.text}`
                                        }`}>
                                        <i className={`fa-solid ${habit.completed_today ? 'fa-check' : (habit.icon || 'fa-circle')}`}></i>
                                    </div>

                                    <div className="flex-1 text-left">
                                        <h3 className={`font-bold text-lg ${habit.completed_today ? 'text-white line-through decoration-stone-500' : 'text-stone-900'}`}>
                                            {habit.title}
                                        </h3>
                                        <div className="flex items-center gap-4 text-xs mt-1">
                                            {habit.current_streak > 0 && (
                                                <span className={`${habit.completed_today ? 'text-orange-400' : 'text-orange-500'} font-bold flex items-center gap-1`}>
                                                    <i className="fa-solid fa-fire"></i>
                                                    {habit.current_streak}
                                                </span>
                                            )}
                                            <span className={`${habit.completed_today ? 'text-stone-500' : 'text-stone-400'} font-bold uppercase tracking-wider`}>{habit.category}</span>
                                        </div>
                                    </div>

                                    {habit.longest_streak > 0 && (
                                        <div className="text-center px-4 hidden sm:block">
                                            <p className={`text-[10px] font-bold uppercase tracking-widest ${habit.completed_today ? 'text-stone-600' : 'text-stone-400'}`}>Best</p>
                                            <p className={`font-serif text-xl ${habit.completed_today ? 'text-stone-400' : 'text-stone-600'}`}>{habit.longest_streak}</p>
                                        </div>
                                    )}
                                </button>
                            );
                        })
                    )}
                </div>
            )}

            {/* All Habits View */}
            {activeView === 'all' && (
                <div className="space-y-4">
                    {allHabits.length === 0 ? (
                        <div className="bg-stone-50 rounded-3xl p-8 text-center">
                            <p className="text-stone-500">No habits yet. Add some to get started!</p>
                        </div>
                    ) : (
                        allHabits.map(habit => {
                            const colors = categoryColors[habit.category || 'other'];
                            return (
                                <Card
                                    key={habit.id}
                                    className={`group hover:border-brand-200 transition-all`}
                                >
                                    <div className="flex items-center gap-5">
                                        <div className={`w-12 h-12 rounded-2xl flex items-center justify-center text-lg ${colors.bg} ${colors.text}`}>
                                            <i className={`fa-solid ${habit.icon || 'fa-circle'}`}></i>
                                        </div>

                                        <div className="flex-1">
                                            <h3 className="font-bold text-stone-900 text-lg">{habit.title}</h3>
                                            <div className="flex items-center gap-3 text-xs text-stone-400 mt-1 font-medium">
                                                <span className="capitalize">{habit.frequency}</span>
                                                <span className="w-1 h-1 rounded-full bg-stone-300"></span>
                                                <span>{habit.total_completions} completions</span>
                                                {!habit.is_active && (
                                                    <>
                                                        <span className="w-1 h-1 rounded-full bg-stone-300"></span>
                                                        <span className="text-stone-400">Paused</span>
                                                    </>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-6">
                                            <div className="text-center hidden sm:block">
                                                <p className="text-xl font-serif text-orange-500">{habit.current_streak}</p>
                                                <p className="text-[9px] text-stone-400 uppercase font-black tracking-widest">Streak</p>
                                            </div>
                                            <button
                                                onClick={() => handleDeleteHabit(habit.id)}
                                                className="w-10 h-10 rounded-xl border border-stone-200 text-stone-300 hover:text-rose-500 hover:border-rose-200 flex items-center justify-center transition-all"
                                            >
                                                <i className="fa-solid fa-trash-can"></i>
                                            </button>
                                        </div>
                                    </div>
                                </Card>
                            );
                        })
                    )}
                </div>
            )}

            {/* Add New View */}
            {activeView === 'add' && (
                <div className="grid gap-4 md:grid-cols-2">
                    {templates.map(template => {
                        const isAdded = allHabits.some(h => h.template_id === template.id);
                        const colors = categoryColors[template.category || 'other'];

                        return (
                            <Card
                                key={template.id}
                                className={`hover:shadow-lg transition-all hover:-translate-y-1 duration-300`}
                            >
                                <div className="flex items-start gap-4">
                                    <div className={`w-12 h-12 rounded-2xl flex items-center justify-center text-lg ${colors.bg} ${colors.text}`}>
                                        <i className={`fa-solid ${template.icon || 'fa-circle'}`}></i>
                                    </div>
                                    <div className="flex-1">
                                        <h3 className="font-bold text-stone-900">{template.title}</h3>
                                        <p className="text-xs text-stone-500 mt-2 leading-relaxed">{template.description}</p>
                                        <div className="mt-4">
                                            {isAdded ? (
                                                <div className="w-full py-2 bg-emerald-50 text-emerald-700 text-xs font-bold rounded-lg text-center">
                                                    <i className="fa-solid fa-check mr-2"></i> Tracking
                                                </div>
                                            ) : (
                                                <Button
                                                    variant="outline"
                                                    onClick={() => handleAddHabit(template)}
                                                    className="w-full text-xs !py-2"
                                                >
                                                    Start Tracking
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}


            {/* AI Recommendations Modal */}
            {
                showAIModal && (
                    <div className="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                        <div className="bg-white rounded-[2rem] w-full max-w-2xl max-h-[85vh] overflow-y-auto shadow-2xl animate-fade-in-up">
                            <div className="p-8">
                                <div className="flex items-center justify-between mb-8">
                                    <div>
                                        <h3 className="text-2xl font-black text-stone-900 font-serif">
                                            <i className="fa-solid fa-sparkles text-brand-500 mr-3"></i>
                                            Smart Recommendations
                                        </h3>
                                        {!generating && aiPhase && (
                                            <p className="text-stone-500 mt-2">
                                                Curated for your <span className="font-bold text-stone-800 capitalize">{aiPhase} Phase</span> & Active Goals
                                            </p>
                                        )}
                                    </div>
                                    <button
                                        onClick={() => setShowAIModal(false)}
                                        className="w-10 h-10 rounded-full bg-stone-100 text-stone-400 hover:bg-stone-200 hover:text-stone-600 transition-colors flex items-center justify-center"
                                    >
                                        <i className="fa-solid fa-xmark"></i>
                                    </button>
                                </div>

                                {generating ? (
                                    <div className="py-20 text-center">
                                        <div className="inline-block relative w-20 h-20 mb-6">
                                            <div className="absolute inset-0 rounded-full border-4 border-brand-100"></div>
                                            <div className="absolute inset-0 rounded-full border-4 border-brand-500 border-t-transparent animate-spin"></div>
                                            <i className="fa-solid fa-wand-magic-sparkles text-brand-500 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-2xl animate-pulse"></i>
                                        </div>
                                        <h4 className="text-lg font-bold text-stone-800 mb-2">Analyzing Your Bio-Data...</h4>
                                        <p className="text-stone-400 max-w-xs mx-auto">Syncing with your cycle phase and goal progress.</p>
                                    </div>
                                ) : aiError ? (
                                    <div className="py-12 text-center">
                                        <div className="w-16 h-16 bg-amber-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                            <i className="fa-solid fa-robot text-amber-500 text-2xl"></i>
                                        </div>
                                        <h4 className="text-lg font-bold text-stone-800 mb-2">Couldn't Generate Recommendations</h4>
                                        <p className="text-stone-400 max-w-xs mx-auto mb-6">{aiError}</p>
                                        <button
                                            onClick={handleGenerateRecommendations}
                                            className="px-6 py-2.5 bg-brand-600 text-white rounded-xl text-sm font-bold hover:bg-brand-700 transition-colors"
                                        >
                                            <i className="fa-solid fa-rotate-right mr-2"></i>Try Again
                                        </button>
                                    </div>
                                ) : (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {recommendations.map((rec, index) => (
                                            <button
                                                key={index}
                                                onClick={() => handleAddRecommendation(rec)}
                                                className="text-left group bg-stone-50 hover:bg-white hover:shadow-xl border border-stone-100 hover:border-brand-200 rounded-2xl p-6 transition-all duration-300"
                                            >
                                                <div className="flex items-start gap-4">
                                                    <div className="w-12 h-12 rounded-xl bg-white shadow-sm text-brand-500 flex items-center justify-center text-xl group-hover:scale-110 transition-transform">
                                                        <i className={`fa-solid ${rec.icon}`}></i>
                                                    </div>
                                                    <div>
                                                        <span className="inline-block px-2 py-1 rounded-md bg-stone-200 text-stone-600 text-[10px] font-bold uppercase tracking-wider mb-2">
                                                            {rec.category}
                                                        </span>
                                                        <h4 className="font-bold text-stone-900 group-hover:text-brand-600 transition-colors">
                                                            {rec.title}
                                                        </h4>
                                                        <p className="text-xs text-stone-500 mt-2 leading-relaxed">
                                                            {rec.description}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="mt-4 flex items-center justify-between border-t border-stone-200 pt-4">
                                                    <span className="text-xs font-bold text-stone-400 capitalize">
                                                        <i className="fa-solid fa-rotate mr-1"></i>
                                                        {rec.frequency}
                                                    </span>
                                                    <span className="text-xs font-bold text-brand-600 group-hover:underline">
                                                        Add Habit <i className="fa-solid fa-arrow-right ml-1"></i>
                                                    </span>
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}
        </div>
    );
};

export default HabitTracker;

