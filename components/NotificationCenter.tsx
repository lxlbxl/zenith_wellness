import React, { useState, useEffect, useRef } from 'react';
import { api } from '../services/api';

interface Notification {
    id: string;
    type: 'success' | 'info' | 'warning' | 'error';
    title: string;
    message: string;
    link?: string;
    is_read: boolean;
    created_at: string;
}

interface NotificationCenterProps {
    user?: { id: string; [key: string]: any };
}

const NotificationCenter: React.FC<NotificationCenterProps> = ({ user }) => {
    const [notifications, setNotifications] = useState<Notification[]>([]);
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    const unreadCount = notifications.filter(n => !n.is_read).length;

    useEffect(() => {
        fetchNotifications();
        // Poll every 60s
        const interval = setInterval(fetchNotifications, 60000);
        return () => clearInterval(interval);
    }, []);

    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const fetchNotifications = async () => {
        const data = await api.get<Notification[]>('/notifications/unread');
        if (data) setNotifications(data);
    };

    const markRead = async (id: string) => {
        await api.post('/notifications/mark-read', { id });
        setNotifications(prev => prev.filter(n => n.id !== id));
    };

    const markAllRead = async () => {
        await api.post('/notifications/mark-all-read', {});
        setNotifications([]);
        setIsOpen(false);
    };

    const getIcon = (type: string) => {
        switch (type) {
            case 'success': return 'fa-circle-check text-emerald-500';
            case 'warning': return 'fa-triangle-exclamation text-amber-500';
            case 'error': return 'fa-circle-xmark text-rose-500';
            default: return 'fa-circle-info text-indigo-500';
        }
    };

    return (
        <div className="relative" ref={containerRef}>
            <button
                onClick={() => setIsOpen(!isOpen)}
                className="relative p-2 rounded-xl text-slate-400 hover:text-indigo-600 hover:bg-slate-50 transition-colors"
            >
                <i className="fa-solid fa-bell text-xl"></i>
                {unreadCount > 0 && (
                    <span className="absolute top-1 right-1 w-4 h-4 bg-rose-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border-2 border-white">
                        {unreadCount > 9 ? '9+' : unreadCount}
                    </span>
                )}
            </button>

            {isOpen && (
                <div className="absolute right-0 mt-4 w-80 md:w-96 bg-white rounded-3xl shadow-xl border border-slate-100 z-50 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                    <div className="p-4 border-b border-slate-50 flex justify-between items-center bg-slate-50/50">
                        <h3 className="font-bold text-slate-800">Notifications</h3>
                        {unreadCount > 0 && (
                            <button onClick={markAllRead} className="text-xs font-bold text-indigo-600 hover:text-indigo-700">
                                Mark all as read
                            </button>
                        )}
                    </div>

                    <div className="max-h-[400px] overflow-y-auto">
                        {notifications.length === 0 ? (
                            <div className="p-8 text-center text-slate-400">
                                <i className="fa-regular fa-bell-slash text-3xl mb-2"></i>
                                <p className="text-xs">No new notifications</p>
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-50">
                                {notifications.map(n => (
                                    <div key={n.id} className="p-4 hover:bg-slate-50 transition-colors flex gap-3 group">
                                        <div className="mt-1 flex-shrink-0">
                                            <i className={`fa-solid ${getIcon(n.type)}`}></i>
                                        </div>
                                        <div className="flex-1">
                                            <h4 className="text-sm font-bold text-slate-800 mb-1">{n.title}</h4>
                                            <p className="text-xs text-slate-500 leading-relaxed mb-2">{n.message}</p>
                                            <div className="flex justify-between items-center">
                                                <span className="text-[10px] text-slate-400">
                                                    {new Date(n.created_at).toLocaleDateString()}
                                                </span>
                                                <button
                                                    onClick={() => markRead(n.id)}
                                                    className="text-[10px] font-bold text-slate-300 hover:text-slate-500 opacity-0 group-hover:opacity-100 transition-all"
                                                >
                                                    Dismiss
                                                </button>
                                            </div>
                                            {n.link && (
                                                <a href={n.link} className="block mt-2 text-xs font-bold text-indigo-600 hover:underline">
                                                    View Details <i className="fa-solid fa-arrow-right ml-1"></i>
                                                </a>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
};

export default NotificationCenter;
