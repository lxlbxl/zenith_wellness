import React, { useState, useEffect, useRef } from 'react';
import { api } from '../services/api';
import { User } from '../types';

interface UserPoints {
    points: number;
    level: number;
    points_to_next_level: number;
    total_points_earned: number;
}

interface Achievement {
    id: string;
    title: string;
    description: string;
    category: string;
    icon: string;
    points: number;
    badge_color: string;
    tier: 'bronze' | 'silver' | 'gold' | 'platinum';
    is_earned: number;
    earned_at?: string;
}

interface LeaderboardEntry {
    id: string;
    name: string;
    level: number;
    score: number;
}

interface HistoryEntry {
    id: string;
    points: number;
    reason: string;
    source_type: string;
    earned_at: string;
}

interface GamificationHubProps {
    user: User;
}

const GamificationHub: React.FC<GamificationHubProps> = ({ user }) => {
    const [activeTab, setActiveTab] = useState<'achievements' | 'leaderboard' | 'history'>('achievements');
    const [points, setPoints] = useState<UserPoints | null>(null);
    const [achievements, setAchievements] = useState<Achievement[]>([]);
    const [leaderboard, setLeaderboard] = useState<LeaderboardEntry[]>([]);
    const [history, setHistory] = useState<HistoryEntry[]>([]); // New state
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchData();
    }, [user.id]);

    const fetchData = async () => {
        setLoading(true);
        try {
            const [pointsRes, achievementsRes, leaderboardRes, historyRes] = await Promise.all([
                api.get<UserPoints>(`/gamification/points/${user.id}`),
                api.get<Achievement[]>(`/gamification/achievements/${user.id}`),
                api.get<LeaderboardEntry[]>('/gamification/leaderboard'),
                api.get<HistoryEntry[]>(`/gamification/history/${user.id}`) // Fetch history
            ]);

            setPoints(pointsRes);
            setAchievements(achievementsRes || []);
            setLeaderboard(leaderboardRes || []);
            setHistory(historyRes || []);
        } catch (err) {
            console.error('Failed to fetch gamification data', err);
        } finally {
            setLoading(false);
        }
    };

    const getTierColor = (tier: string) => {
        switch (tier) {
            case 'bronze': return 'text-amber-700 bg-amber-100 border-amber-200';
            case 'silver': return 'text-slate-600 bg-slate-100 border-slate-300';
            case 'gold': return 'text-yellow-600 bg-yellow-100 border-yellow-300';
            case 'platinum': return 'text-indigo-600 bg-indigo-100 border-indigo-300';
            default: return 'text-gray-600 bg-gray-100';
        }
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center h-64">
                <i className="fa-solid fa-circle-notch fa-spin text-3xl text-indigo-500"></i>
            </div>
        );
    }

    return (
        <div className="space-y-8 animate-in fade-in duration-500">
            {/* Header & Level Progress */}
            <div className="bg-gradient-to-r from-indigo-600 to-purple-600 rounded-3xl p-8 text-white shadow-xl relative overflow-hidden group hover:scale-[1.01] transition-transform duration-500">
                <div className="absolute top-0 right-0 w-64 h-64 bg-white/10 rounded-full blur-3xl -mr-16 -mt-16 group-hover:bg-white/20 transition-colors"></div>
                <div className="relative z-10">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <p className="text-indigo-200 font-bold uppercase tracking-wider text-sm mb-1">Current Level</p>
                            <h1 className="text-6xl font-black drop-shadow-md">{points?.level || 1}</h1>
                        </div>
                        <div className="text-right">
                            <p className="text-indigo-200 font-bold uppercase tracking-wider text-sm mb-1">Total Points</p>
                            <h2 className="text-3xl font-bold font-mono">{points?.total_points_earned.toLocaleString() || 0} XP</h2>
                        </div>
                    </div>

                    <div className="bg-black/20 rounded-full h-5 mb-3 overflow-hidden backdrop-blur-sm shadow-inner p-1">
                        <div
                            className="bg-gradient-to-r from-white/80 to-white h-full rounded-full transition-all duration-1000 ease-out shadow-[0_0_15px_rgba(255,255,255,0.6)] relative group"
                            style={{ width: `${Math.min(100, ((points?.points || 0) / (points?.points_to_next_level || 100)) * 100)}%` }}
                        >
                            <div className="absolute top-0 right-0 bottom-0 w-8 bg-gradient-to-r from-transparent to-white/20 animate-pulse"></div>
                        </div>
                    </div>
                    <div className="flex justify-between items-end">
                        <div>
                            <p className="text-[10px] font-black uppercase text-indigo-300 tracking-tighter mb-1">Current XP</p>
                            <span className="text-xl font-black">{points?.points || 0}</span>
                            <span className="text-indigo-300 text-xs font-bold ml-1">/ {points?.points_to_next_level || 100}</span>
                        </div>
                        <div className="text-right">
                            <span className="text-[10px] font-black text-indigo-200 bg-white/10 px-3 py-1 rounded-full uppercase tracking-widest border border-white/10">
                                Rank: {points?.level && points.level > 10 ? 'Elite Optimizer' : points?.level && points.level > 5 ? 'Advanced Tracker' : 'Novice Explorer'}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Navigation Tabs */}
            <div className="flex gap-2 bg-slate-100 p-1.5 rounded-2xl w-fit mx-auto md:mx-0">
                <button
                    onClick={() => setActiveTab('achievements')}
                    className={`px-4 py-2 md:px-6 md:py-3 rounded-xl text-sm font-bold transition-all flex items-center gap-2 ${activeTab === 'achievements'
                        ? 'bg-white text-slate-900 shadow-md'
                        : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                        }`}
                >
                    <i className="fa-solid fa-trophy"></i>
                    <span className="hidden md:inline">Achievements</span>
                </button>
                <button
                    onClick={() => setActiveTab('leaderboard')}
                    className={`px-4 py-2 md:px-6 md:py-3 rounded-xl text-sm font-bold transition-all flex items-center gap-2 ${activeTab === 'leaderboard'
                        ? 'bg-white text-slate-900 shadow-md'
                        : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                        }`}
                >
                    <i className="fa-solid fa-list-ol"></i>
                    <span className="hidden md:inline">Leaderboard</span>
                </button>
                <button
                    onClick={() => setActiveTab('history')}
                    className={`px-4 py-2 md:px-6 md:py-3 rounded-xl text-sm font-bold transition-all flex items-center gap-2 ${activeTab === 'history'
                        ? 'bg-white text-slate-900 shadow-md'
                        : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                        }`}
                >
                    <i className="fa-solid fa-clock-rotate-left"></i>
                    <span className="hidden md:inline">History</span>
                </button>
            </div>

            {/* Achievements List */}
            {activeTab === 'achievements' && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 animate-in slide-in-from-bottom-4 duration-500">
                    {achievements.map((achievement) => (
                        <div
                            key={achievement.id}
                            className={`relative bg-white rounded-2xl p-6 border-2 transition-all group ${achievement.is_earned
                                ? 'border-emerald-100 shadow-md hover:shadow-lg hover:-translate-y-1'
                                : 'border-slate-100 opacity-70 grayscale hover:grayscale-0 hover:opacity-100'
                                }`}
                        >
                            <div className="flex items-start gap-4">
                                <div className={`w-16 h-16 rounded-2xl flex items-center justify-center text-2xl transition-transform group-hover:scale-110 ${achievement.is_earned ? achievement.badge_color : 'bg-slate-100 text-slate-400'
                                    } text-white shadow-lg`}>
                                    <i className={`fa-solid ${achievement.icon}`}></i>
                                </div>
                                <div className="flex-1">
                                    <div className="flex justify-between items-start mb-1">
                                        <h3 className="font-bold text-slate-900 text-lg">{achievement.title}</h3>
                                        {achievement.is_earned === 1 && (
                                            <span className="text-emerald-600 text-[10px] font-black uppercase bg-emerald-50 px-2 py-1 rounded-lg border border-emerald-100 tracking-wider">
                                                <i className="fa-solid fa-check mr-1"></i> Earned
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-sm text-slate-500 mb-3 leading-relaxed">{achievement.description}</p>

                                    <div className="flex items-center gap-2">
                                        <span className={`text-[10px] font-bold px-2 py-1 rounded-md border ${getTierColor(achievement.tier)} uppercase tracking-wide`}>
                                            {achievement.tier}
                                        </span>
                                        <span className="text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded-md border border-indigo-100 flex items-center">
                                            <i className="fa-solid fa-bolt mr-1 text-indigo-400"></i>
                                            {achievement.points} XP
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Leaderboard */}
            {activeTab === 'leaderboard' && (
                <div className="bg-white rounded-[2rem] border border-slate-100 overflow-hidden shadow-sm animate-in slide-in-from-bottom-4 duration-500">
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className="bg-slate-50 border-b border-slate-100">
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Rank</th>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">User</th>
                                    <th className="px-6 py-4 text-center text-xs font-bold text-slate-500 uppercase tracking-wider">Level</th>
                                    <th className="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-wider">Score</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {leaderboard.map((entry, index) => (
                                    <tr
                                        key={entry.id}
                                        className={`hover:bg-slate-50 transition-colors ${entry.id === user.id ? 'bg-indigo-50/50' : ''}`}
                                    >
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            <div className={`w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm ${index === 0 ? 'bg-yellow-100 text-yellow-700 shadow-sm border border-yellow-200' :
                                                index === 1 ? 'bg-slate-200 text-slate-700 shadow-sm border border-slate-300' :
                                                    index === 2 ? 'bg-amber-100 text-amber-800 shadow-sm border border-amber-200' :
                                                        'text-slate-500'
                                                }`}>
                                                {index + 1}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            <div className="flex items-center">
                                                <div className="h-10 w-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 flex items-center justify-center text-white font-bold text-sm mr-3 shadow-md border-2 border-white">
                                                    {entry.name.substring(0, 2).toUpperCase()}
                                                </div>
                                                <div className="font-bold text-slate-900">
                                                    {entry.name}
                                                    {entry.id === user.id && <span className="ml-2 text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-bold">YOU</span>}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-center">
                                            <span className="px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                Lvl {entry.level}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-right font-mono font-bold text-indigo-600">
                                            {entry.score.toLocaleString()} XP
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* History Tab */}
            {activeTab === 'history' && (
                <div className="bg-white rounded-[2rem] border border-slate-100 overflow-hidden shadow-sm animate-in slide-in-from-bottom-4 duration-500">
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className="bg-slate-50 border-b border-slate-100">
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Date</th>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Action</th>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Type</th>
                                    <th className="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-wider">Points</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {history.map((entry) => (
                                    <tr key={entry.id} className="hover:bg-slate-50 transition-colors">
                                        <td className="px-6 py-4 whitespace-nowrap text-xs font-bold text-slate-500">
                                            {new Date(entry.earned_at).toLocaleDateString()}
                                            <span className="block text-[10px] font-normal opacity-70">
                                                {new Date(entry.earned_at).toLocaleTimeString()}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="text-sm font-bold text-slate-800">{entry.reason}</div>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            <span className={`px-2 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider ${entry.source_type === 'achievement' ? 'bg-amber-100 text-amber-700' :
                                                entry.source_type === 'habit' ? 'bg-emerald-100 text-emerald-700' :
                                                    'bg-indigo-100 text-indigo-700'
                                                }`}>
                                                {entry.source_type}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-right font-mono font-bold text-emerald-600">
                                            +{entry.points}
                                        </td>
                                    </tr>
                                ))}
                                {history.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-6 py-12 text-center text-slate-400 text-sm">
                                            No points history yet. Start completing habits!
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
};

export default GamificationHub;
