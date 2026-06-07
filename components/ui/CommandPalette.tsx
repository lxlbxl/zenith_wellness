
import React, { useState, useEffect, useRef } from 'react';
import { AppTab } from '../../types';

interface CommandPaletteProps {
    onNavigate: (tab: AppTab) => void;
}

interface CommandItem {
    id: AppTab;
    label: string;
    icon: string;
    description: string;
    category: 'Main' | 'Trackers' | 'System';
}

const COMMANDS: CommandItem[] = [
    { id: AppTab.DASHBOARD, label: 'Dashboard', icon: 'fa-chart-pie', description: 'Overview of your wellness data', category: 'Main' },
    { id: AppTab.COACH, label: 'Zenith IQ', icon: 'fa-brain', description: 'Chat with your Smart health orchestrator', category: 'Main' },
    { id: AppTab.CHALLENGES, label: 'Challenge Hub', icon: 'fa-layer-group', description: 'Browse programs and cohorts', category: 'Main' },

    { id: AppTab.ROUTINES, label: 'Routines', icon: 'fa-list-check', description: 'Manage your daily habits', category: 'Trackers' },
    { id: AppTab.GOALS, label: 'Goals', icon: 'fa-bullseye', description: 'Track your personal goals', category: 'Trackers' },
    { id: AppTab.MEAL_TRACKER, label: 'Meal Tracker', icon: 'fa-utensils', description: 'Log and scan your meals', category: 'Trackers' },
    { id: AppTab.CYCLE_TRACKER, label: 'Cycle Tracker', icon: 'fa-droplet', description: 'Monitor your biological rhythm', category: 'Trackers' },
    { id: AppTab.WELLNESS, label: 'Wellness Log', icon: 'fa-heart', description: 'Log sleep, water, and energy', category: 'Trackers' },
    { id: AppTab.JOURNAL, label: 'Journal', icon: 'fa-book-open', description: 'Daily reflection and notes', category: 'Trackers' },

    { id: AppTab.GAMIFICATION, label: 'Trophies', icon: 'fa-trophy', description: 'View your awards and points', category: 'System' },
    { id: AppTab.RESOURCES, label: 'Library', icon: 'fa-bookmark', description: 'Educational articles and videos', category: 'System' },
    { id: AppTab.SETTINGS, label: 'Settings', icon: 'fa-gear', description: 'Update profile and preferences', category: 'System' },
];

