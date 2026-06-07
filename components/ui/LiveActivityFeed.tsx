import React, { useState, useEffect } from 'react';

interface Activity {
    name: string;
    location: string;
    program: string;
    time: string;
}

const LiveActivityFeed: React.FC = () => {
    const [currentActivity, setCurrentActivity] = useState<Activity | null>(null);
    const [isVisible, setIsVisible] = useState(false);

    useEffect(() => {
        const fetchActivity = async () => {
            try {
                const res = await fetch('/api/recent_enrollments.php');
                const data = await res.json();
                if (data.success && data.data.length > 0) {
                    // Pick a random one for demo
                    const random = data.data[Math.floor(Math.random() * data.data.length)];
                    setCurrentActivity(random);
                    setIsVisible(true);

                    // Hide after 5 seconds
                    setTimeout(() => setIsVisible(false), 5000);
                }
            } catch (e) {
                console.error(e);
            }
        };

        // Initial fetch
        setTimeout(fetchActivity, 2000);

        // Run every 30 seconds
        const interval = setInterval(fetchActivity, 30000);
        return () => clearInterval(interval);
    }, []);

    if (!currentActivity) return null;

    return (
        <div className={`fixed bottom-4 left-4 z-50 transition-all duration-500 transform ${isVisible ? 'translate-y-0 opacity-100' : 'translate-y-10 opacity-0 pointer-events-none'}`}>
            <div className="bg-white/90 backdrop-blur-md border border-slate-200 shadow-xl rounded-full px-4 py-2 flex items-center gap-3">
                <div className="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-bold text-xs ring-2 ring-white">
                    {currentActivity.name.charAt(0)}
                </div>
                <div className="flex flex-col">
                    <span className="text-xs font-bold text-slate-800">
                        {currentActivity.name} from {currentActivity.location}
                    </span>
                    <span className="text-[10px] text-slate-500">
                        joined <strong>{currentActivity.program}</strong> {currentActivity.time}
                    </span>
                </div>
            </div>
        </div>
    );
};

export default LiveActivityFeed;
