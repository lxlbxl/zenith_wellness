
import React, { useState } from 'react';
import { User, CyclePhase } from '../types';
import RoutineHub from './RoutineHub';
import HabitTracker from './HabitTracker';

interface RoutinesAndHabitsProps {
    user: User;
    currentCyclePhase?: CyclePhase;
}

const RoutinesAndHabits: React.FC<RoutinesAndHabitsProps> = ({ user, currentCyclePhase }) => {
    const [activeSection, setActiveSection] = useState<'routines' | 'habits'>('routines');

    return (
        <div className="space-y-8 animate-in fade-in duration-500">
            {/* Header Area */}
            <div className="flex flex-col md:flex-row justify-between items-end gap-4">
                <div>
                    <h1 className="text-3xl font-serif text-stone-900">Daily Rituals</h1>
                    <p className="text-stone-500 mt-1">Consistency is the key to transformation.</p>
                </div>

                {/* Toggle */}
                <div className="flex bg-stone-100 p-1.5 rounded-xl">
                    <button
                        onClick={() => setActiveSection('routines')}
                        className={`px-6 py-2.5 rounded-lg text-sm font-bold transition-all flex items-center gap-2 ${activeSection === 'routines'
                            ? 'bg-white text-stone-900 shadow-sm'
                            : 'text-stone-500 hover:text-stone-700'
                            }`}
                    >
                        <i className="fa-solid fa-list-check"></i>
                        Routines
                    </button>
                    <button
                        onClick={() => setActiveSection('habits')}
                        className={`px-6 py-2.5 rounded-lg text-sm font-bold transition-all flex items-center gap-2 ${activeSection === 'habits'
                            ? 'bg-white text-stone-900 shadow-sm'
                            : 'text-stone-500 hover:text-stone-700'
                            }`}
                    >
                        <i className="fa-solid fa-fire"></i>
                        Habits
                    </button>
                </div>
            </div>

            {/* Content */}
            <div className="min-h-[500px]">
                {activeSection === 'routines' ? (
                    <div className="animate-in fade-in slide-in-from-left-4 duration-500">
                        <RoutineHub user={user} currentCyclePhase={currentCyclePhase} />
                    </div>
                ) : (
                    <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                        <HabitTracker user={user} />
                    </div>
                )}
            </div>
        </div>
    );
};

export default RoutinesAndHabits;

