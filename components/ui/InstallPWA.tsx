import React, { useState, useEffect } from 'react';

const InstallPWA: React.FC = () => {
    const [deferredPrompt, setDeferredPrompt] = useState<any>(null);
    const [isVisible, setIsVisible] = useState(false);

    useEffect(() => {
        const handler = (e: any) => {
            e.preventDefault();
            setDeferredPrompt(e);
            // Only show after a small delay to not annoy the user immediately
            setTimeout(() => setIsVisible(true), 3000);
        };

        window.addEventListener('beforeinstallprompt', handler);

        return () => window.removeEventListener('beforeinstallprompt', handler);
    }, []);

    const handleInstall = async () => {
        if (!deferredPrompt) return;

        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;

        if (outcome === 'accepted') {
            console.log('User accepted the PWA install');
        } else {
            console.log('User dismissed the PWA install');
        }

        setDeferredPrompt(null);
        setIsVisible(false);
    };

    if (!isVisible) return null;

    return (
        <div className="fixed bottom-24 left-4 right-4 md:left-auto md:right-8 md:bottom-8 md:w-80 z-[100] animate-in slide-in-from-bottom-8 duration-500">
            <div className="bg-slate-900 text-white rounded-[2.5rem] p-6 shadow-2xl border border-white/10 relative overflow-hidden group">
                {/* Decorative background element */}
                <div className="absolute top-0 right-0 w-32 h-32 bg-indigo-500/10 rounded-full -mr-16 -mt-16 blur-2xl group-hover:bg-indigo-500/20 transition-all"></div>

                <div className="relative z-10">
                    <div className="flex items-start justify-between mb-4">
                        <div className="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-xl shadow-lg">
                            <i className="fa-solid fa-mobile-screen-button"></i>
                        </div>
                        <button
                            onClick={() => setIsVisible(false)}
                            className="text-slate-400 hover:text-white transition-colors"
                        >
                            <i className="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <h3 className="text-lg font-black mb-1">Install Zenith App</h3>
                    <p className="text-slate-400 text-xs font-medium leading-relaxed mb-6">
                        Access your metabolic dashboard instantly from your home screen. Works offline.
                    </p>

                    <button
                        onClick={handleInstall}
                        className="w-full bg-white text-slate-900 py-3 rounded-2xl font-black text-sm shadow-xl hover:bg-slate-50 active:scale-95 transition-all"
                    >
                        Add to Home Screen
                    </button>

                    <p className="text-[9px] text-center text-slate-500 mt-4 font-bold uppercase tracking-widest">
                        Premium Experience • Fast Loading
                    </p>
                </div>
            </div>
        </div>
    );
};

export default InstallPWA;
