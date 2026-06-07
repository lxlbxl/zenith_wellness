import React, { useState, useEffect } from 'react';
import { User, Program, UserStats } from '../../types';
import { getCohortMorningBriefing } from '../../services/geminiService';

interface DailyBriefingProps {
    user: User;
    program: Program;
    stats: UserStats;
}

interface BriefingData {
    briefing: string;
    tasks: { label: string; difficulty: string }[];
    performanceTip: string;
}

const DailyBriefing: React.FC<DailyBriefingProps> = ({ user, program, stats }) => {
    const [data, setData] = useState<BriefingData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const fetchBriefing = async () => {
            setError(null);
            try {
                const res = await getCohortMorningBriefing(user.id, program, stats);
                if (res) setData(res);
            } catch (err: any) {
                console.error("Briefing Error", err);
                setError(err?.message || "Could not load your morning briefing.");
            } finally {
                setLoading(false);
            }
        };
        fetchBriefing();
    }, [user.id, program.id]);

    if (loading) {
        return (
            <div className="bg-white/40 backdrop-blur-md rounded-[2.5rem] p-8 border border-white/20 animate-pulse h-48 flex items-center justify-center">
                <div className="flex flex-col items-center gap-4 text-slate-400">
                    <i className="fa-solid fa-sparkles animate-spin text-2xl text-indigo-400"></i>
                    <p className="text-xs font-black uppercase tracking-widest">Generating Your Daily Intelligence...</p>
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="bg-amber-50 border-2 border-amber-200 rounded-[2.5rem] p-8 flex items-center gap-5">
                <div className="w-12 h-12 bg-amber-100 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <i className="fa-solid fa-satellite-dish text-amber-600"></i>
                </div>
                <div className="flex-1">
                    <p className="text-sm font-bold text-amber-800">Briefing Unavailable</p>
                    <p className="text-xs text-amber-600">{error}</p>
                </div>
                <button
                    onClick={() => { setLoading(true); setError(null); setData(null); }}
                    className="px-5 py-2.5 bg-amber-600 text-white rounded-xl text-xs font-bold hover:bg-amber-700 transition-all"
                >
                    Retry
                </button>
            </div>
        );
    }

    if (!data) return null;

    return (
        <div className="relative overflow-hidden group">
            <div className="absolute inset-0 bg-gradient-to-br from-indigo-600/10 to-purple-600/10 rounded-[3rem] blur-xl group-hover:blur-2xl transition-all"></div>
            <div className="relative bg-white/60 backdrop-blur-xl rounded-[3rem] border border-white/40 shadow-2xl shadow-indigo-200/20 p-8 md:p-10 overflow-hidden">
                <div className="absolute top-0 right-0 p-8 text-indigo-100 opacity-20 pointer-events-none">
                    <i className="fa-solid fa-quote-right text-8xl"></i>
                </div>

                <header className="flex items-center gap-4 mb-8">
                    <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-brand-500 flex items-center justify-center text-white shadow-lg">
                        <i className="fa-solid fa-sun-bright"></i>
                    </div>
                    <div>
                        <p className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-0.5">Morning Intelligence</p>
                        <h2 className="text-2xl font-black text-slate-900 leading-tight">Your Cohort Briefing</h2>
                    </div>
                    <span className="ml-auto bg-indigo-50 text-indigo-600 px-4 py-1.5 rounded-full text-[10px] font-black uppercase border border-indigo-100">
                        Day 4 of 21
                    </span>
                </header>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-10">
                    <div className="space-y-6">
                        <p className="text-lg font-serif italic text-slate-700 leading-relaxed">
                            "{data.briefing}"
                        </p>
                        <div className="flex items-start gap-4 p-5 bg-brand-50 rounded-[2rem] border border-brand-100">
                            <div className="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-brand-600 shadow-sm shrink-0">
                                <i className="fa-solid fa-lightbulb"></i>
                            </div>
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-widest text-brand-400 mb-1">Performance Tip</p>
                                <p className="text-sm font-bold text-slate-800 leading-snug">{data.performanceTip}</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-slate-50/50 rounded-[2.5rem] p-8 border border-white">
                        <h3 className="text-xs font-black uppercase tracking-[0.2em] text-slate-400 mb-6">Today's Protocol Objectives</h3>
                        <div className="space-y-4">
                            {data.tasks?.map((task, idx) => (
                                <div key={idx} className="flex items-center gap-4 group/item">
                                    <div className="w-6 h-6 rounded-lg bg-white border border-slate-200 flex items-center justify-center text-slate-200 group-hover/item:border-indigo-400 transition-colors">
                                        <i className="fa-solid fa-circle text-[8px]"></i>
                                    </div>
                                    <span className="flex-1 text-sm font-bold text-slate-700">{task.label}</span>
                                    <span className={`text-[9px] font-black uppercase px-2 py-0.5 rounded ${task.difficulty === 'high' ? 'bg-rose-50 text-rose-500' :
                                        task.difficulty === 'medium' ? 'bg-amber-50 text-amber-500' :
                                            'bg-emerald-50 text-emerald-500'
                                        }`}>
                                        {task.difficulty}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default DailyBriefing;
