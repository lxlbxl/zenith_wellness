import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import { User } from '../types';
import Card from './ui/Card';

interface WellnessLog {
    id?: string;
    user_id: string;
    log_date: string;
    water_glasses: number;
    sleep_hours: number;
    sleep_quality?: string;
    energy_level?: number;
    stress_level?: number;
    notes?: string;
}

interface WellnessStats {
    avg_water_week: number;
    avg_sleep_week: number;
    avg_energy_week: number;
    avg_stress_week: number;
    days_logged_week: number;
    logging_streak: number;
}

interface WellnessLoggerProps {
    user: User;
}

const WellnessLogger: React.FC<WellnessLoggerProps> = ({ user }) => {
    const [todayLog, setTodayLog] = useState<WellnessLog | null>(null);
    const [stats, setStats] = useState<WellnessStats | null>(null);
    const [loading, setLoading] = useState(true);

    // Sleep state
    const [sleepHours, setSleepHours] = useState(7);
    const [sleepQuality, setSleepQuality] = useState<string>('good');
    const [isSleepLogged, setIsSleepLogged] = useState(false);

    useEffect(() => {
        fetchData();
    }, [user.id]);

    const fetchData = async () => {
        setLoading(true);
        try {
            const [logRes, statsRes] = await Promise.all([
                api.get<WellnessLog>(`/wellness/today/${user.id}`),
                api.get<WellnessStats>(`/wellness/stats/${user.id}`)
            ]);
            setTodayLog(logRes);
            if (logRes) {
                setSleepHours(logRes.sleep_hours || 7);
                setSleepQuality(logRes.sleep_quality || 'good');
                setIsSleepLogged(!!logRes.sleep_hours);
            }
            setStats(statsRes);
        } catch (err) {
            console.error('Failed to fetch wellness data', err);
        } finally {
            setLoading(false);
        }
    };

    const updateWater = async (glasses: number) => {
        try {
            // Optimistic update
            const newLog = { ...todayLog!, water_glasses: glasses };
            setTodayLog(newLog);

            await api.post('/wellness/log', {
                log_date: new Date().toISOString().split('T')[0],
                water_glasses: glasses
            });
            // Background fetch to sync stats
            fetchStatsOnly();
        } catch (err) {
            console.error('Failed to update water', err);
            fetchData(); // Revert on error
        }
    };

    const handleSaveSleep = async () => {
        try {
            await api.post('/wellness/log', {
                log_date: new Date().toISOString().split('T')[0],
                sleep_hours: sleepHours,
                sleep_quality: sleepQuality
            });
            setIsSleepLogged(true);
            fetchStatsOnly();
        } catch (err) {
            console.error('Failed to log sleep', err);
        }
    };

    const fetchStatsOnly = async () => {
        try {
            const statsRes = await api.get<WellnessStats>(`/wellness/stats/${user.id}`);
            setStats(statsRes);
        } catch (err) { console.error(err); }
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center h-32">
                <i className="fa-solid fa-circle-notch fa-spin text-2xl text-indigo-500"></i>
            </div>
        );
    }

    const waterGoal = 8;
    const currentWater = todayLog?.water_glasses || 0;

    return (
        <div className="space-y-6">
            {/* Water Tracking Card */}
            <Card className="bg-gradient-to-br from-cyan-50 to-blue-50 border-cyan-100 !shadow-sm">
                <div className="flex items-center justify-between mb-6">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-cyan-500 flex items-center justify-center text-white shadow-lg shadow-cyan-200">
                            <i className="fa-solid fa-droplet"></i>
                        </div>
                        <div>
                            <h3 className="font-bold text-slate-900">Hydration</h3>
                            <p className="text-xs text-slate-500 font-medium">Daily Goal: 8 glasses</p>
                        </div>
                    </div>
                    {stats && stats.avg_water_week > 0 && (
                        <div className="text-right">
                            <p className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">7-day avg</p>
                            <p className="font-bold text-cyan-600">{stats.avg_water_week} 💧</p>
                        </div>
                    )}
                </div>

                {/* Visual Glasses Grid */}
                <div className="grid grid-cols-4 sm:grid-cols-8 gap-3 mb-4">
                    {Array.from({ length: waterGoal }).map((_, i) => (
                        <button
                            key={i}
                            onClick={() => updateWater(i + 1 === currentWater ? i : i + 1)}
                            className={`aspect-[3/4] rounded-lg border-2 transition-all duration-300 relative overflow-hidden group ${i < currentWater
                                ? 'border-cyan-400 bg-cyan-400 shadow-md transform scale-105'
                                : 'border-slate-200 bg-white hover:border-cyan-200'
                                }`}
                        >
                            {/* Water Fill Animation */}
                            <div className={`absolute bottom-0 left-0 right-0 bg-white/20 transition-all ${i < currentWater ? 'h-full' : 'h-0'}`}></div>

                            {/* Icon */}
                            <div className="absolute inset-0 flex items-center justify-center">
                                <i className={`fa-solid fa-glass-water transition-colors ${i < currentWater ? 'text-white' : 'text-slate-300 group-hover:text-cyan-200'
                                    }`}></i>
                            </div>
                        </button>
                    ))}
                </div>

                {/* Quick Add Button */}
                <div className="flex justify-center mb-4">
                    <button
                        onClick={() => updateWater(Math.min(currentWater + 1, waterGoal + 4))}
                        className="flex items-center gap-2 px-6 py-3 bg-cyan-500 hover:bg-cyan-600 active:bg-cyan-700 text-white font-bold rounded-xl shadow-lg shadow-cyan-200 transition-all duration-200 hover:scale-105 active:scale-100"
                    >
                        <i className="fa-solid fa-plus"></i>
                        <span>Add 1 Glass</span>
                        <i className="fa-solid fa-droplet ml-1"></i>
                    </button>
                </div>

                <div className="text-center">
                    <p className={`text-sm font-bold transition-colors ${currentWater >= waterGoal ? 'text-cyan-600' : 'text-slate-500'}`}>
                        {currentWater >= waterGoal
                            ? "🎉 Hydration goal reached! Great job!"
                            : `${currentWater} / ${waterGoal} glasses consumed`}
                    </p>
                </div>
            </Card>

            {/* Sleep Tracking Card */}
            <Card className="bg-gradient-to-br from-indigo-50 to-purple-50 border-indigo-100 !shadow-sm">
                <div className="flex items-center justify-between mb-6">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-indigo-500 flex items-center justify-center text-white shadow-lg shadow-indigo-200">
                            <i className="fa-solid fa-moon"></i>
                        </div>
                        <div>
                            <h3 className="font-bold text-slate-900">Sleep</h3>
                            <p className="text-xs text-slate-500 font-medium">{isSleepLogged ? "Log saved" : "How did you sleep?"}</p>
                        </div>
                    </div>
                    {stats && stats.avg_sleep_week > 0 && (
                        <div className="text-right">
                            <p className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">7-day avg</p>
                            <p className="font-bold text-indigo-600">{stats.avg_sleep_week}h 😴</p>
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    {/* Hours Slider */}
                    <div className="bg-white/60 rounded-2xl p-6 border border-white/50">
                        <div className="flex justify-between items-end mb-4">
                            <label className="text-xs font-bold text-slate-500 uppercase tracking-wider">Hours slept</label>
                            <span className="text-3xl font-black text-indigo-600">{sleepHours}<span className="text-sm font-medium text-slate-400 ml-1">hrs</span></span>
                        </div>
                        <input
                            type="range"
                            min="0"
                            max="12"
                            step="0.5"
                            value={sleepHours}
                            onChange={(e) => { setSleepHours(parseFloat(e.target.value)); setIsSleepLogged(false); }}
                            className="w-full h-4 bg-slate-200 rounded-full appearance-none accent-indigo-600 mb-2 cursor-pointer"
                        />
                        <div className="flex justify-between text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                            <span>0h</span>
                            <span>6h</span>
                            <span>12h+</span>
                        </div>
                    </div>

                    {/* Quality Selection */}
                    <div>
                        <label className="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-3">Quality</label>
                        <div className="grid grid-cols-4 gap-3">
                            {[
                                { val: 'poor', label: 'Poor', emoji: '😫', color: 'bg-rose-100 text-rose-600 border-rose-200' },
                                { val: 'fair', label: 'Fair', emoji: '😐', color: 'bg-amber-100 text-amber-600 border-amber-200' },
                                { val: 'good', label: 'Good', emoji: '🙂', color: 'bg-blue-100 text-blue-600 border-blue-200' },
                                { val: 'excellent', label: 'Great', emoji: '🤩', color: 'bg-emerald-100 text-emerald-600 border-emerald-200' }
                            ].map((opt) => (
                                <button
                                    key={opt.val}
                                    onClick={() => { setSleepQuality(opt.val); setIsSleepLogged(false); }}
                                    className={`py-3 rounded-xl border-2 transition-all flex flex-col items-center gap-1 ${sleepQuality === opt.val
                                        ? `${opt.color} shadow-sm scale-105 border-transparent`
                                        : 'bg-white border-slate-100 text-slate-400 hover:border-slate-200'
                                        }`}
                                >
                                    <span className="text-2xl">{opt.emoji}</span>
                                    <span className="text-[10px] font-bold uppercase">{opt.label}</span>
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Action Button */}
                    <button
                        onClick={handleSaveSleep}
                        disabled={isSleepLogged}
                        className={`w-full py-4 rounded-xl font-bold transition-all flex items-center justify-center gap-2 ${isSleepLogged
                            ? 'bg-emerald-500 text-white cursor-default'
                            : 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-lg shadow-indigo-200 active:scale-95'
                            }`}
                    >
                        {isSleepLogged ? (
                            <><i className="fa-solid fa-check"></i> Saved</>
                        ) : (
                            <><i className="fa-solid fa-save"></i> Save Sleep Log</>
                        )}
                    </button>
                </div>
            </Card>
        </div>
    );
};

export default WellnessLogger;
