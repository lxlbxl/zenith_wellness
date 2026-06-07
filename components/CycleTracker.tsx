import React, { useState, useEffect, useCallback, useRef } from 'react';
import { CycleLog, CycleStats, CyclePhase, Symptom, FlowIntensity } from '../types';
import { db } from '../services/db';
import { api } from '../services/api';
import { ResponsiveContainer, AreaChart, Area, XAxis, YAxis, Tooltip } from 'recharts';

interface CycleTrackerProps {
    userId: string;
}

const SYMPTOMS: { id: Symptom; label: string; icon: string }[] = [
    { id: 'cramps', label: 'Cramps', icon: 'fa-hand-holding-heart' },
    { id: 'headache', label: 'Headache', icon: 'fa-head-side-virus' },
    { id: 'fatigue', label: 'Fatigue', icon: 'fa-battery-quarter' },
    { id: 'mood_swings', label: 'Mood Swings', icon: 'fa-face-smile-beam' },
    { id: 'bloating', label: 'Bloating', icon: 'fa-circle' },
    { id: 'acne', label: 'Acne', icon: 'fa-face-frown' },
    { id: 'cravings', label: 'Cravings', icon: 'fa-cookie-bite' },
    { id: 'breast_tenderness', label: 'Breast Tenderness', icon: 'fa-heart' },
    { id: 'back_pain', label: 'Back Pain', icon: 'fa-person-walking' },
    { id: 'nausea', label: 'Nausea', icon: 'fa-face-dizzy' },
];

const FLOW_OPTIONS: { id: FlowIntensity; label: string; color: string }[] = [
    { id: 'spotting', label: 'Spotting', color: 'bg-rose-200' },
    { id: 'light', label: 'Light', color: 'bg-rose-300' },
    { id: 'medium', label: 'Medium', color: 'bg-rose-400' },
    { id: 'heavy', label: 'Heavy', color: 'bg-rose-600' },
];

const PHASE_INFO: Record<CyclePhase, { label: string; color: string; icon: string; description: string }> = {
    menstrual: {
        label: 'Menstrual',
        color: 'bg-rose-500',
        icon: 'fa-droplet',
        description: 'Period days. Rest and nourish your body.'
    },
    follicular: {
        label: 'Follicular',
        color: 'bg-amber-500',
        icon: 'fa-seedling',
        description: 'Energy rises. Great for new projects.'
    },
    ovulation: {
        label: 'Ovulation',
        color: 'bg-emerald-500',
        icon: 'fa-sun',
        description: 'Peak fertility. High energy and confidence.'
    },
    luteal: {
        label: 'Luteal',
        color: 'bg-indigo-500',
        icon: 'fa-moon',
        description: 'Wind down phase. Focus on self-care.'
    },
};

