import React, { useState, useEffect, useRef } from 'react';

interface FocusTimerProps {
  onComplete: (minutes: number) => void;
}

const FocusTimer: React.FC<FocusTimerProps> = ({ onComplete }) => {
  const [timeLeft, setTimeLeft] = useState(25 * 60);
  const [isActive, setIsActive] = useState(false);
  const [sessionType, setSessionType] = useState<'focus' | 'break'>('focus');
  const [ambientSound, setAmbientSound] = useState<'none' | 'rain' | 'lofi'>('none');
  // Use number for browser-side timer tracking to avoid NodeJS type issues
  const timerRef = useRef<number | null>(null);

  useEffect(() => {
    if (isActive && timeLeft > 0) {
      timerRef.current = window.setInterval(() => {
        setTimeLeft(prev => prev - 1);
      }, 1000);
    } else if (timeLeft === 0) {
      handleSessionEnd();
    } else {
      if (timerRef.current) window.clearInterval(timerRef.current);
    }
    return () => { if (timerRef.current) window.clearInterval(timerRef.current); };
  }, [isActive, timeLeft]);

  const handleSessionEnd = () => {
    setIsActive(false);
    if (sessionType === 'focus') {
      onComplete(25);
      alert('Focus session complete! Time for a well-deserved break.');
      setSessionType('break');
      setTimeLeft(5 * 60);
    } else {
      setSessionType('focus');
      setTimeLeft(25 * 60);
    }
  };

  const toggleTimer = () => setIsActive(!isActive);
  const resetTimer = () => {
    setIsActive(false);
    setTimeLeft(sessionType === 'focus' ? 25 * 60 : 5 * 60);
  };

  const formatTime = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  };

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-center h-full py-12 animate-in fade-in slide-in-from-bottom-4 duration-700">
      <div className="flex flex-col items-center text-center space-y-8">
        <div className="relative w-72 h-72">
          {/* Progress Circle Visual */}
          <svg className="w-full h-full transform -rotate-90">
            <circle 
              cx="144" cy="144" r="130" 
              className="stroke-slate-100 fill-none" 
              strokeWidth="10"
            />
            <circle 
              cx="144" cy="144" r="130" 
              className={`fill-none transition-all duration-1000 ease-linear ${sessionType === 'focus' ? 'stroke-indigo-600' : 'stroke-emerald-500'}`}
              strokeWidth="10"
              strokeDasharray={2 * Math.PI * 130}
              strokeDashoffset={2 * Math.PI * 130 * (1 - timeLeft / (sessionType === 'focus' ? 25 * 60 : 5 * 60))}
              strokeLinecap="round"
            />
          </svg>
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span className="text-xs font-bold tracking-[0.3em] text-slate-400 uppercase mb-3">
              {sessionType === 'focus' ? 'Deep Work' : 'Refuel Break'}
            </span>
            <span className="text-7xl font-black text-slate-900 font-mono tracking-tighter">
              {formatTime(timeLeft)}
            </span>
          </div>
        </div>

        <div className="flex items-center gap-6">
          <button 
            onClick={toggleTimer}
            className={`w-24 h-24 rounded-[2.5rem] flex items-center justify-center shadow-xl transition-all active:scale-90 ${
              isActive 
                ? 'bg-white text-slate-800 border-2 border-slate-100' 
                : 'bg-indigo-600 text-white shadow-indigo-200 hover:bg-indigo-700'
            }`}
          >
            <i className={`fa-solid ${isActive ? 'fa-pause' : 'fa-play'} text-3xl ${!isActive ? 'ml-2' : ''}`}></i>
          </button>
          <button 
            onClick={resetTimer}
            className="w-16 h-16 bg-white border-2 border-slate-100 text-slate-400 rounded-3xl flex items-center justify-center hover:text-slate-800 transition-all hover:border-slate-200 active:scale-90"
          >
            <i className="fa-solid fa-rotate-left text-xl"></i>
          </button>
        </div>
      </div>

      <div className="space-y-8">
        <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
          <div className="flex items-center gap-3 mb-6">
            <div className="w-8 h-8 bg-indigo-50 rounded-lg flex items-center justify-center text-indigo-600">
              <i className="fa-solid fa-wind text-sm"></i>
            </div>
            <h3 className="text-xl font-bold text-slate-800">Ambient Flow</h3>
          </div>
          <div className="space-y-3">
            {[
              { id: 'none', label: 'Silence', icon: 'fa-volume-xmark', color: 'text-slate-400' },
              { id: 'rain', label: 'Rainfall', icon: 'fa-cloud-showers-heavy', color: 'text-indigo-400' },
              { id: 'lofi', label: 'Lo-Fi Beats', icon: 'fa-headphones', color: 'text-rose-400' }
            ].map((sound) => (
              <div 
                key={sound.id}
                onClick={() => setAmbientSound(sound.id as any)}
                className={`p-5 rounded-2xl border-2 cursor-pointer flex items-center justify-between transition-all ${
                  ambientSound === sound.id 
                    ? 'border-indigo-600 bg-indigo-50/50' 
                    : 'border-slate-50 hover:bg-slate-50 hover:border-slate-100'
                }`}
              >
                <div className="flex items-center gap-4">
                  <i className={`fa-solid ${sound.icon} ${sound.color}`}></i>
                  <span className={`font-bold text-sm ${ambientSound === sound.id ? 'text-indigo-900' : 'text-slate-600'}`}>
                    {sound.label}
                  </span>
                </div>
                {ambientSound === sound.id && (
                  <div className="w-6 h-6 bg-indigo-600 rounded-full flex items-center justify-center text-white text-[10px]">
                    <i className="fa-solid fa-check"></i>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        <div className="bg-slate-900 p-8 rounded-[2.5rem] flex items-start gap-5 text-white relative overflow-hidden">
          <i className="fa-solid fa-quote-right absolute top-4 right-4 text-white/5 text-6xl"></i>
          <div className="mt-1 bg-white/10 p-3 rounded-2xl text-indigo-400">
            <i className="fa-solid fa-brain-circuit"></i>
          </div>
          <div>
            <h4 className="font-bold text-lg mb-2">Cognitive Science</h4>
            <p className="text-sm text-slate-400 leading-relaxed font-medium">
              Sustained attention declines after 50 minutes. Pomodoro cycles align your workflow with natural neuro-oscillations to maximize throughput without burning out.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default FocusTimer;
