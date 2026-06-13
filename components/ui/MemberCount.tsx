import React, { useState, useEffect } from 'react';

interface MemberCountProps {
    initialCount?: number | null;
    label?: string;
    variant?: 'minimal' | 'badge' | 'full';
}

const MemberCount: React.FC<MemberCountProps> = ({ initialCount = null, label = "Active Members", variant = 'badge' }) => {
    const [count, setCount] = useState<number | null>(initialCount);

    useEffect(() => {
        fetch('/api/stats')
            .then(res => res.json())
            .then(data => {
                if (data.total_enrolled_users && data.total_enrolled_users > 0) {
                    setCount(data.total_enrolled_users);
                } else {
                    setCount(null);
                }
            })
            .catch(() => {
                setCount(null);
            });
    }, []);

    if (count === null || count === 0) {
        if (variant === 'minimal') {
            return <span className="font-bold tabular-nums">Join the Community</span>;
        }
        if (variant === 'badge') {
            return (
                <div className="inline-flex items-center gap-2 bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full text-xs font-bold border border-indigo-100 shadow-sm">
                    <div className="flex -space-x-1">
                        <div className="w-4 h-4 rounded-full bg-indigo-200 border-2 border-white"></div>
                        <div className="w-4 h-4 rounded-full bg-purple-200 border-2 border-white"></div>
                        <div className="w-4 h-4 rounded-full bg-emerald-200 border-2 border-white"></div>
                    </div>
                    <span>Join the Community</span>
                </div>
            );
        }
        return (
            <div className="flex flex-col items-center">
                <div className="text-3xl font-black text-slate-900 tracking-tight">Join the Community</div>
                <div className="text-sm text-slate-500 font-medium uppercase tracking-widest">{label}</div>
            </div>
        );
    }

    const formatted = count.toLocaleString();

    if (variant === 'minimal') {
        return <span className="font-bold tabular-nums">{formatted}</span>;
    }

    if (variant === 'badge') {
        return (
            <div className="inline-flex items-center gap-2 bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full text-xs font-bold border border-indigo-100 shadow-sm">
                <div className="flex -space-x-1">
                    <div className="w-4 h-4 rounded-full bg-indigo-200 border-2 border-white"></div>
                    <div className="w-4 h-4 rounded-full bg-purple-200 border-2 border-white"></div>
                    <div className="w-4 h-4 rounded-full bg-emerald-200 border-2 border-white"></div>
                </div>
                <span>{formatted} {label}</span>
            </div>
        );
    }

    return (
        <div className="flex flex-col items-center">
            <div className="text-3xl font-black text-slate-900 tracking-tight">{formatted}</div>
            <div className="text-sm text-slate-500 font-medium uppercase tracking-widest">{label}</div>
        </div>
    );
};

export default MemberCount;
