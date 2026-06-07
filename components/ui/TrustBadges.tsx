import React from 'react';

const TrustBadges: React.FC = () => {
    return (
        <div className="flex flex-wrap items-center justify-center gap-4 py-4 opacity-80 scale-90">
            <div className="flex items-center gap-2 text-[10px] text-slate-500 font-bold uppercase tracking-widest">
                <i className="fa-solid fa-lock text-emerald-500"></i>
                <span>256-bit SSL</span>
            </div>
            <div className="flex items-center gap-2 text-[10px] text-slate-500 font-bold uppercase tracking-widest">
                <i className="fa-solid fa-shield-halved text-indigo-500"></i>
                <span>Money-Back Guarantee</span>
            </div>
            <div className="flex items-center gap-2 text-[10px] text-slate-500 font-bold uppercase tracking-widest">
                <i className="fa-solid fa-users text-amber-500"></i>
                <span>2,400+ Active Members</span>
            </div>
        </div>
    );
};

export default TrustBadges;
