import React from 'react';

interface StickyMobileCTAProps {
    onClick?: () => void;
    btnText?: string;
    price?: number; // Price in cents
    className?: string;
}

const StickyMobileCTA: React.FC<StickyMobileCTAProps> = ({
    onClick,
    btnText = 'Get Started',
    price,
    className = '',
}) => {
    if (!onClick) return null;

    return (
        <div className={`fixed bottom-0 left-0 right-0 z-40 md:hidden ${className}`}>
            <div className="bg-white border-t border-slate-200 px-4 py-3 flex items-center gap-3 shadow-[0_-4px_20px_rgba(0,0,0,0.1)]">
                <div className="flex-1">
                    {price ? (
                        <div className="flex items-baseline gap-1">
                            <span className="text-lg font-black text-slate-900">
                                ${(price / 100).toFixed(0)}
                            </span>
                            <span className="text-xs text-slate-400 line-through">
                                ${(price / 100 * 2).toFixed(0)}
                            </span>
                        </div>
                    ) : (
                        <div className="text-xs font-bold text-slate-500 uppercase tracking-wider">
                            Free to start
                        </div>
                    )}
                </div>
                <button
                    onClick={onClick}
                    className="bg-indigo-600 text-white px-6 py-3 rounded-xl font-black text-sm uppercase tracking-widest hover:bg-indigo-500 active:scale-95 transition-all shadow-lg shadow-indigo-900/30"
                >
                    {btnText}
                </button>
            </div>
        </div>
    );
};

export default StickyMobileCTA;
