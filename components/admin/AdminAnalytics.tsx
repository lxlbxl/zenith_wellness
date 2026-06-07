import React, { useEffect, useState } from 'react';
import { api } from '../../services/api';
import {
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
    AreaChart, Area, LineChart, Line, Legend
} from 'recharts';

const AdminAnalytics: React.FC = () => {
    const [range, setRange] = useState('30d');
    const [revenueData, setRevenueData] = useState<any[]>([]);
    const [userData, setUserData] = useState<any[]>([]);
    const [engagementData, setEngagementData] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchData();
    }, [range]);

    const fetchData = async () => {
        setLoading(true);
        try {
            const [rev, users, eng] = await Promise.all([
                api.get<any[]>(`/analytics/revenue?range=${range}`),
                api.get<any[]>(`/analytics/users?range=${range}`),
                api.get<any[]>(`/analytics/engagement?range=${range}`)
            ]);
            setRevenueData(rev || []);
            setUserData(users || []);
            setEngagementData(eng || []);
        } catch (e) {
            console.error('Failed to load analytics', e);
        } finally {
            setLoading(false);
        }
    };

    const handleExport = () => {
        const csvContent = [
            ['Date', 'Revenue', 'Total Users', 'New Users', 'Wellness Logs', 'Journal Entries'],
            ...revenueData.map((r, i) => {
                const u = userData.find(d => d.date === r.date) || {};
                const e = engagementData.find(d => d.date === r.date) || {};
                return [
                    r.date,
                    r.amount,
                    u.total_users || 0,
                    u.new_users || 0,
                    e.logs || 0,
                    e.journals || 0
                ];
            })
        ].map(e => e.join(",")).join("\n");

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement("a");
        const url = URL.createObjectURL(blob);
        link.setAttribute("href", url);
        link.setAttribute("download", `zenith_analytics_${range}_${new Date().toISOString().split('T')[0]}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    if (loading) return <div className="p-12 text-center text-slate-400"><i className="fa-solid fa-circle-notch fa-spin text-3xl mb-4"></i><p>Crunching the numbers...</p></div>;

    return (
        <div className="space-y-8">
            <div className="flex justify-between items-center bg-white p-4 rounded-3xl border border-slate-100 shadow-sm">
                <div className="flex gap-2">
                    {['7d', '30d', '90d', 'all'].map(r => (
                        <button
                            key={r}
                            onClick={() => setRange(r)}
                            className={`px-4 py-2 rounded-xl text-sm font-bold transition-all ${range === r ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-200' : 'bg-slate-50 text-slate-500 hover:bg-slate-100'}`}
                        >
                            {r.toUpperCase()}
                        </button>
                    ))}
                </div>
                <button onClick={handleExport} className="flex items-center gap-2 px-6 py-2 bg-slate-900 text-white rounded-xl font-bold text-sm hover:bg-slate-800 transition-all shadow-lg shadow-slate-200">
                    <i className="fa-solid fa-download"></i> Export CSV
                </button>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {/* Revenue Chart */}
                <div className="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 className="text-lg font-black text-slate-800 mb-6 flex items-center gap-2">
                        <i className="fa-solid fa-sack-dollar text-emerald-500"></i> Revenue Trend
                    </h3>
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={revenueData}>
                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
                                <XAxis dataKey="date" tick={{ fontSize: 10, fill: '#94a3b8' }} tickFormatter={(val) => new Date(val).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} />
                                <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} tickFormatter={(val) => `$${val}`} />
                                <Tooltip
                                    cursor={{ fill: '#f8fafc' }}
                                    contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                                />
                                <Bar dataKey="amount" fill="#10b981" radius={[4, 4, 0, 0]} name="Revenue ($)" />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </div>

                {/* User Growth */}
                <div className="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 className="text-lg font-black text-slate-800 mb-6 flex items-center gap-2">
                        <i className="fa-solid fa-users text-indigo-500"></i> User Growth
                    </h3>
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={userData}>
                                <defs>
                                    <linearGradient id="colorUsers" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="5%" stopColor="#6366f1" stopOpacity={0.2} />
                                        <stop offset="95%" stopColor="#6366f1" stopOpacity={0} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
                                <XAxis dataKey="date" tick={{ fontSize: 10, fill: '#94a3b8' }} tickFormatter={(val) => new Date(val).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} />
                                <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} />
                                <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 15px -3px rgb(0 0 0 / 0.1)' }} />
                                <Area type="monotone" dataKey="total_users" stroke="#6366f1" strokeWidth={3} fillOpacity={1} fill="url(#colorUsers)" name="Total Users" />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </div>

                {/* Engagement */}
                <div className="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm lg:col-span-2">
                    <h3 className="text-lg font-black text-slate-800 mb-6 flex items-center gap-2">
                        <i className="fa-solid fa-heart-pulse text-rose-500"></i> Platform Engagement
                    </h3>
                    <div className="h-72">
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={engagementData}>
                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
                                <XAxis dataKey="date" tick={{ fontSize: 10, fill: '#94a3b8' }} tickFormatter={(val) => new Date(val).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} />
                                <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} />
                                <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 15px -3px rgb(0 0 0 / 0.1)' }} />
                                <Legend />
                                <Line type="monotone" dataKey="logs" stroke="#f43f5e" strokeWidth={3} dot={false} name="Wellness Logs" />
                                <Line type="monotone" dataKey="journals" stroke="#8b5cf6" strokeWidth={3} dot={false} name="Journal Entries" />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default AdminAnalytics;
