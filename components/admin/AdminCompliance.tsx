import React, { useState, useEffect } from 'react';
import { api } from '../../services/api';

interface Request {
    id: string;
    user_id: string;
    type: 'export' | 'delete';
    status: 'pending' | 'processed' | 'rejected';
    reason?: string;
    admin_notes?: string;
    created_at: string;
    name: string;
    email: string;
}

const AdminCompliance: React.FC = () => {
    const [requests, setRequests] = useState<Request[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        loadRequests();
    }, []);

    const loadRequests = async () => {
        try {
            const data = await api.get<Request[]>('/admin-compliance/list');
            if (data) setRequests(data);
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    };

    const handleAction = async (id: string, status: 'processed' | 'rejected') => {
        const notes = prompt(`Enter notes for ${status === 'processed' ? 'approval' : 'rejection'}:`);
        if (notes === null) return;

        try {
            await api.post('/admin-compliance/process', { id, status, notes });
            loadRequests();
        } catch (e) {
            alert('Failed to process request');
        }
    };

    if (loading) return <div className="p-10 text-center text-slate-400">Loading compliance data...</div>;

    return (
        <div className="space-y-6">
            <header className="flex justify-between items-center">
                <div>
                    <h2 className="text-2xl font-black text-slate-800">Compliance Requests</h2>
                    <p className="text-slate-500">Manage GDPR/CCPA data export and deletion requests.</p>
                </div>
                <button onClick={loadRequests} className="w-10 h-10 bg-white rounded-xl border border-slate-100 flex items-center justify-center text-slate-400 hover:text-indigo-600">
                    <i className="fa-solid fa-rotate-right"></i>
                </button>
            </header>

            <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                <table className="w-full text-left">
                    <thead className="bg-slate-50 border-b border-slate-100 text-xs font-bold text-slate-400 uppercase tracking-widest">
                        <tr>
                            <th className="p-6">User</th>
                            <th className="p-6">Type</th>
                            <th className="p-6">Status</th>
                            <th className="p-6">Reason / Date</th>
                            <th className="p-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {requests.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="p-10 text-center text-slate-400">No pending requests found.</td>
                            </tr>
                        ) : (
                            requests.map(req => (
                                <tr key={req.id} className="hover:bg-slate-50/50 transition-colors">
                                    <td className="p-6">
                                        <p className="font-bold text-slate-800">{req.name}</p>
                                        <p className="text-xs text-slate-400">{req.email}</p>
                                    </td>
                                    <td className="p-6">
                                        <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${req.type === 'delete' ? 'bg-rose-100 text-rose-600' : 'bg-indigo-100 text-indigo-600'
                                            }`}>
                                            {req.type === 'delete' ? 'Deletion' : 'Data Export'}
                                        </span>
                                    </td>
                                    <td className="p-6">
                                        <span className={`flex items-center gap-2 text-xs font-bold capitalize ${req.status === 'pending' ? 'text-amber-500' :
                                                req.status === 'processed' ? 'text-emerald-500' : 'text-slate-400'
                                            }`}>
                                            <span className={`w-2 h-2 rounded-full ${req.status === 'pending' ? 'bg-amber-500 animate-pulse' :
                                                    req.status === 'processed' ? 'bg-emerald-500' : 'bg-slate-400'
                                                }`}></span>
                                            {req.status}
                                        </span>
                                    </td>
                                    <td className="p-6">
                                        {req.reason && (
                                            <p className="text-xs text-slate-600 mb-1 max-w-xs truncate" title={req.reason}>"{req.reason}"</p>
                                        )}
                                        <p className="text-[10px] text-slate-400">{new Date(req.created_at).toLocaleDateString()}</p>
                                    </td>
                                    <td className="p-6 text-right">
                                        {req.status === 'pending' && (
                                            <div className="flex justify-end gap-2">
                                                <button
                                                    onClick={() => handleAction(req.id, 'processed')}
                                                    className="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100 flex items-center justify-center transition-colors"
                                                    title="Approve & Process"
                                                >
                                                    <i className="fa-solid fa-check"></i>
                                                </button>
                                                <button
                                                    onClick={() => handleAction(req.id, 'rejected')}
                                                    className="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100 flex items-center justify-center transition-colors"
                                                    title="Reject"
                                                >
                                                    <i className="fa-solid fa-xmark"></i>
                                                </button>
                                            </div>
                                        )}
                                        {req.status !== 'pending' && (
                                            <span className="text-xs text-slate-300 italic">No actions needed</span>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default AdminCompliance;
