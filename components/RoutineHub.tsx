
import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import { User, CyclePhase } from '../types';
import Card from './ui/Card';
import Button from './ui/Button';

interface RoutineItem {
    id: string;
    title: string;
    description?: string;
    duration_minutes?: number;
    order_index: number;
    icon?: string;
}

interface Routine {
    id: string;
    title: string;
    description?: string;
    type: 'daily' | 'weekly' | 'one_off';
    cycle_phase?: string;
    category?: string;
    duration_minutes?: number;
    items: RoutineItem[];
    completed_today?: boolean;
    today_completion?: any;
    item_count?: number;
    is_active?: number;
    is_generated?: boolean;
    template_id?: string;
}

interface RoutineTemplate {
    id: string;
    title: string;
    description?: string;
    type: string;
    cycle_phase?: string;
    category?: string;
    duration_minutes?: number;
    items?: RoutineItem[];
}

interface RoutineStats {
    total_completions: number;
    current_streak: number;
    weekly_completions: number;
    average_completion_percent: number;
}

interface RoutineHubProps {
    user: User;
    currentCyclePhase?: CyclePhase;
}

const categoryIcons: Record<string, string> = {
    morning: 'fa-sun',
    evening: 'fa-moon',
    self_care: 'fa-spa',
    nutrition: 'fa-bowl-food',
    workout: 'fa-dumbbell',
    default: 'fa-list-check'
};

const phaseColors: Record<string, string> = {
    menstrual: 'rose',
    follicular: 'emerald',
    ovulation: 'amber',
    luteal: 'purple'
};