const CommandPalette: React.FC<CommandPaletteProps> = ({ onNavigate }) => {
    const [isOpen, setIsOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [selectedIndex, setSelectedIndex] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                setIsOpen(true);
            }
            if (e.key === 'Escape') {
                setIsOpen(false);
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, []);

    useEffect(() => {
        if (isOpen) {
            inputRef.current?.focus();
            setSelectedIndex(0);
        }
    }, [isOpen]);

    const filteredCommands = query === ''
        ? COMMANDS
        : COMMANDS.filter(cmd =>
            cmd.label.toLowerCase().includes(query.toLowerCase()) ||
            cmd.description.toLowerCase().includes(query.toLowerCase())
        );

    const handleSelect = (id: AppTab) => {
        onNavigate(id);
        setIsOpen(false);
        setQuery('');
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setSelectedIndex(prev => (prev + 1) % filteredCommands.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setSelectedIndex(prev => (prev - 1 + filteredCommands.length) % filteredCommands.length);
        } else if (e.key === 'Enter') {
            if (filteredCommands[selectedIndex]) {
                handleSelect(filteredCommands[selectedIndex].id);
            }
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-[100] flex items-start justify-center pt-[15vh] px-4 pointer-events-none">
            <div
                className="fixed inset-0 bg-stone-900/40 backdrop-blur-[2px] pointer-events-auto"
                onClick={() => setIsOpen(false)}
            ></div>

            <div className="w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-stone-200 overflow-hidden pointer-events-auto flex flex-col animate-in zoom-in-95 duration-200">
                <div className="relative border-b border-stone-100 p-6">
                    <i className="fa-solid fa-magnifying-glass absolute left-10 top-1/2 -translate-y-1/2 text-stone-400"></i>
                    <input
                        ref={inputRef}
                        type="text"
                        placeholder="Search for a feature... (e.g. 'Goals', 'Coach')"
                        className="w-full pl-12 pr-4 py-2 border-none focus:ring-0 text-lg font-medium text-stone-800 placeholder:text-stone-300"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={handleKeyDown}
                    />
                    <div className="absolute right-10 top-1/2 -translate-y-1/2 flex items-center gap-1 group">
                        <span className="text-[10px] font-bold text-stone-400 bg-stone-100 px-1.5 py-0.5 rounded border border-stone-200">ESC</span>
                        <span className="text-[10px] text-stone-300">to close</span>
                    </div>
                </div>

                <div className="max-h-[60vh] overflow-y-auto p-2">
                    {filteredCommands.length === 0 ? (
                        <div className="p-12 text-center">
                            <i className="fa-solid fa-ghost text-4xl text-stone-200 mb-4 block"></i>
                            <p className="text-stone-500 font-medium">No features found for "{query}"</p>
                            <p className="text-stone-400 text-sm mt-1">Try searching for 'Routines' or 'Wellness'</p>
                        </div>
                    ) : (
                        <div className="space-y-1">
                            {filteredCommands.map((cmd, index) => (
                                <button
                                    key={cmd.id}
                                    className={`w-full text-left p-4 rounded-2xl flex items-center gap-4 transition-all ${index === selectedIndex ? 'bg-brand-50 shadow-sm' : 'hover:bg-stone-50'
                                        }`}
                                    onClick={() => handleSelect(cmd.id)}
                                    onMouseEnter={() => setSelectedIndex(index)}
                                >
                                    <div className={`w-10 h-10 rounded-xl flex items-center justify-center text-lg ${index === selectedIndex ? 'bg-brand-500 text-white' : 'bg-stone-100 text-stone-500'
                                        }`}>
                                        <i className={`fa-solid ${cmd.icon}`}></i>
                                    </div>
                                    <div className="flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className={`font-bold ${index === selectedIndex ? 'text-brand-900' : 'text-stone-700'}`}>
                                                {cmd.label}
                                            </span>
                                            <span className="text-[10px] font-bold uppercase tracking-wider text-stone-300 mt-0.5">
                                                {cmd.category}
                                            </span>
                                        </div>
                                        <p className={`text-xs ${index === selectedIndex ? 'text-brand-600' : 'text-stone-400'}`}>
                                            {cmd.description}
                                        </p>
                                    </div>
                                    {index === selectedIndex && (
                                        <div className="flex items-center gap-1">
                                            <span className="text-[10px] font-bold text-brand-400 bg-white px-1.5 py-0.5 rounded shadow-sm border border-brand-100">ENTER</span>
                                        </div>
                                    )}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <div className="bg-stone-50 p-4 flex items-center justify-between text-[10px] font-bold uppercase tracking-widest text-stone-400 border-t border-stone-100">
                    <div className="flex gap-4">
                        <span className="flex items-center gap-1.5">
                            <i className="fa-solid fa-arrow-up-long"></i>
                            <i className="fa-solid fa-arrow-down-long"></i>
                            Navigate
                        </span>
                        <span className="flex items-center gap-1.5">
                            <i className="fa-solid fa-turn-down fa-rotate-90"></i>
                            Select
                        </span>
                    </div>
                    <div className="flex items-center gap-1.5 opacity-50">
                        <i className="fa-solid fa-wand-magic-sparkles text-brand-400"></i>
                        Smart Accelerated Navigation
                    </div>
                </div>
            </div>
        </div>
    );
};

export default CommandPalette;
