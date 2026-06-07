import React, { useEffect, useState } from 'react';

interface SpotsRemainingProps {
    cohortId?: string; // Optional, if fetching from API
    initialTotal?: number;
    initialTaken?: number;
}

const SpotsRemaining: React.FC<SpotsRemainingProps> = ({ cohortId, initialTotal = 50, initialTaken = 35 }) => {
    const [stats, setStats] = useState({ total: initialTotal, taken: initialTaken });
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (cohortId) {
            setLoading(true);
            fetch(`/api/cohorts_spots.php?id=${cohortId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        setStats({ total: data.data.total, taken: data.data.taken });
                    }
                })
                .finally(() => setLoading(false));
        }
    }, [cohortId]);

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
