import React, { useEffect, useState } from 'react';

interface ConnectionStatusProps {
    showAlways?: boolean;
}

const ConnectionStatus: React.FC<ConnectionStatusProps> = ({ showAlways = false }) => {
    const [isOnline, setIsOnline] = useState(true);
    const [showBanner, setShowBanner] = useState(false);

    useEffect(() => {
        const handleOnline = () => {
            setIsOnline(true);
            // Show "back online" message briefly
            setShowBanner(true);
            setTimeout(() => setShowBanner(false), 3000);
        };

        const handleOffline = () => {
            setIsOnline(false);
            setShowBanner(true);
        };

        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        // Check initial state
        setIsOnline(navigator.onLine);
        if (!navigator.onLine) {
            setShowBanner(true);
        }

        return () => {
            window.removeEventListener('online', handleOnline);
            window.removeEventListener('offline', handleOffline);
        };
    }, []);

    if (!showBanner && !showAlways) return null;

    return (
        <div
            className={`fixed top-0 left-0 right-0 z-50 transition-all duration-300 ${showBanner ? 'translate-y-0' : '-translate-y-full'
                }`}
        >
            <div
                className={`py-2 px-4 text-center text-sm font-medium ${isOnline
                        ? 'bg-green-500 text-white'
                        : 'bg-amber-500 text-white'
                    }`}
            >
                {isOnline ? (
                    <span className="flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                        You're back online
                    </span>
                ) : (
                    <span className="flex items-center justify-center gap-2">
                        <svg className="w-4 h-4 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243 2.829a4.978 4.978 0 01-1.414-2.83m-1.414 5.658a9 9 0 01-2.167-9.238m7.824 2.167a1 1 0 111.414 1.414m-1.414-1.414L3 3m8.293 8.293l1.414 1.414" />
                        </svg>
                        You're offline. Some features may not work.
                    </span>
                )}
            </div>
        </div>
    );
};

export default ConnectionStatus;
