import React, { useState, useEffect } from 'react';

const XPOverlay: React.FC = () => {
    const [notifications, setNotifications] = useState<{ id: string; amount: number; reason: string }[]>([]);

    useEffect(() => {
        const handleXPGain = (e: any) => {
            const { amount, reason } = e.detail;
            const id = Math.random().toString(36).substring(7);
            setNotifications(prev => [...prev, { id, amount, reason }]);

            setTimeout(() => {
                setNotifications(prev => prev.filter(n => n.id !== id));
            }, 3000);
        };

        window.addEventListener('xp-gain', handleXPGain);
        return () => window.removeEventListener('xp-gain', handleXPGain);
    }, []);

    return (
        <div className="fixed bottom-24 left-1/2 -translate-x-1/2 z-[100] pointer-events-none flex flex-col items-center gap-2">
            {notifications.map(n => (
                <div
                    key={n.id}
                    className="animate-in slide-in-from-bottom-8 fade-in duration-500 flex items-center gap-3 bg-slate-900/90 backdrop-blur-md text-white px-5 py-3 rounded-2xl shadow-2xl border border-white/20"
                >
                    <div className="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i className="fa-solid fa-bolt text-lg anim-pulse"></i>
                    </div>
                    <div>
                        <p className="text-xl font-black text-white leading-none">+{n.amount} XP</p>
                        <p className="text-[10px] font-bold text-indigo-300 uppercase tracking-widest mt-1">{n.reason}</p>
                    </div>
                </div>
            ))}
        </div>
    );
};

export default XPOverlay;