const RoutineHub: React.FC<RoutineHubProps> = ({ user, currentCyclePhase }) => {
    const [activeTab, setActiveTab] = useState<'today' | 'my_routines' | 'templates'>('today');
    const [todayRoutines, setTodayRoutines] = useState<Routine[]>([]);
    const [myRoutines, setMyRoutines] = useState<Routine[]>([]);
    const [templates, setTemplates] = useState<RoutineTemplate[]>([]);
    const [stats, setStats] = useState<RoutineStats | null>(null);
    const [loading, setLoading] = useState(true);
    const [activeRoutine, setActiveRoutine] = useState<Routine | null>(null);

    const [completedItems, setCompletedItems] = useState<string[]>([]);
    const [notification, setNotification] = useState<{ message: string, type: 'error' | 'success' | 'info' } | null>(null);

    // Auto-dismiss notification
    useEffect(() => {
        if (notification) {
            const timer = setTimeout(() => setNotification(null), 3000);
            return () => clearTimeout(timer);
        }
    }, [notification]);

    useEffect(() => {
        fetchData();
    }, [user.id]);

    const fetchData = async () => {
        setLoading(true);
        try {
            const [todayRes, routinesRes, templatesRes, statsRes] = await Promise.all([
                api.get<Routine[]>(`/routines/today/${user.id}${currentCyclePhase ? `?cycle_phase=${currentCyclePhase}` : ''}`),
                api.get<Routine[]>(`/routines/user/${user.id}`),
                api.get<RoutineTemplate[]>('/routines/templates'),
                api.get<RoutineStats>(`/routines/stats/${user.id}`)
            ]);
            setTodayRoutines(todayRes || []);
            setMyRoutines(routinesRes || []);
            setTemplates(templatesRes || []);
            setStats(statsRes);
        } catch (err) {
            console.error('Failed to fetch routines', err);
        } finally {
            setLoading(false);
        }
    };

    const handleAdoptTemplate = async (template: RoutineTemplate) => {
        try {
            await api.post(`/routines/user/${user.id}`, {
                template_id: template.id,
                title: template.title,
                type: template.type,
                cycle_phase: template.cycle_phase
            });
            fetchData();
        } catch (err) {
            console.error('Failed to adopt template', err);
        }
    };

    const handleStartRoutine = (routine: Routine) => {
        setActiveRoutine(routine);
        setCompletedItems([]);
    };

    const handleToggleItem = (itemId: string) => {
        setCompletedItems(prev =>
            prev.includes(itemId)
                ? prev.filter(id => id !== itemId)
                : [...prev, itemId]
        );
    };

    const handleCompleteRoutine = async () => {
        if (!activeRoutine) return;

        try {
            await api.post(`/routines/complete/${activeRoutine.id}`, {
                items_completed: completedItems
            });
            setActiveRoutine(null);
            setCompletedItems([]);
            fetchData();
        } catch (err) {
            console.error('Failed to complete routine', err);
        }
    };

    const handleDeleteRoutine = async (routineId: string) => {
        if (!confirm('Delete this routine?')) return;
        try {
            await api.delete(`/routines/${routineId}`);
            fetchData();
        } catch (err) {
            console.error('Failed to delete routine', err);
        }
    };

    const handleDeleteItem = async (itemId: string, e: React.MouseEvent) => {
        e.stopPropagation();
        if (!confirm('Remove this item?')) return;
        try {
            await api.delete(`/routines/items/${itemId}`);
            // Update local state
            if (activeRoutine) {
                setActiveRoutine({
                    ...activeRoutine,
                    items: activeRoutine.items.filter(i => i.id !== itemId)
                });
            }
        } catch (err) {
            console.error('Failed to delete item', err);
        }
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center h-64">
                <i className="fa-solid fa-circle-notch fa-spin text-3xl text-brand-500"></i>
            </div>
        );
    }

    // Active routine player view
    if (activeRoutine) {
        const progress = activeRoutine.items.length > 0
            ? Math.round((completedItems.length / activeRoutine.items.length) * 100)
            : 0;

        return (
            <div className="max-w-2xl mx-auto animate-in zoom-in-95 duration-500">
                <Card className="!p-0 overflow-hidden shadow-2xl border-none">
                    {/* Header */}
                    <div className="bg-stone-900 p-8 text-white relative overflow-hidden">
                        <div className="absolute top-0 right-0 w-64 h-64 bg-brand-900/20 rounded-full blur-3xl pointer-events-none -mt-10 -mr-10"></div>

                        <div className="flex items-center justify-between mb-6 relative z-10">
                            <button
                                onClick={() => setActiveRoutine(null)}
                                className="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center hover:bg-white/20 transition-colors backdrop-blur-md"
                            >
                                <i className="fa-solid fa-xmark"></i>
                            </button>
                            <span className="text-xs font-bold uppercase tracking-widest text-brand-200">{progress}% Complete</span>
                        </div>
                        <h2 className="text-3xl font-serif relative z-10">{activeRoutine.title}</h2>
                        <p className="text-stone-400 text-sm mt-2 relative z-10">
                            {completedItems.length} of {activeRoutine.items.length} tasks completed
                        </p>
                        {/* Progress bar */}
                        <div className="mt-8 h-1 bg-white/10 rounded-full overflow-hidden relative z-10">
                            <div
                                className="h-full bg-brand-400 transition-all duration-500"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                    </div>

                    {/* Items */}
                    <div className="p-8 space-y-4 bg-white">
                        {activeRoutine.items.map((item, index) => {
                            const isCompleted = completedItems.includes(item.id);
                            return (
                                <button
                                    key={item.id}
                                    onClick={() => handleToggleItem(item.id)}
                                    className={`w-full group flex items-center gap-5 p-5 rounded-2xl transition-all border ${isCompleted
                                        ? 'bg-emerald-50 border-emerald-200 shadow-inner'
                                        : 'bg-white border-stone-100 hover:border-brand-200 hover:shadow-md'
                                        }`}
                                >
                                    <div className={`w-12 h-12 rounded-xl flex items-center justify-center transition-all text-lg ${isCompleted
                                        ? 'bg-emerald-500 text-white shadow-lg shadow-emerald-200'
                                        : 'bg-stone-50 text-stone-300'
                                        }`}>
                                        {isCompleted ? (
                                            <i className="fa-solid fa-check"></i>
                                        ) : (
                                            <span className="font-serif font-bold">{index + 1}</span>
                                        )}
                                    </div>
                                    <div className="flex-1 text-left">
                                        <p className={`font-bold text-lg ${isCompleted ? 'text-emerald-800 line-through decoration-emerald-300' : 'text-stone-700'}`}>
                                            {item.title}
                                        </p>
                                        {item.duration_minutes && (
                                            <p className="text-xs text-stone-400 font-bold uppercase tracking-wider mt-1">{item.duration_minutes} min</p>
                                        )}
                                    </div>
                                    {item.icon && (
                                        <i className={`fa-solid ${item.icon} text-stone-200 text-xl`}></i>
                                    )}
                                    <button
                                        onClick={(e) => handleDeleteItem(item.id, e)}
                                        className="ml-4 w-8 h-8 rounded-full text-stone-300 hover:bg-rose-50 hover:text-rose-500 flex items-center justify-center transition-colors opacity-0 group-hover:opacity-100"
                                        title="Remove item"
                                    >
                                        <i className="fa-solid fa-xmark"></i>
                                    </button>
                                </button>
                            );
                        })}
                    </div>

                    {/* Complete button */}
                    <div className="p-8 pt-0 bg-white">
                        <Button
                            onClick={handleCompleteRoutine}
                            disabled={completedItems.length === 0}
                            className={`w-full py-5 text-lg shadow-xl ${progress === 100 ? 'bg-emerald-600 hover:bg-emerald-700 shadow-emerald-200' : 'bg-stone-900 hover:bg-black shadow-stone-200'}`}
                        >
                            {progress === 100 ? (
                                <>
                                    <i className="fa-solid fa-check mr-2"></i>
                                    Complete Routine
                                </>
                            ) : (
                                `Mark ${completedItems.length} items as done`
                            )}
                        </Button>
                    </div>
                </Card>
            </div>
        );
    }

    return (
        <div className="space-y-8">
            {/* Stats */}
            {stats && (
                <div className="grid grid-cols-2 gap-4 max-w-md">
                    <div className="bg-white p-4 rounded-2xl border border-stone-100 shadow-sm flex items-center gap-4">
                        <div className="w-12 h-12 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center text-xl">
                            <i className="fa-solid fa-fire"></i>
                        </div>
                        <div>
                            <p className="text-2xl font-serif text-stone-800">{stats.current_streak}</p>
                            <p className="text-[10px] font-bold text-stone-400 uppercase tracking-wider">Day Streak</p>
                        </div>
                    </div>
                    <div className="bg-white p-4 rounded-2xl border border-stone-100 shadow-sm flex items-center gap-4">
                        <div className="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                            <i className="fa-solid fa-check-double"></i>
                        </div>
                        <div>
                            <p className="text-2xl font-serif text-stone-800">{stats.weekly_completions}</p>
                            <p className="text-[10px] font-bold text-stone-400 uppercase tracking-wider">This Week</p>
                        </div>
                    </div>
                </div>
            )}

            {/* Tabs */}
            <div className="flex gap-2 bg-stone-100 p-1.5 rounded-2xl w-fit">
                {[
                    { id: 'today', label: "Today's", icon: 'fa-calendar-day' },
                    { id: 'my_routines', label: 'My Routines', icon: 'fa-list-check' },
                    { id: 'templates', label: 'Discover', icon: 'fa-compass' }
                ].map(tab => (
                    <button
                        key={tab.id}
                        onClick={() => setActiveTab(tab.id as any)}
                        className={`px-4 py-2 rounded-lg text-sm font-bold transition-all flex items-center gap-2 ${activeTab === tab.id
                            ? 'bg-white text-stone-900 shadow-sm'
                            : 'text-stone-500 hover:text-stone-700'
                            }`}
                    >
                        <i className={`fa-solid ${tab.icon}`}></i>
                        {tab.label}
                    </button>
                ))}
            </div>

            {/* Today's Routines */}
            {activeTab === 'today' && (
                <div className="space-y-6">
                    {/* AI Generator Card */}
                    <div className="bg-stone-900 rounded-[2rem] p-8 text-white shadow-xl relative overflow-hidden group">
                        <div className="absolute top-0 right-0 w-64 h-64 bg-brand-500/10 rounded-full blur-3xl -mr-16 -mt-16 transition-all group-hover:bg-brand-500/20 duration-1000"></div>
                        <div className="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                            <div>
                                <div className="inline-block px-3 py-1 rounded-full bg-white/10 border border-white/10 text-[10px] font-bold uppercase tracking-widest mb-3 text-brand-200">
                                    Smart Powered
                                </div>
                                <h3 className="text-2xl font-serif mb-2">Daily Smart Routine</h3>
                                <p className="text-stone-400 text-sm max-w-sm leading-relaxed">
                                    Optimized based on your current {currentCyclePhase} phase, mood, and sleep data.
                                </p>
                            </div>
                            <Button
                                onClick={async () => {
                                    try {
                                        const res = await api.post<any>(`/routines/generate/${user.id}`, {});
                                        if (res.requires_premium) {
                                            setNotification({ message: "Premium Feature: Join a Challenge to unlock Smart routines.", type: 'info' });
                                        } else {
                                            fetchData();
                                            setNotification({ message: "Routine generated successfully!", type: 'success' });
                                        }
                                    } catch (err) {
                                        console.error(err);
                                        setNotification({ message: "Failed to generate routine. Ensure you are enrolled in a challenge.", type: 'error' });
                                    }
                                }}
                                className="!bg-white !text-stone-900 hover:!bg-stone-200 whitespace-nowrap shadow-lg shadow-white/10"
                            >
                                <i className="fa-solid fa-wand-magic-sparkles mr-2 text-brand-600"></i>
                                Generate New
                            </Button>

                            {/* Regenerate Button - Only if AI routine exists */}
                            {todayRoutines.some(r => r.is_generated) && (
                                <Button
                                    onClick={async () => {
                                        if (!confirm("Regenerate routine? This will replace your current Smart routine.")) return;

                                        const aiRoutine = todayRoutines.find(r => r.is_generated);
                                        if (aiRoutine) {
                                            try {
                                                await api.delete(`/routines/${aiRoutine.id}`);
                                            } catch (e) { console.error("Failed to delete old routine"); }
                                        }

                                        try {
                                            const res = await api.post<any>(`/routines/generate/${user.id}`, {});
                                            if (res.requires_premium) {
                                                setNotification({ message: "Premium Feature: Join a Challenge to unlock Smart routines.", type: 'info' });
                                            } else {
                                                fetchData();
                                                setNotification({ message: "Routine regenerated!", type: 'success' });
                                            }
                                        } catch (err) {
                                            setNotification({ message: "Regeneration failed.", type: 'error' });
                                        }
                                    }}
                                    className="!bg-white/10 !text-white hover:!bg-white/20 whitespace-nowrap border border-white/20"
                                >
                                    <i className="fa-solid fa-rotate mr-2"></i>
                                    Regenerate
                                </Button>
                            )}
                        </div>
                    </div>

                    {todayRoutines.length === 0 ? (
                        <div className="bg-stone-50 rounded-3xl p-12 text-center border-2 border-dashed border-stone-200">
                            <i className="fa-solid fa-mug-hot text-4xl text-stone-300 mb-4 block"></i>
                            <h3 className="font-serif text-xl text-stone-600 mb-2">Start your day right</h3>
                            <p className="text-stone-400 mb-6">
                                Use the Smart Generator above or browse templates.
                            </p>
                            <Button variant="outline" onClick={() => setActiveTab('templates')}>Browse Templates</Button>
                        </div>
                    ) : (
                        <div className="grid gap-4">
                            {todayRoutines.map(routine => (
                                <Card
                                    key={routine.id}
                                    className={`!p-6 transition-all duration-300 ${routine.completed_today
                                        ? '!bg-emerald-50/50 border-emerald-100'
                                        : 'hover:border-brand-200 hover:shadow-lg'
                                        }`}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-6">
                                            <div className={`w-16 h-16 rounded-2xl flex items-center justify-center text-2xl ${routine.completed_today
                                                ? 'bg-emerald-500 text-white shadow-lg shadow-emerald-200'
                                                : 'bg-stone-100 text-stone-600'
                                                }`}>
                                                <i className={`fa-solid ${routine.completed_today ? 'fa-check' : categoryIcons[routine.category || 'default']}`}></i>
                                            </div>
                                            <div>
                                                <h3 className={`font-bold text-lg ${routine.completed_today ? 'text-emerald-900' : 'text-stone-900'}`}>
                                                    {routine.title}
                                                </h3>
                                                <div className="flex items-center gap-3 text-xs text-stone-400 mt-2 font-medium">
                                                    <span>{routine.items.length} tasks</span>
                                                    {routine.duration_minutes && (
                                                        <>
                                                            <span className="w-1 h-1 rounded-full bg-stone-300"></span>
                                                            <span>{routine.duration_minutes} min</span>
                                                        </>
                                                    )}
                                                    {routine.cycle_phase && (
                                                        <>
                                                            <span className="w-1 h-1 rounded-full bg-stone-300"></span>
                                                            <span className={`text-${phaseColors[routine.cycle_phase]}-600 capitalize bg-${phaseColors[routine.cycle_phase]}-50 px-2 py-0.5 rounded-md`}>
                                                                {routine.cycle_phase}
                                                            </span>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        {routine.completed_today ? (
                                            <span className="px-5 py-2 bg-emerald-100 text-emerald-700 text-xs font-black uppercase tracking-widest rounded-xl">
                                                Done
                                            </span>
                                        ) : (
                                            <Button
                                                onClick={() => handleStartRoutine(routine)}
                                                className="!px-8 shadow-lg shadow-brand-500/20"
                                            >
                                                Start
                                            </Button>
                                        )}
                                    </div>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>
            )
            }

            {/* My Routines */}
            {
                activeTab === 'my_routines' && (
                    <div className="space-y-4">
                        {myRoutines.length === 0 ? (
                            <div className="bg-stone-50 rounded-3xl p-12 text-center">
                                <div className="w-16 h-16 bg-white rounded-2xl mx-auto flex items-center justify-center mb-6 shadow-sm text-stone-300">
                                    <i className="fa-solid fa-list-check text-2xl"></i>
                                </div>
                                <h3 className="font-serif text-xl text-stone-600 mb-2">No routines yet</h3>
                                <p className="text-stone-400 mb-6">
                                    Create custom routines or adopt from templates
                                </p>
                                <Button onClick={() => setActiveTab('templates')}>
                                    Discover Templates
                                </Button>
                            </div>
                        ) : (
                            myRoutines.map(routine => (
                                <Card key={routine.id} className="group hover:border-brand-200 transition-all">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-5">
                                            <div className="w-14 h-14 rounded-2xl bg-stone-50 flex items-center justify-center text-stone-500 text-xl group-hover:bg-brand-50 group-hover:text-brand-600 transition-colors">
                                                <i className={`fa-solid ${categoryIcons[routine.category || 'default']}`}></i>
                                            </div>
                                            <div>
                                                <h3 className="font-bold text-stone-900 text-lg">{routine.title}</h3>
                                                <div className="flex items-center gap-3 text-xs text-stone-400 mt-1">
                                                    <span className="capitalize bg-stone-100 px-2 py-0.5 rounded-md">{routine.type}</span>
                                                    <span className="w-1 h-1 rounded-full bg-stone-300"></span>
                                                    <span>{routine.items?.length || routine.item_count || 0} tasks</span>
                                                    <span className="w-1 h-1 rounded-full bg-stone-300"></span>
                                                    {routine.is_active ? (
                                                        <span className="text-emerald-600 font-bold">Active</span>
                                                    ) : (
                                                        <span className="text-stone-400">Paused</span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="secondary"
                                                onClick={() => handleStartRoutine(routine)}
                                                className="!py-2.5 !px-4 text-xs bg-stone-100 hover:bg-stone-200"
                                            >
                                                <i className="fa-solid fa-play mr-2"></i> Run
                                            </Button>
                                            <button
                                                onClick={() => handleDeleteRoutine(routine.id)}
                                                className="w-10 h-10 rounded-xl border border-stone-200 text-stone-300 hover:text-rose-500 hover:border-rose-200 flex items-center justify-center transition-all"
                                            >
                                                <i className="fa-solid fa-trash-can"></i>
                                            </button>
                                        </div>
                                    </div>
                                </Card>
                            ))
                        )}
                    </div>
                )
            }

            {/* Templates */}
            {
                activeTab === 'templates' && (
                    <div className="grid gap-4 md:grid-cols-2">
                        {templates.map(template => {
                            const isAdopted = myRoutines.some(r => r.template_id === template.id);
                            return (
                                <Card key={template.id} className="hover:shadow-lg transition-all hover:-translate-y-1 duration-300">
                                    <div className="flex items-start gap-4">
                                        <div className={`w-12 h-12 rounded-2xl flex items-center justify-center text-lg ${template.cycle_phase
                                            ? `bg-${phaseColors[template.cycle_phase]}-50 text-${phaseColors[template.cycle_phase]}-600`
                                            : 'bg-brand-50 text-brand-600'
                                            }`}>
                                            <i className={`fa-solid ${categoryIcons[template.category || 'default']}`}></i>
                                        </div>
                                        <div className="flex-1">
                                            <h3 className="font-bold text-stone-900">{template.title}</h3>
                                            <p className="text-xs text-stone-500 mt-2 leading-relaxed line-clamp-2">{template.description}</p>
                                            <div className="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wider text-stone-400 mt-4">
                                                {template.cycle_phase && (
                                                    <span className={`text-${phaseColors[template.cycle_phase]}-600 bg-${phaseColors[template.cycle_phase]}-50 px-2 py-1 rounded-lg`}>
                                                        {template.cycle_phase}
                                                    </span>
                                                )}
                                                {template.duration_minutes && (
                                                    <span>{template.duration_minutes} min</span>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-6 pt-4 border-t border-stone-100">
                                        {isAdopted ? (
                                            <div className="w-full py-3 bg-emerald-50 text-emerald-700 text-sm font-bold rounded-xl text-center">
                                                <i className="fa-solid fa-check mr-2"></i> Added
                                            </div>
                                        ) : (
                                            <Button
                                                variant="outline"
                                                onClick={() => handleAdoptTemplate(template)}
                                                className="w-full text-xs"
                                            >
                                                Adopt Routine
                                            </Button>
                                        )}
                                    </div>
                                </Card>
                            );
                        })}
                    </div>
                )
            }
            {/* Notification Toast */}
            {
                notification && (
                    <div className={`fixed top-4 right-4 z-[100] animate-in slide-in-from-top-2 fade-in duration-300 max-w-sm w-full p-4 rounded-xl shadow-2xl border flex items-center gap-3 ${notification.type === 'error' ? 'bg-red-50 border-red-100 text-red-800' :
                        notification.type === 'success' ? 'bg-emerald-50 border-emerald-100 text-emerald-800' :
                            'bg-indigo-50 border-indigo-100 text-indigo-800'
                        }`}>
                        <div className={`w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ${notification.type === 'error' ? 'bg-red-100 text-red-500' :
                            notification.type === 'success' ? 'bg-emerald-100 text-emerald-500' :
                                'bg-indigo-100 text-indigo-500'
                            }`}>
                            <i className={`fa-solid ${notification.type === 'error' ? 'fa-circle-exclamation' :
                                notification.type === 'success' ? 'fa-check' :
                                    'fa-circle-info'
                                }`}></i>
                        </div>
                        <p className="text-sm font-medium">{notification.message}</p>
                    </div>
                )
            }
        </div >
    );
};

export default RoutineHub;

