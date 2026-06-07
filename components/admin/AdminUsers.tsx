
import React, { useEffect, useState } from 'react';
import { api } from '../../services/api';
import { User } from '../../types';

interface UserDetails {
    user: User;
    stats: any;
    enrollments: any[];
    payments: any[];
    logs: any[];
}

const AdminUsers: React.FC = () => {
    const [users, setUsers] = useState<User[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [error, setError] = useState<string | null>(null);

    const [selectedUserId, setSelectedUserId] = useState<string | null>(null);
    const [userDetails, setUserDetails] = useState<UserDetails | null>(null);
    const [loadingDetails, setLoadingDetails] = useState(false);

    useEffect(() => {
        loadUsers();
    }, [search]);

    useEffect(() => {
        if (selectedUserId) {
            loadUserDetails(selectedUserId);
        } else {
            setUserDetails(null);
        }
    }, [selectedUserId]);

    const loadUsers = async () => {
        setLoading(true);
        setError(null);
        try {
            const query = search ? `?search=${search}` : '';
            const data = await api.get<User[]>(`/admin/users${query}`);
            setUsers(data || []);
        } catch (err: any) {
            console.error("Failed to load admin users:", err);
            setError(err.message || 'Failed to load users. Please check your admin permissions.');
        } finally {
            setLoading(false);
        }
    };

    const loadUserDetails = async (id: string) => {
        setLoadingDetails(true);
        try {
            const data = await api.get<UserDetails>(`/admin/user?id=${id}`);
            setUserDetails(data);
        } catch (err) {
            console.error('Failed to load user details:', err);
            alert('Failed to load details');
        } finally {
            setLoadingDetails(false);
        }
    };

    const handleRoleChange = async (userId: string, newRole: string) => {
        if (!confirm(`Change role to ${newRole}?`)) return;
        await api.post('/admin/role', { userId, role: newRole });
        loadUsers();
        if (selectedUserId === userId) loadUserDetails(userId); // Refresh details if open
    };

    const handleBanUser = async (userId: string) => {
        if (!confirm('Ban user?')) return;
        await api.post('/admin/ban', { userId });
        loadUsers();
        if (selectedUserId === userId) loadUserDetails(userId);
    };

    return (
        <div className="space-y-6">
            <div className="flex justify-between items-center">
                <h3 className="text-xl font-bold text-slate-800">User Management</h3>
                <input
                    type="text"
                    placeholder="Search users..."
                    className="bg-white border-slate-200 rounded-xl px-4 py-2 text-sm"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </div>

            <div className="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden p-6">
                {loading ? (
                    <div className="text-center py-12">
                        <div className="w-12 h-12 mx-auto mb-4 border-4 border-slate-100 border-t-indigo-600 rounded-full animate-spin"></div>
                        <p className="text-slate-500 font-medium">Loading users...</p>
                    </div>
                ) : error ? (
                    <div className="text-center py-12">
                        <div className="w-16 h-16 mx-auto mb-4 bg-rose-50 rounded-2xl flex items-center justify-center">
                            <i className="fa-solid fa-triangle-exclamation text-rose-500 text-2xl"></i>
                        </div>
                        <h4 className="font-bold text-slate-800 mb-2">Unable to Load Users</h4>
                        <p className="text-sm text-slate-500 mb-6 max-w-sm mx-auto">{error}</p>
                        <button
                            onClick={loadUsers}
                            className="px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm hover:bg-indigo-700 transition-all"
                        >
                            <i className="fa-solid fa-rotate-right mr-2"></i>Try Again
                        </button>
                    </div>
                ) : (
                    <table className="w-full text-left bg-white">
                        <thead>
                            <tr className="border-b border-slate-100">
                                <th className="pb-4 pl-2 text-xs font-black uppercase text-slate-400">User</th>
                                <th className="pb-4 text-xs font-black uppercase text-slate-400">Role</th>
                                <th className="pb-4 text-xs font-black uppercase text-slate-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {users.map(user => (
                                <tr key={user.id} className="hover:bg-slate-50">
                                    <td className="py-4 pl-2">
                                        <div className="font-bold text-slate-900">{user.name}</div>
                                        <div className="text-xs text-slate-500">{user.email}</div>
                                    </td>
                                    <td className="py-4">
                                        <span className={`px-2 py-1 rounded-lg text-xs font-bold uppercase ${user.role === 'admin' ? 'bg-purple-100 text-purple-700' :
                                            user.role === 'banned' ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-700'
                                            }`}>
                                            {user.role}
                                        </span>
                                    </td>
                                    <td className="py-4">
                                        <div className="flex gap-2">
                                            <button onClick={() => setSelectedUserId(user.id)} className="text-xs text-indigo-600 font-bold hover:underline bg-indigo-50 px-2 py-1 rounded">
                                                View
                                            </button>
                                            {user.role !== 'admin' && (
                                                <button onClick={() => handleRoleChange(user.id, 'admin')} className="text-xs text-slate-500 font-bold hover:underline">Promote</button>
                                            )}
                                            {user.role === 'admin' && (
                                                <button onClick={() => handleRoleChange(user.id, 'user')} className="text-xs text-orange-600 font-bold hover:underline">Demote</button>
                                            )}
                                            {user.role !== 'banned' && (
                                                <button onClick={() => handleBanUser(user.id)} className="text-xs text-rose-600 font-bold hover:underline">Ban</button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* User Details Modal */}
            {selectedUserId && (
                <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-sm" onClick={() => setSelectedUserId(null)}>
                    <div className="bg-white rounded-[2rem] w-full max-w-4xl max-h-[90vh] overflow-y-auto shadow-2xl" onClick={e => e.stopPropagation()}>
                        {loadingDetails || !userDetails ? (
                            <div className="p-12 text-center">
                                <i className="fa-solid fa-circle-notch fa-spin text-3xl text-indigo-500"></i>
                            </div>
                        ) : (
                            <div className="p-8">
                                <div className="flex justify-between items-start mb-8">
                                    <div>
                                        <h2 className="text-2xl font-black text-slate-800">{userDetails.user.name}</h2>
                                        <p className="text-slate-500">{userDetails.user.email}</p>
                                        <div className="flex gap-2 mt-2">
                                            <span className="px-2 py-1 bg-slate-100 rounded text-xs font-bold uppercase text-slate-600">{userDetails.user.role}</span>
                                            <span className="px-2 py-1 bg-indigo-50 rounded text-xs font-bold uppercase text-indigo-600">{userDetails.user.persona || 'Newbie'}</span>
                                        </div>
                                    </div>
                                    <button onClick={() => setSelectedUserId(null)} className="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center hover:bg-slate-200 transition-colors">
                                        <i className="fa-solid fa-xmark text-slate-500"></i>
                                    </button>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                                    {/* Quick Stats */}
                                    <div className="bg-slate-50 p-6 rounded-3xl border border-slate-100">
                                        <h4 className="font-bold text-slate-800 mb-4">Wellness Stats</h4>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="bg-white p-4 rounded-2xl shadow-sm">
                                                <p className="text-xs text-slate-400 uppercase font-black">Focus Minutes</p>
                                                <p className="text-2xl font-black text-indigo-600">{userDetails.stats?.focus_minutes || 0}</p>
                                            </div>
                                            <div className="bg-white p-4 rounded-2xl shadow-sm">
                                                <p className="text-xs text-slate-400 uppercase font-black">Goals</p>
                                                <p className="text-2xl font-black text-emerald-600">{userDetails.stats?.goals ? JSON.parse(userDetails.stats.goals).length : 0}</p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Enrollments */}
                                    <div className="bg-slate-50 p-6 rounded-3xl border border-slate-100">
                                        <h4 className="font-bold text-slate-800 mb-4">Active Enrollments</h4>
                                        <div className="space-y-3">
                                            {userDetails.enrollments.map((enr, i) => (
                                                <div key={i} className="bg-white p-3 rounded-xl border border-slate-100 shadow-sm flex justify-between items-center">
                                                    <div>
                                                        <p className="font-bold text-sm text-slate-800">{enr.cohort_title}</p>
                                                        <p className="text-xs text-slate-500">Expires: {new Date(enr.access_expires_at).toLocaleDateString()}</p>
                                                    </div>
                                                    <span className={`text-[10px] font-black uppercase px-2 py-1 rounded ${enr.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500'}`}>
                                                        {enr.status}
                                                    </span>
                                                </div>
                                            ))}
                                            {userDetails.enrollments.length === 0 && (
                                                <p className="text-sm text-slate-400 italic">No active enrollments</p>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Transaction History */}
                                <div className="mb-8">
                                    <h4 className="font-bold text-slate-800 mb-4">Transaction History</h4>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left">
                                            <thead>
                                                <tr className="text-xs font-black text-slate-400 uppercase border-b border-slate-100">
                                                    <th className="pb-2">Date</th>
                                                    <th className="pb-2">Description</th>
                                                    <th className="pb-2">Amount</th>
                                                    <th className="pb-2">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody className="text-sm">
                                                {userDetails.payments.map((pay, i) => (
                                                    <tr key={i} className="border-b border-slate-50">
                                                        <td className="py-3 text-slate-500">{new Date(pay.created_at).toLocaleDateString()}</td>
                                                        <td className="py-3 font-medium text-slate-700">{pay.description}</td>
                                                        <td className="py-3 font-bold text-slate-900">${(pay.amount / 100).toFixed(2)}</td>
                                                        <td className="py-3">
                                                            <span className={`text-[10px] font-black uppercase px-2 py-1 rounded ${pay.status === 'success' ? 'bg-emerald-100 text-emerald-600' : 'bg-amber-100 text-amber-600'}`}>{pay.status}</span>
                                                        </td>
                                                    </tr>
                                                ))}
                                                {userDetails.payments.length === 0 && (
                                                    <tr><td colSpan={4} className="py-4 text-center text-slate-400 italic">No transactions found</td></tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                {/* Quick Actions */}
                                <div className="flex gap-3 justify-end pt-6 border-t border-slate-100">
                                    <button
                                        onClick={() => handleBanUser(userDetails.user.id)}
                                        className="bg-rose-50 text-rose-600 px-4 py-2 rounded-xl text-sm font-bold hover:bg-rose-100 transition-colors"
                                    >
                                        <i className="fa-solid fa-ban mr-2"></i>Ban User
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
};

export default AdminUsers;