const CycleTracker: React.FC<CycleTrackerProps> = ({ userId }) => {
    const [cycleLogs, setCycleLogs] = useState<CycleLog[]>([]);
    const [cycleStats, setCycleStats] = useState<CycleStats | null>(null);
    const [showLogModal, setShowLogModal] = useState(false);
    const [selectedSymptoms, setSelectedSymptoms] = useState<Symptom[]>([]);
    const [selectedFlow, setSelectedFlow] = useState<FlowIntensity>('medium');
    const [periodStartDate, setPeriodStartDate] = useState(new Date().toISOString().split('T')[0]);
    const [notes, setNotes] = useState('');
    const [activeView, setActiveView] = useState<'overview' | 'history' | 'insights'>('overview');
    const symptomSaveTimeout = useRef<NodeJS.Timeout | null>(null);

    useEffect(() => {
        loadCycleData();
        loadTodaySymptoms();
    }, [userId]);

    // Load today's symptoms from API
    const loadTodaySymptoms = async () => {
        try {
            const symptoms = await api.get<Symptom[]>(`/users/${userId}/symptoms`);
            if (Array.isArray(symptoms) && symptoms.length > 0) {
                setSelectedSymptoms(symptoms);
            }
        } catch (err) {
            console.log('No symptoms saved for today');
        }
    };

    // Debounced save symptoms to API
    const saveSymptoms = useCallback(async (symptoms: Symptom[]) => {
        try {
            await api.post(`/users/${userId}/symptoms`, { symptoms });
        } catch (err) {
            console.error('Failed to save symptoms:', err);
        }
    }, [userId]);

    const calculateCycleStats = (logs: CycleLog[]) => {
        if (logs.length === 0) return;

        const sortedLogs = [...logs].sort((a, b) =>
            new Date(b.startDate).getTime() - new Date(a.startDate).getTime()
        );

        const lastPeriod = sortedLogs[0];
        const cycleLengths = sortedLogs.slice(0, -1).map((log, i) => {
            const nextLog = sortedLogs[i + 1];
            return Math.round((new Date(log.startDate).getTime() - new Date(nextLog.startDate).getTime()) / (1000 * 60 * 60 * 24));
        }).filter(len => len > 0 && len < 60);

        const periodLengths = sortedLogs.filter(l => l.endDate).map(log => {
            return Math.round((new Date(log.endDate!).getTime() - new Date(log.startDate).getTime()) / (1000 * 60 * 60 * 24)) + 1;
        }).filter(len => len > 0 && len < 15);

        const avgCycle = cycleLengths.length > 0
            ? Math.round(cycleLengths.reduce((a, b) => a + b, 0) / cycleLengths.length)
            : 28;
        const avgPeriod = periodLengths.length > 0
            ? Math.round(periodLengths.reduce((a, b) => a + b, 0) / periodLengths.length)
            : 5;

        const lastPeriodDate = new Date(lastPeriod.startDate);
        const today = new Date();
        const dayOfCycle = Math.floor((today.getTime() - lastPeriodDate.getTime()) / (1000 * 60 * 60 * 24)) + 1;

        const nextPeriodDate = new Date(lastPeriodDate);
        nextPeriodDate.setDate(nextPeriodDate.getDate() + avgCycle);

        const ovulationDay = avgCycle - 14;
        const ovulationDate = new Date(lastPeriodDate);
        ovulationDate.setDate(ovulationDate.getDate() + ovulationDay);

        const fertileStart = new Date(ovulationDate);
        fertileStart.setDate(fertileStart.getDate() - 5);
        const fertileEnd = new Date(ovulationDate);
        fertileEnd.setDate(fertileEnd.getDate() + 1);

        let phase: CyclePhase = 'follicular';
        if (dayOfCycle <= avgPeriod) {
            phase = 'menstrual';
        } else if (dayOfCycle >= ovulationDay - 2 && dayOfCycle <= ovulationDay + 1) {
            phase = 'ovulation';
        } else if (dayOfCycle > ovulationDay) {
            phase = 'luteal';
        }

        setCycleStats({
            averageCycleLength: avgCycle,
            averagePeriodLength: avgPeriod,
            currentPhase: phase,
            dayOfCycle: dayOfCycle > avgCycle ? 1 : dayOfCycle,
            nextPeriodDate: nextPeriodDate.toISOString(),
            fertileWindowStart: fertileStart.toISOString(),
            fertileWindowEnd: fertileEnd.toISOString(),
            ovulationDate: ovulationDate.toISOString(),
        });
    };

    const loadCycleData = async () => {
        const logs = await db.getCycleLogs(userId);
        setCycleLogs(logs);
        if (logs.length > 0) {
            calculateCycleStats(logs);
        }
    };

    // ... calculateCycleStats (no change needed as it uses in-memory logs)

    const handleLogPeriod = async () => {
        const newLog: CycleLog = {
            id: Date.now().toString(),
            startDate: periodStartDate,
            symptoms: selectedSymptoms,
            flowIntensity: selectedFlow,
            notes: notes || undefined,
        };

        await db.saveCycleLog(userId, newLog);
        loadCycleData();
        resetForm();
        setShowLogModal(false);
    };

    const handleEndPeriod = async (logId: string) => {
        const today = new Date().toISOString().split('T')[0];
        await db.updateCycleLogEndDate(userId, logId, today);
        loadCycleData();
    };

    const resetForm = () => {
        setSelectedSymptoms([]);
        setSelectedFlow('medium');
        setPeriodStartDate(new Date().toISOString().split('T')[0]);
        setNotes('');
    };

    const toggleSymptom = (symptom: Symptom) => {
        setSelectedSymptoms(prev => {
            const newSymptoms = prev.includes(symptom)
                ? prev.filter(s => s !== symptom)
                : [...prev, symptom];

            // Debounced save to API (1 second delay)
            if (symptomSaveTimeout.current) {
                clearTimeout(symptomSaveTimeout.current);
            }
            symptomSaveTimeout.current = setTimeout(() => {
                saveSymptoms(newSymptoms);
            }, 1000);

            return newSymptoms;
        });
    };

    const getDaysUntilPeriod = () => {
        if (!cycleStats) return null;
        const today = new Date();
        const nextPeriod = new Date(cycleStats.nextPeriodDate);
        const diff = Math.floor((nextPeriod.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
        return diff < 0 ? 0 : diff;
    };

    const isInFertileWindow = () => {
        if (!cycleStats?.fertileWindowStart || !cycleStats?.fertileWindowEnd) return false;
        const today = new Date();
        return today >= new Date(cycleStats.fertileWindowStart) && today <= new Date(cycleStats.fertileWindowEnd);
    };

    const getPhaseProgress = () => {
        if (!cycleStats) return 0;
        return (cycleStats.dayOfCycle / cycleStats.averageCycleLength) * 100;
    };

    const chartData = cycleLogs.slice(0, 6).reverse().map((log, i) => ({
        cycle: `Cycle ${i + 1}`,
        length: log.cycleLength || 28,
        period: log.periodLength || 5,
    }));

    const currentLog = cycleLogs.find(log => !log.endDate);

    return (
        <div className="space-y-8 animate-in fade-in duration-700 pb-12">
            {/* Header */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 className="text-3xl font-black text-slate-900 tracking-tight">Cycle Tracker</h2>
                    <p className="text-slate-500 mt-1">
                        Track your menstrual cycle, symptoms, and fertility window.
                    </p>
                </div>
                <button
                    onClick={() => setShowLogModal(true)}
                    className="bg-rose-500 text-white px-6 py-3 rounded-2xl font-black shadow-lg shadow-rose-100 hover:bg-rose-600 transition-all flex items-center gap-2"
                >
                    <i className="fa-solid fa-plus"></i>
                    {currentLog ? 'Log Today' : 'Start Period'}
                </button>
            </div>

            {/* Tab Navigation */}
            <div className="flex gap-2 bg-white p-2 rounded-2xl border border-slate-100 shadow-sm w-fit">
                {[
                    { id: 'overview', label: 'Overview', icon: 'fa-chart-pie' },
                    { id: 'history', label: 'History', icon: 'fa-calendar' },
                    { id: 'insights', label: 'Insights', icon: 'fa-lightbulb' },
                ].map(tab => (
                    <button
                        key={tab.id}
                        onClick={() => setActiveView(tab.id as any)}
                        className={`flex items-center gap-2 px-5 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all ${activeView === tab.id
                            ? 'bg-rose-500 text-white shadow-lg'
                            : 'text-slate-400 hover:text-slate-600 hover:bg-slate-50'
                            }`}
                    >
                        <i className={`fa-solid ${tab.icon} text-xs`}></i>
                        {tab.label}
                    </button>
                ))}
            </div>

            {activeView === 'overview' && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    {/* Main Cycle Visualization */}
                    <div className="lg:col-span-2 bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        {cycleStats ? (
                            <div className="space-y-8">
                                {/* Current Phase Header */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-4">
                                        <div className={`w-16 h-16 ${PHASE_INFO[cycleStats.currentPhase].color} rounded-2xl flex items-center justify-center text-white text-2xl shadow-lg`}>
                                            <i className={`fa-solid ${PHASE_INFO[cycleStats.currentPhase].icon}`}></i>
                                        </div>
                                        <div>
                                            <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Current Phase</p>
                                            <h3 className="text-2xl font-black text-slate-900">{PHASE_INFO[cycleStats.currentPhase].label}</h3>
                                            <p className="text-sm text-slate-500">{PHASE_INFO[cycleStats.currentPhase].description}</p>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-4xl font-black text-slate-900">Day {cycleStats.dayOfCycle}</p>
                                        <p className="text-sm text-slate-400">of {cycleStats.averageCycleLength}</p>
                                    </div>
                                </div>

                                {/* Cycle Progress Bar */}
                                <div className="space-y-3">
                                    <div className="h-4 w-full bg-slate-100 rounded-full overflow-hidden flex">
                                        <div className="bg-rose-500 h-full" style={{ width: `${(cycleStats.averagePeriodLength / cycleStats.averageCycleLength) * 100}%` }}></div>
                                        <div className="bg-amber-400 h-full" style={{ width: `${((14 - cycleStats.averagePeriodLength - 5) / cycleStats.averageCycleLength) * 100}%` }}></div>
                                        <div className="bg-emerald-400 h-full" style={{ width: `${(7 / cycleStats.averageCycleLength) * 100}%` }}></div>
                                        <div className="bg-indigo-400 h-full flex-1"></div>
                                    </div>
                                    <div className="h-1 w-full relative">
                                        <div
                                            className="absolute w-4 h-4 bg-slate-900 rounded-full -top-1.5 transform -translate-x-1/2 shadow-lg"
                                            style={{ left: `${getPhaseProgress()}%` }}
                                        ></div>
                                    </div>
                                    <div className="flex justify-between text-[8px] font-black uppercase tracking-widest text-slate-400">
                                        <span>Menstrual</span>
                                        <span>Follicular</span>
                                        <span>Ovulation</span>
                                        <span>Luteal</span>
                                    </div>
                                </div>

                                {/* Period Prediction */}
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="bg-rose-50 p-6 rounded-2xl border border-rose-100">
                                        <p className="text-[10px] font-black text-rose-400 uppercase tracking-widest mb-2">Next Period</p>
                                        <p className="text-3xl font-black text-rose-600">{getDaysUntilPeriod()}</p>
                                        <p className="text-sm text-rose-400">days away</p>
                                    </div>
                                    <div className={`p-6 rounded-2xl border ${isInFertileWindow() ? 'bg-emerald-50 border-emerald-100' : 'bg-slate-50 border-slate-100'}`}>
                                        <p className={`text-[10px] font-black uppercase tracking-widest mb-2 ${isInFertileWindow() ? 'text-emerald-400' : 'text-slate-400'}`}>Fertile Window</p>
                                        <p className={`text-lg font-black ${isInFertileWindow() ? 'text-emerald-600' : 'text-slate-600'}`}>
                                            {isInFertileWindow() ? 'Now Active' : 'Not Active'}
                                        </p>
                                        {cycleStats.ovulationDate && (
                                            <p className="text-sm text-slate-400">
                                                Ovulation: {new Date(cycleStats.ovulationDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div className="text-center py-16">
                                <div className="w-20 h-20 bg-rose-50 rounded-full flex items-center justify-center mx-auto mb-6">
                                    <i className="fa-solid fa-droplet text-rose-300 text-3xl"></i>
                                </div>
                                <h3 className="text-xl font-bold text-slate-800 mb-2">Start Tracking</h3>
                                <p className="text-slate-500 max-w-sm mx-auto mb-6">
                                    Log your first period to start getting cycle predictions and fertility insights.
                                </p>
                                <button
                                    onClick={() => setShowLogModal(true)}
                                    className="bg-rose-500 text-white px-8 py-3 rounded-2xl font-black shadow-lg"
                                >
                                    Log First Period
                                </button>
                            </div>
                        )}
                    </div>

                    {/* Side Stats */}
                    <div className="space-y-6">
                        {/* Current Period Status */}
                        {currentLog && (
                            <div className="bg-rose-500 p-6 rounded-[2rem] text-white relative overflow-hidden">
                                <div className="relative z-10">
                                    <div className="flex items-center gap-2 mb-4">
                                        <div className="w-3 h-3 bg-white rounded-full animate-pulse"></div>
                                        <p className="text-[10px] font-black uppercase tracking-widest opacity-80">Period Active</p>
                                    </div>
                                    <p className="text-2xl font-black mb-2">
                                        Day {Math.floor((new Date().getTime() - new Date(currentLog.startDate).getTime()) / (1000 * 60 * 60 * 24)) + 1}
                                    </p>
                                    <button
                                        onClick={() => handleEndPeriod(currentLog.id)}
                                        className="mt-4 w-full py-3 bg-white/20 rounded-xl font-bold text-sm hover:bg-white/30 transition-all"
                                    >
                                        End Period
                                    </button>
                                </div>
                                <i className="fa-solid fa-droplet absolute -right-6 -bottom-6 text-[8rem] text-white/10"></i>
                            </div>
                        )}

                        {/* Cycle Stats */}
                        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                            <h4 className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Your Averages</h4>
                            <div className="space-y-4">
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-slate-600">Cycle Length</span>
                                    <span className="text-lg font-black text-slate-900">{cycleStats?.averageCycleLength || 28} days</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-slate-600">Period Length</span>
                                    <span className="text-lg font-black text-slate-900">{cycleStats?.averagePeriodLength || 5} days</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-slate-600">Cycles Logged</span>
                                    <span className="text-lg font-black text-slate-900">{cycleLogs.length}</span>
                                </div>
                            </div>
                        </div>

                        {/* Quick Log Symptoms */}
                        <div className="bg-slate-900 p-6 rounded-[2rem] text-white">
                            <h4 className="text-[10px] font-black uppercase tracking-widest text-indigo-400 mb-4">Quick Symptom Log</h4>
                            <div className="grid grid-cols-3 gap-2">
                                {SYMPTOMS.slice(0, 6).map(symptom => (
                                    <button
                                        key={symptom.id}
                                        onClick={() => toggleSymptom(symptom.id)}
                                        className={`p-3 rounded-xl transition-all ${selectedSymptoms.includes(symptom.id)
                                            ? 'bg-rose-500 text-white'
                                            : 'bg-white/10 text-slate-400 hover:bg-white/20'
                                            }`}
                                    >
                                        <i className={`fa-solid ${symptom.icon} text-lg`}></i>
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {activeView === 'history' && (
                <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 className="text-xl font-black text-slate-900 mb-6">Cycle History</h3>
                    {cycleLogs.length === 0 ? (
                        <p className="text-slate-500 text-center py-8">No cycles logged yet.</p>
                    ) : (
                        <div className="space-y-4">
                            {cycleLogs.map(log => (
                                <div key={log.id} className="p-5 bg-slate-50 rounded-2xl border border-slate-100 flex items-center justify-between">
                                    <div className="flex items-center gap-4">
                                        <div className="w-12 h-12 bg-rose-100 rounded-xl flex items-center justify-center">
                                            <i className="fa-solid fa-droplet text-rose-500"></i>
                                        </div>
                                        <div>
                                            <p className="font-bold text-slate-800">
                                                {new Date(log.startDate).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                                            </p>
                                            <p className="text-sm text-slate-500">
                                                {log.endDate
                                                    ? `${Math.floor((new Date(log.endDate).getTime() - new Date(log.startDate).getTime()) / (1000 * 60 * 60 * 24)) + 1} days`
                                                    : 'Ongoing'
                                                }
                                                {log.flowIntensity && ` • ${log.flowIntensity} flow`}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {log.symptoms.slice(0, 3).map(s => (
                                            <span key={s} className="w-8 h-8 bg-slate-200 rounded-lg flex items-center justify-center text-slate-500 text-xs">
                                                <i className={`fa-solid ${SYMPTOMS.find(sym => sym.id === s)?.icon}`}></i>
                                            </span>
                                        ))}
                                        {log.symptoms.length > 3 && (
                                            <span className="text-xs text-slate-400 font-bold">+{log.symptoms.length - 3}</span>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {activeView === 'insights' && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <h3 className="text-xl font-black text-slate-900 mb-6">Cycle Length Trends</h3>
                        {chartData.length > 1 ? (
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart data={chartData}>
                                        <defs>
                                            <linearGradient id="colorCycle" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#f43f5e" stopOpacity={0.2} />
                                                <stop offset="95%" stopColor="#f43f5e" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <XAxis dataKey="cycle" axisLine={false} tickLine={false} tick={{ fill: '#94a3b8', fontSize: 10 }} />
                                        <YAxis axisLine={false} tickLine={false} tick={{ fill: '#94a3b8', fontSize: 10 }} domain={[20, 35]} />
                                        <Tooltip />
                                        <Area type="monotone" dataKey="length" stroke="#f43f5e" strokeWidth={3} fillOpacity={1} fill="url(#colorCycle)" />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        ) : (
                            <p className="text-slate-500 text-center py-8">Log more cycles to see trends.</p>
                        )}
                    </div>

                    <div className="bg-slate-900 p-8 rounded-[2.5rem] text-white">
                        <h3 className="text-xl font-black mb-6">Intelligent Wellness Tips</h3>
                        <div className="space-y-4">
                            {[
                                { phase: 'menstrual', tip: 'Focus on iron-rich foods and gentle movement during your period.' },
                                { phase: 'follicular', tip: 'Great time for high-intensity workouts and starting new projects.' },
                                { phase: 'ovulation', tip: 'Your communication skills peak now. Schedule important meetings.' },
                                { phase: 'luteal', tip: 'Prioritize sleep and reduce caffeine to manage PMS symptoms.' },
                            ].map(item => (
                                <div key={item.phase} className={`p-4 rounded-2xl ${cycleStats?.currentPhase === item.phase ? 'bg-rose-500/20 border border-rose-500/30' : 'bg-white/5'}`}>
                                    <p className="text-[10px] font-black uppercase tracking-widest text-rose-400 mb-1">{item.phase}</p>
                                    <p className="text-sm text-slate-300">{item.tip}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {/* Log Period Modal */}
            {showLogModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-in fade-in duration-300">
                    <div className="bg-white w-full max-w-lg rounded-[3rem] shadow-2xl border border-white/20 overflow-hidden max-h-[90vh] overflow-y-auto">
                        <div className="p-8 border-b border-slate-50">
                            <div className="flex items-center justify-between">
                                <h2 className="text-2xl font-black text-slate-900">Log Period</h2>
                                <button
                                    onClick={() => { resetForm(); setShowLogModal(false); }}
                                    className="w-10 h-10 bg-slate-50 rounded-full flex items-center justify-center text-slate-400 hover:text-slate-900"
                                >
                                    <i className="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>

                        <div className="p-8 space-y-6">
                            {/* Start Date */}
                            <div>
                                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Start Date</label>
                                <input
                                    type="date"
                                    value={periodStartDate}
                                    onChange={(e) => setPeriodStartDate(e.target.value)}
                                    className="w-full p-4 bg-slate-50 rounded-xl border border-slate-100 font-bold"
                                />
                            </div>

                            {/* Flow Intensity */}
                            <div>
                                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-3">Flow Intensity</label>
                                <div className="grid grid-cols-4 gap-2">
                                    {FLOW_OPTIONS.map(flow => (
                                        <button
                                            key={flow.id}
                                            onClick={() => setSelectedFlow(flow.id)}
                                            className={`p-3 rounded-xl text-xs font-bold transition-all ${selectedFlow === flow.id
                                                ? `${flow.color} text-white shadow-lg`
                                                : 'bg-slate-50 text-slate-600 hover:bg-slate-100'
                                                }`}
                                        >
                                            {flow.label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Symptoms */}
                            <div>
                                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-3">Symptoms</label>
                                <div className="grid grid-cols-5 gap-2">
                                    {SYMPTOMS.map(symptom => (
                                        <button
                                            key={symptom.id}
                                            onClick={() => toggleSymptom(symptom.id)}
                                            className={`p-3 rounded-xl transition-all flex flex-col items-center gap-1 ${selectedSymptoms.includes(symptom.id)
                                                ? 'bg-rose-500 text-white shadow-lg'
                                                : 'bg-slate-50 text-slate-400 hover:bg-slate-100'
                                                }`}
                                        >
                                            <i className={`fa-solid ${symptom.icon}`}></i>
                                            <span className="text-[8px] font-bold">{symptom.label}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Notes */}
                            <div>
                                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Notes (Optional)</label>
                                <textarea
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    placeholder="Any additional notes..."
                                    className="w-full p-4 bg-slate-50 rounded-xl border border-slate-100 font-medium text-sm resize-none h-20"
                                />
                            </div>
                        </div>

                        <div className="p-8 bg-slate-50 flex gap-4">
                            <button
                                onClick={() => { resetForm(); setShowLogModal(false); }}
                                className="flex-1 py-4 font-bold text-slate-500"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={handleLogPeriod}
                                className="flex-[2] py-4 bg-rose-500 text-white rounded-2xl font-black shadow-xl hover:bg-rose-600 transition-all"
                            >
                                Log Period
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default CycleTracker;
