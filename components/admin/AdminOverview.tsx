
import React, { useEffect, useState } from 'react';
import { api } from '../../services/api';

interface SystemStats {
    total_users: number;
    active_today: number;
    total_journal_entries: number;
    total_goals: number;
    total_achievements_earned: number;
    revenue: number;
}

import AdminLoginHelper from './AdminLoginHelper';

const AdminOverview: React.FC = () => {
    const [stats, setStats] = useState<SystemStats | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api.get<SystemStats>('/admin/stats').then(data => {
            setStats(data);
            setLoading(false);
        }).catch((err) => {
            console.error("Admin Stats Error:", err);
            setError(err.message || "Unknown error occurred");
            setLoading(false);
        });
    }, []);

    if (loading) return <div className="p-10 text-center"><i className="fa-solid fa-spinner fa-spin"></i></div>;
    if (error || !stats) return <AdminLoginHelper onRetry={() => window.location.reload()} errorDetails={error || "Failed to load"} />;

    return (
        <div className="space-y-6">
            <h3 className="text-xl font-bold text-slate-800">Platform Overview</h3>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                    <div className="flex justify-between items-start">
                        <div>
                            <p className="text-xs font-bold text-slate-400 uppercase">Total Users</p>
                            <p className="text-4xl font-black text-slate-900 mt-2">{stats.total_users}</p>
                        </div>
                        <div className="p-3 bg-indigo-50 rounded-2xl text-indigo-500">
                            <i className="fa-solid fa-users text-xl"></i>
                        </div>
                    </div>
                    <p className="text-xs font-bold text-emerald-500 mt-2">
                        {stats.active_today} active today
                    </p>
                </div>

                <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                    <div className="flex justify-between items-start">
                        <div>
                            <p className="text-xs font-bold text-slate-400 uppercase">Revenue (Est.)</p>
                            <p className="text-4xl font-black text-slate-900 mt-2">${stats.revenue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                        </div>
                        <div className="p-3 bg-emerald-50 rounded-2xl text-emerald-500">
                            <i className="fa-solid fa-sack-dollar text-xl"></i>
                        </div>
                    </div>
                    <p className="text-xs text-slate-400 mt-2">Lifetime volume</p>
                </div>

                <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                    <p className="text-xs font-bold text-slate-400 uppercase">Engagement</p>
                    <div className="space-y-2 mt-2">
                        <div className="flex justify-between text-sm">
                            <span className="text-slate-600">Journal Entries</span>
                            <span className="font-bold">{stats.total_journal_entries}</span>
                        </div>
                        <div className="flex justify-between text-sm">
                            <span className="text-slate-600">Goals Created</span>
                            <span className="font-bold">{stats.total_goals}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default AdminOverview;
