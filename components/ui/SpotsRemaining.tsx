import React, { useEffect, useState } from 'react';

interface SpotsRemainingProps {
    cohortId?: string; // Optional, if fetching specific cohort
}

const SpotsRemaining: React.FC<SpotsRemainingProps> = ({ cohortId }) => {
    const [stats, setStats] = useState<{ total: number; taken: number } | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        setLoading(true);
        const params = cohortId ? `?id=${cohortId}` : '';
        fetch(`/api/cohorts_spots.php${params}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    setStats({ total: data.data.total, taken: data.data.taken });
                }
            })
            .finally(() => setLoading(false));
    }, [cohortId]);

    if (loading) {
        return (
            <div className="flex flex-col gap-1 w-full max-w-[200px] animate-pulse">
                <div className="h-3 w-24 bg-slate-200 rounded-full" />
                <div className="h-2 w-full bg-slate-100 rounded-full" />
            </div>
        );
    }

    if (!stats) return null;

    const remaining = stats.total - stats.taken;
    const percentFull = (stats.taken / stats.total) * 100;
    const isUrgent = remaining < 10;

    return (
        <div className="flex flex-col gap-1 w-full max-w-[200px]">
            <div className="flex justify-between items-center text-xs font-bold uppercase tracking-wider">
                <span className={isUrgent ? 'text-red-500 animate-pulse' : 'text-slate-500'}>
                    {isUrgent ? 'Almost Full!' : 'Spots Remaining'}
                </span>
                <span className="text-slate-700">{remaining} / {stats.total}</span>
            </div>
            <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                <div
                    className={`h-full transition-all duration-1000 ${isUrgent ? 'bg-red-500' : 'bg-green-500'}`}
                    style={{ width: `${percentFull}%` }}
                ></div>
            </div>
        </div>
    );
};

export default SpotsRemaining;
