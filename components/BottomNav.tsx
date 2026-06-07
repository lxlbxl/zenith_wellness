
import React from 'react';
import { AppTab } from '../types';

interface BottomNavProps {
  activeTab: AppTab;
  setActiveTab: (tab: AppTab) => void;
}

const BottomNav: React.FC<BottomNavProps> = ({ activeTab, setActiveTab }) => {
  const tabs = [
    { id: AppTab.DASHBOARD, icon: 'fa-house', label: 'Home' },
    { id: AppTab.ROUTINES, icon: 'fa-clipboard-list', label: 'Routines' },
    { id: AppTab.CYCLE_TRACKER, icon: 'fa-droplet', label: 'Cycle' },
    { id: AppTab.CHALLENGES, icon: 'fa-trophy', label: 'Challenges' },
    { id: AppTab.MENU, icon: 'fa-table-cells-large', label: 'Menu' },
  ];

  return (
    <div className="fixed bottom-0 left-0 right-0 p-4 z-50 pointer-events-none flex justify-center">
      <nav className="pointer-events-auto bg-white/90 backdrop-blur-xl border border-slate-200 shadow-2xl shadow-slate-200/50 rounded-[2rem] px-2 py-2 flex items-center gap-1 max-w-sm w-full mx-auto">
        {tabs.map((tab) => {
          const isActive = activeTab === tab.id;
          return (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`relative flex-1 flex flex-col items-center justify-center py-3 rounded-[1.5rem] transition-all duration-300 group ${isActive
                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-200 scale-105 -translate-y-1'
                : 'text-slate-400 hover:text-slate-600 hover:bg-slate-50'
                }`}
            >
              <i className={`fa-solid ${tab.icon} text-xl mb-0.5 transition-transform ${isActive ? 'scale-110' : 'group-hover:scale-110'}`}></i>
              {isActive && (
                <span className="text-[9px] font-bold uppercase tracking-widest animate-in fade-in slide-in-from-bottom-1 duration-300">
                  {tab.label}
                </span>
              )}
              {!isActive && (
                <span className="h-1 w-1 rounded-full bg-transparent group-hover:bg-slate-300 mt-1 transition-colors"></span>
              )}
            </button>
          );
        })}
      </nav>
    </div>
  );
};

export default BottomNav;
