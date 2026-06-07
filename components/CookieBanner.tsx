import React, { useState, useEffect } from 'react';
import { api } from '../services/api';

const CookieBanner: React.FC = () => {
    const [isVisible, setIsVisible] = useState(false);

    useEffect(() => {
        const stored = localStorage.getItem('zenith_consent');
        if (!stored) {
            setIsVisible(true);
        }
    }, []);

    const handleAccept = async () => {
        // Save to local storage to avoid re-prompt
        localStorage.setItem('zenith_consent', 'true');
        setIsVisible(false);

        // Save to backend if user is logged in (optional check)
        try {
            await api.post('/privacy/update-consent', {
                consents: {
                    cookie_analytics: true,
                    cookie_marketing: true,
                    email_marketing: true
                }
            });
        } catch (e) {
            // fail silently if not logged in
        }
    };

    if (!isVisible) return null;

    return (
        <div className="fixed bottom-0 left-0 right-0 bg-slate-900 border-t border-slate-800 p-6 z-50 animate-in slide-in-from-bottom duration-500">
            <div className="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-6">
                <div className="flex items-start gap-4">
                    <div className="w-10 h-10 bg-indigo-500/20 text-indigo-400 rounded-xl flex items-center justify-center shrink-0">
                        <i className="fa-solid fa-cookie-bite text-xl"></i>
                    </div>
                    <div>
                        <h3 className="text-white font-bold mb-1">We value your privacy</h3>
                        <p className="text-slate-400 text-sm leading-relaxed max-w-xl">
                            We use cookies to enhance your experience, analyze site traffic, and deliver personalized content.
                            Your data remains yours.
                        </p>
                    </div>
                </div>
                <div className="flex gap-4">
                    <button
                        onClick={() => setIsVisible(false)}
                        className="text-slate-400 text-sm font-bold hover:text-white transition-colors"
                    >
                        Decline
                    </button>
                    <button
                        onClick={handleAccept}
                        className="bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-indigo-500 transition-colors shadow-lg shadow-indigo-500/20"
                    >
                        Accept All
                    </button>
                </div>
            </div>
        </div>
    );
};

export default CookieBanner;
