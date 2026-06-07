import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import Card from './ui/Card';

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

interface TrophyCabinetProps {
    userId: string;
}

const TrophyCabinet: React.FC<TrophyCabinetProps> = ({ userId }) => {
    const [achievements, setAchievements] = useState<Achievement[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchAchievements = async () => {
            try {
                const res = await api.get<Achievement[]>(`/gamification/achievements/${userId}`);
                // Sort by earned first, then tier value
                const sorted = (res || []).sort((a, b) => {
                    if (a.is_earned !== b.is_earned) return b.is_earned - a.is_earned;
                    const tiers = { platinum: 4, gold: 3, silver: 2, bronze: 1 };
                    return tiers[b.tier] - tiers[a.tier];
                });
                setAchievements(sorted.slice(0, 4)); // Show top 4
            } catch (err) {
                console.error(err);
            } finally {
                setLoading(false);
            }
        };
        fetchAchievements();
    }, [userId]);

    if (loading) return <div className="h-40 bg-slate-100 rounded-3xl animate-pulse"></div>;

    return (
        <Card className="bg-gradient-to-br from-slate-900 to-slate-800 border-slate-700 text-white overflow-hidden relative">
            {/* Background Decor */}
            <div className="absolute top-0 right-0 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl -mr-16 -mt-16 pointer-events-none"></div>

            <div className="flex items-center justify-between mb-6 relative z-10">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-yellow-400 to-amber-600 flex items-center justify-center text-white shadow-lg shadow-amber-900/20">
                        <i className="fa-solid fa-trophy text-sm"></i>
                    </div>
                    <div>
                        <h3 className="font-bold text-white">Trophy Case</h3>
                        <p className="text-xs text-slate-400 font-medium">Recent Achievements</p>
                    </div>
                </div>
                <button className="text-xs font-bold text-indigo-300 hover:text-white transition-colors">
                    View All <i className="fa-solid fa-chevron-right ml-1 text-[10px]"></i>
                </button>
            </div>

            <div className="grid grid-cols-4 gap-3 relative z-10">
                {achievements.map((achievement) => (
                    <div
                        key={achievement.id}
                        className={`aspect-square rounded-2xl flex flex-col items-center justify-center gap-2 p-2 text-center transition-all group ${achievement.is_earned
                                ? 'bg-white/10 hover:bg-white/15 ring-1 ring-white/10'
                                : 'bg-slate-800/50 opacity-40 grayscale'
                            }`}
                    >
                        <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm shadow-lg ${achievement.is_earned ? achievement.badge_color : 'bg-slate-700 text-slate-500'
                            }`}>
                            <i className={`fa-solid ${achievement.icon}`}></i>
                        </div>
                        <p className="text-[10px] font-bold text-slate-300 leading-tight line-clamp-2">
                            {achievement.title}
                        </p>
                    </div>
                ))}
            </div>
        </Card>
    );
};

export default TrophyCabinet;
