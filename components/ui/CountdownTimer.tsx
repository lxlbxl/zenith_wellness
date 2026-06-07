import React, { useState, useEffect } from 'react';
import { calculateTimeRemaining } from '../../utils/countdown';

interface CountdownTimerProps {
  targetDate: Date;
  size?: 'sm' | 'md' | 'lg';
  variant?: 'inline' | 'banner' | 'card' | 'box';
  onExpire?: () => void;
}

const CountdownTimer: React.FC<CountdownTimerProps> = ({ targetDate, size = 'md', variant = 'inline', onExpire }) => {
  const [timeLeft, setTimeLeft] = useState(calculateTimeRemaining(targetDate));

  useEffect(() => {
    const timer = setInterval(() => {
      const remaining = calculateTimeRemaining(targetDate);
      setTimeLeft(remaining);
      if (remaining.total <= 0) {
        clearInterval(timer);
        if (onExpire) onExpire();
      }
    }, 1000);

    return () => clearInterval(timer);
  }, [targetDate, onExpire]);

  if (timeLeft.total <= 0) {
    return <span className="text-red-600 font-bold">Enrollment Closed</span>;
  }

  const Box = ({ value, label }: { value: number; label: string }) => (
    <div className={`flex flex-col items-center ${variant === 'banner' ? 'mx-2' : 'mx-1'}`}>
      <div className={`
        font-mono font-bold bg-slate-800 text-white rounded-md flex items-center justify-center
        ${size === 'sm' ? 'w-8 h-8 text-sm' : ''}
        ${size === 'md' ? 'w-10 h-10 text-base' : ''}
        ${size === 'lg' ? 'w-16 h-16 text-3xl' : ''}
      `}>
        {value < 10 ? `0${value}` : value}
      </div>
      {size !== 'sm' && <span className="text-[10px] uppercase mt-1 text-slate-500 font-bold tracking-wider">{label}</span>}
    </div>
  );

  return (
    <div className="flex items-center">
      <Box value={timeLeft.days} label="Days" />
      <span className="text-slate-400 font-bold mx-1">:</span>
      <Box value={timeLeft.hours} label="Hrs" />
      <span className="text-slate-400 font-bold mx-1">:</span>
      <Box value={timeLeft.minutes} label="Mins" />
      <span className="text-slate-400 font-bold mx-1">:</span>
      <Box value={timeLeft.seconds} label="Secs" />
    </div>
  );
};

export default CountdownTimer;
