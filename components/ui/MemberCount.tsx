import React, { useState, useEffect } from 'react';

interface MemberCountProps {
    initialCount?: number;
    label?: string;
    variant?: 'minimal' | 'badge' | 'full';
}

const MemberCount: React.FC<MemberCountProps> = ({ initialCount = 2430, label = "Active Members", variant = 'badge' }) => {
    const [count, setCount] = useState(initialCount);

    useEffect(() => {
        // Simulate live increment occasionally
        const interval = setInterval(() => {
            if (Math.random() > 0.7) {
                setCount(c => c + 1);
            }
        }, 5000);
        return () => clearInterval(interval);
    }, []);

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
