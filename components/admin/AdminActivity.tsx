import React, { useEffect, useState, useMemo } from 'react';
import { api } from '../../services/api';

interface ActivityLog {
    id: number;
    user_id: string | null;
    user_name: string | null;
    user_email: string | null;
    action_type: string;
    description: string;
    metadata: Record<string, any> | null;
    ip_address: string;
    user_agent: string;
    created_at: string;
}

interface ActivityStats {
    total_activities: number;
    activities_today: number;
    activities_this_week: number;
    unique_users_today: number;
    by_action_type: { action_type: string; count: number }[];
    trend_7_days: { date: string; count: number }[];
    most_active_users: { user_id: string; name: string; email: string; activity_count: number }[];
}

interface FilterState {
    action_type: string;
    user_id: string;
    from: string;
    to: string;
}

const AdminActivity: React.FC = () => {
    const [logs, setLogs] = useState<ActivityLog[]>([]);
    const [stats, setStats] = useState<ActivityStats | null>(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [filters, setFilters] = useState<FilterState>({
        action_type: '',
        user_id: '',
        from: '',
        to: ''
    });
    const [view, setView] = useState<'logs' | 'stats'>('logs');

    const loadLogs = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ page: String(page), limit: '50' });
            if (filters.action_type) params.append('action_type', filters.action_type);
            if (filters.user_id) params.append('user_id', filters.user_id);
            if (filters.from) params.append('from', filters.from);
            if (filters.to) params.append('to', filters.to);

            const response = await api.get<{ data: ActivityLog[]; pagination: { total_pages: number } }>(`/activity/list?${params}`);
            setLogs(response.data || []);
            setTotalPages(response.pagination?.total_pages || 1);
        } catch (e) {
            console.error('Failed to load activity logs', e);
        } finally {
            setLoading(false);
        }
    };

    const loadStats = async () => {
        try {
            const data = await api.get<ActivityStats>('/activity/stats');
            setStats(data);
        } catch (e) {
            console.error('Failed to load activity stats', e);
        }
    };

    useEffect(() => {
        loadLogs();
        loadStats();
    }, [page]);

    useEffect(() => {
        setPage(1);
        loadLogs();
    }, [filters]);

    const actionTypes = useMemo(() => {
        const types = new Set(logs.map(l => l.action_type));
        return Array.from(types).sort();
    }, [logs]);

    const getActionIcon = (actionType: string) => {
        const icons: Record<string, string> = {
            login: 'fa-right-to-bracket text-emerald-500',
            logout: 'fa-right-from-bracket text-slate-400',
            page_view: 'fa-eye text-blue-500',
            click: 'fa-hand-pointer text-indigo-500',
            error: 'fa-triangle-exclamation text-rose-500',
            payment: 'fa-credit-card text-amber-500',
            signup: 'fa-user-plus text-teal-500'
        };
        return icons[actionType] || 'fa-circle text-slate-300';
    };

    const formatDate = (date: string) => {
        const d = new Date(date);
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
    };

    const clearFilters = () => {
        setFilters({ action_type: '', user_id: '', from: '', to: '' });
    };

    const hasActiveFilters = Object.values(filters).some(v => v !== '');

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <h3 className="text-xl font-bold text-slate-800">User Activity</h3>
                <div className="flex gap-2">
                    <button
                        onClick={() => setView('logs')}
                        className={`px-4 py-2 rounded-xl text-sm font-bold transition-all ${view === 'logs' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                    >
                        <i className="fa-solid fa-list mr-2"></i>Logs
                    </button>
                    <button
                        onClick={() => setView('stats')}
                        className={`px-4 py-2 rounded-xl text-sm font-bold transition-all ${view === 'stats' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                    >
                        <i className="fa-solid fa-chart-pie mr-2"></i>Stats
                    </button>
                </div>
            </div>

            {view === 'stats' && stats && (
                <div className="space-y-6">
                    {/* Summary Cards */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div className="bg-indigo-50 rounded-2xl p-4 border border-indigo-100">
                            <div className="text-xs font-bold text-indigo-600 uppercase">Total Activities</div>
                            <div className="text-2xl font-black text-indigo-700">{stats.total_activities.toLocaleString()}</div>
                        </div>
                        <div className="bg-emerald-50 rounded-2xl p-4 border border-emerald-100">
                            <div className="text-xs font-bold text-emerald-600 uppercase">Today</div>
                            <div className="text-2xl font-black text-emerald-700">{stats.activities_today.toLocaleString()}</div>
                        </div>
                        <div className="bg-amber-50 rounded-2xl p-4 border border-amber-100">
                            <div className="text-xs font-bold text-amber-600 uppercase">This Week</div>
                            <div className="text-2xl font-black text-amber-700">{stats.activities_this_week.toLocaleString()}</div>
                        </div>
                        <div className="bg-rose-50 rounded-2xl p-4 border border-rose-100">
                            <div className="text-xs font-bold text-rose-600 uppercase">Active Users Today</div>
                            <div className="text-2xl font-black text-rose-700">{stats.unique_users_today}</div>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Activity by Type */}
                        <div className="bg-white rounded-2xl border border-slate-100 p-6">
                            <h4 className="font-bold text-slate-800 mb-4">Activity by Type</h4>
                            <div className="space-y-3">
                                {stats.by_action_type.map(item => (
                                    <div key={item.action_type} className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <i className={`fa-solid ${getActionIcon(item.action_type)} w-5`}></i>
                                            <span className="text-sm font-medium text-slate-700">{item.action_type}</span>
                                        </div>
                                        <span className="text-sm font-bold text-slate-900">{item.count.toLocaleString()}</span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Most Active Users */}
                        <div className="bg-white rounded-2xl border border-slate-100 p-6">
                            <h4 className="font-bold text-slate-800 mb-4">Most Active Users (7 days)</h4>
                            <div className="space-y-3">
                                {stats.most_active_users.map((user, idx) => (
                                    <div key={user.user_id} className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <span className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold ${idx < 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500'}`}>
                                                {idx + 1}
                                            </span>
                                            <div>
                                                <div className="text-sm font-medium text-slate-700">{user.name || 'Unknown'}</div>
                                                <div className="text-xs text-slate-400">{user.email}</div>
                                            </div>
                                        </div>
                                        <span className="text-sm font-bold text-slate-900">{user.activity_count}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {view === 'logs' && (
                <>
                    {/* Filters */}
                    <div className="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                        <div className="flex items-center justify-between mb-3">
                            <span className="text-xs font-bold text-slate-500 uppercase">Filters</span>
                            {hasActiveFilters && (
                                <button onClick={clearFilters} className="text-xs font-bold text-slate-500 hover:text-slate-700">
                                    Clear All
                                </button>
                            )}
                        </div>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <select
                                value={filters.action_type}
                                onChange={e => setFilters(f => ({ ...f, action_type: e.target.value }))}
                                className="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 outline-none bg-white"
                            >
                                <option value="">All Actions</option>
                                <option value="login">Login</option>
                                <option value="logout">Logout</option>
                                <option value="page_view">Page View</option>
                                <option value="click">Click</option>
                                <option value="error">Error</option>
                                <option value="payment">Payment</option>
                                <option value="signup">Signup</option>
                            </select>
                            <input
                                type="text"
                                placeholder="User ID..."
                                value={filters.user_id}
                                onChange={e => setFilters(f => ({ ...f, user_id: e.target.value }))}
                                className="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 outline-none"
                            />
                            <input
                                type="date"
                                value={filters.from}
                                onChange={e => setFilters(f => ({ ...f, from: e.target.value }))}
                                className="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 outline-none"
                                title="From date"
                            />
                            <input
                                type="date"
                                value={filters.to}
                                onChange={e => setFilters(f => ({ ...f, to: e.target.value }))}
                                className="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 outline-none"
                                title="To date"
                            />
                        </div>
                    </div>

                    {/* Logs Table */}
                    <div className="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden p-6">
                        {loading ? (
                            <div className="text-center py-12 text-slate-400">
                                <i className="fa-solid fa-circle-notch fa-spin text-2xl mb-2"></i>
                                <p>Loading activity logs...</p>
                            </div>
                        ) : (
                            <>
                                <table className="w-full text-left">
                                    <thead>
                                        <tr className="border-b border-slate-100">
                                            <th className="pb-4 text-xs font-black uppercase text-slate-400">Time</th>
                                            <th className="pb-4 text-xs font-black uppercase text-slate-400">User</th>
                                            <th className="pb-4 text-xs font-black uppercase text-slate-400">Action</th>
                                            <th className="pb-4 text-xs font-black uppercase text-slate-400">Description</th>
                                            <th className="pb-4 text-xs font-black uppercase text-slate-400">IP</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-50">
                                        {logs.map(log => (
                                            <tr key={log.id} className="hover:bg-slate-50">
                                                <td className="py-3 text-xs text-slate-500 whitespace-nowrap">
                                                    {formatDate(log.created_at)}
                                                </td>
                                                <td className="py-3">
                                                    {log.user_name ? (
                                                        <div>
                                                            <div className="text-sm font-medium text-slate-700">{log.user_name}</div>
                                                            <div className="text-xs text-slate-400">{log.user_email}</div>
                                                        </div>
                                                    ) : (
                                                        <span className="text-xs text-slate-400">Anonymous</span>
                                                    )}
                                                </td>
                                                <td className="py-3">
                                                    <span className="inline-flex items-center gap-2 px-2 py-1 rounded-lg bg-slate-50 text-xs font-bold text-slate-600">
                                                        <i className={`fa-solid ${getActionIcon(log.action_type)}`}></i>
                                                        {log.action_type}
                                                    </span>
                                                </td>
                                                <td className="py-3 text-sm text-slate-600 max-w-xs truncate">
                                                    {log.description || '-'}
                                                </td>
                                                <td className="py-3 text-xs text-slate-400 font-mono">
                                                    {log.ip_address}
                                                </td>
                                            </tr>
                                        ))}
                                        {logs.length === 0 && (
                                            <tr>
                                                <td colSpan={5} className="py-12 text-center text-slate-400">
                                                    No activity logs found
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>

                                {/* Pagination */}
                                {totalPages > 1 && (
                                    <div className="flex items-center justify-between mt-6 pt-4 border-t border-slate-100">
                                        <button
                                            onClick={() => setPage(p => Math.max(1, p - 1))}
                                            disabled={page === 1}
                                            className="px-4 py-2 text-sm font-bold text-slate-600 bg-slate-100 rounded-xl hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <i className="fa-solid fa-chevron-left mr-2"></i>Previous
                                        </button>
                                        <span className="text-sm text-slate-500">
                                            Page {page} of {totalPages}
                                        </span>
                                        <button
                                            onClick={() => setPage(p => Math.min(totalPages, p + 1))}
                                            disabled={page === totalPages}
                                            className="px-4 py-2 text-sm font-bold text-slate-600 bg-slate-100 rounded-xl hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Next<i className="fa-solid fa-chevron-right ml-2"></i>
                                        </button>
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </>
            )}
        </div>
    );
};

export default AdminActivity;
