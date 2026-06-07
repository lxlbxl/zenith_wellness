
import React from 'react';
import { AppTab, Program, UserRole } from '../types';

interface SidebarProps {
  activeTab: AppTab;
  setActiveTab: (tab: AppTab) => void;
  purchasedIds: string[];
  programs: Program[];
  onLogout: () => void;
  userName: string;
  userRole?: UserRole;
  userPersona: string;
}

const Sidebar: React.FC<SidebarProps> = ({ activeTab, setActiveTab, purchasedIds, programs, onLogout, userName, userRole, userPersona }) => {
  const mainNav = [
    { id: AppTab.DASHBOARD, label: 'Dashboard', icon: 'fa-chart-pie' },
    { id: AppTab.ROUTINES, label: 'Routines', icon: 'fa-list-check' },
    { id: AppTab.GOALS, label: 'Goals', icon: 'fa-bullseye' },
    { id: AppTab.JOURNAL, label: 'Journal', icon: 'fa-book-open' },
    { id: AppTab.WELLNESS, label: 'Wellness', icon: 'fa-heart' },
    { id: AppTab.CYCLE_TRACKER, label: 'Cycle', icon: 'fa-droplet' },
    { id: AppTab.CHALLENGES, label: 'Hub', icon: 'fa-layer-group' },
    { id: AppTab.GAMIFICATION, label: 'Trophies', icon: 'fa-trophy' },
    { id: AppTab.MEAL_TRACKER, label: 'Meals', icon: 'fa-utensils' },
    { id: AppTab.COACH, label: 'Zenith IQ', icon: 'fa-brain' },
    { id: AppTab.RESOURCES, label: 'Library', icon: 'fa-bookmark' },
  ];

  if (userRole === 'admin') {
    mainNav.push({ id: AppTab.ADMIN, label: 'Admin', icon: 'fa-lock' });
  }

  return (
    <nav className="h-full flex flex-col glass-dark rounded-r-3xl border-r border-white/10 relative overflow-hidden">
      {/* Decorative gradient overlay */}
      <div className="absolute top-0 left-0 w-full h-full bg-gradient-to-b from-brand-900/10 to-stone-900/90 pointer-events-none z-0"></div>

      <div className="p-8 flex items-center gap-3 relative z-10">
        <div className="w-10 h-10 bg-brand-500 rounded-xl flex items-center justify-center text-white shadow-lg shadow-brand-900/20">
          <i className="fa-solid fa-leaf text-lg"></i>
        </div>
        <span className="font-serif font-bold text-2xl tracking-tight text-stone-800 dark:text-stone-100">Zenith</span>
      </div>

      <div className="flex-1 px-4 space-y-1 overflow-y-auto mt-2 relative z-10 scrollbar-hide">
        <div className="text-[10px] font-bold uppercase text-stone-400 px-4 mb-2 tracking-widest opacity-70">Menu</div>
        {mainNav.map((item) => {
          const isSmartNavItem = item.id === AppTab.COACH;
          const isFree = userPersona === 'lead' || userPersona === 'newbie';
          return (
            <button
              key={item.id}
              onClick={() => setActiveTab(item.id)}
              className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 group relative ${activeTab === item.id
                ? 'bg-brand-500 text-white shadow-md shadow-brand-500/20'
                : 'text-stone-400 hover:bg-white/5 hover:text-brand-300'
                }`}
            >
              <i className={`fa-solid ${item.icon} w-5 text-center transition-transform group-hover:scale-110 ${activeTab === item.id ? 'opacity-100' : 'opacity-70'}`}></i>
              <span className="font-medium text-sm tracking-wide">{item.label}</span>
              {isSmartNavItem && isFree && (
                <span className="absolute right-3 top-1/2 -translate-y-1/2 w-2 h-2 bg-brand-400 rounded-full animate-pulse shadow-[0_0_8px_rgba(168,162,158,0.5)]"></span>
              )}
            </button>
          );
        })}

        <div className="mt-8 pt-6 border-t border-white/5">
          <div className="text-[10px] font-bold uppercase text-stone-400 px-4 mb-3 tracking-widest opacity-70">My Tracks</div>
          <div className="space-y-1">
            {programs.map(p => {
              const isLocked = !purchasedIds.includes(p.id);
              if (isLocked) return null;
              return (
                <button
                  key={p.id}
                  onClick={() => setActiveTab(AppTab.CHALLENGES)}
                  className="w-full flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-white/5 transition-all group"
                >
                  <div className="w-6 h-6 rounded-md bg-brand-500/10 text-brand-400 flex items-center justify-center text-[10px]">
                    <i className="fa-solid fa-check"></i>
                  </div>
                  <span className="text-sm text-stone-400 group-hover:text-brand-200 truncate font-light">{p.title}</span>
                </button>
              );
            })}
          </div>
        </div>
      </div>

      <div className="px-4 mb-4 relative z-10">
        {/* Search Hint */}
        <div className="flex items-center justify-between px-4 py-2 bg-white/5 rounded-xl border border-white/5 text-[10px] font-bold uppercase tracking-widest text-stone-500 mb-6">
          <span>Quick Find</span>
          <span className="flex items-center gap-1 opacity-60">
            <i className="fa-solid fa-command"></i>
            K
          </span>
        </div>

        {/* Upgrade Card */}
        {(userPersona === 'lead' || userPersona === 'newbie') && (
          <div className="p-5 rounded-[2rem] bg-gradient-to-br from-brand-600 to-indigo-700 text-white shadow-xl shadow-brand-900/40 relative overflow-hidden group mb-4">
            <div className="absolute -right-4 -top-4 w-24 h-24 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform duration-700"></div>
            <div className="relative z-10">
              <div className="flex items-center gap-2 mb-2">
                <i className="fa-solid fa-star text-amber-300 text-xs animate-pulse"></i>
                <span className="text-[10px] font-black uppercase tracking-widest">Premium Plan</span>
              </div>
              <p className="font-bold text-sm mb-1">Unlock All Protocols</p>
              <p className="text-[10px] text-brand-200 mb-4 opacity-80">Unlimited Smart Coach & History</p>
              <button
                onClick={() => setActiveTab(AppTab.CHALLENGES)}
                className="w-full py-2 bg-white text-brand-700 rounded-xl text-xs font-black shadow-lg hover:bg-brand-50 transition-colors"
              >
                Upgrade Now
              </button>
            </div>
          </div>
        )}

        {/* Strategy Call Card for Veterans */}
        {(userPersona === 'veteran' || userPersona === 'active') && (
          <div className="p-5 rounded-[2rem] bg-gradient-to-br from-emerald-600 to-teal-700 text-white shadow-xl shadow-emerald-900/40 relative overflow-hidden group mb-4">
            <div className="absolute -right-4 -top-4 w-24 h-24 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform duration-700"></div>
            <div className="relative z-10">
              <div className="flex items-center gap-2 mb-2">
                <i className="fa-solid fa-phone text-emerald-300 text-xs anim-pulse"></i>
                <span className="text-[10px] font-black uppercase tracking-widest text-emerald-100">LTV VIP Offer</span>
              </div>
              <p className="font-bold text-sm mb-1">Strategy Audit</p>
              <p className="text-[10px] text-emerald-200 mb-4 opacity-80">Book your 2026 planning call.</p>
              <button
                className="w-full py-2 bg-white text-emerald-700 rounded-xl text-xs font-black shadow-lg hover:bg-emerald-50 transition-colors"
              >
                Book Call
              </button>
            </div>
          </div>
        )}
      </div>

      <div className="p-4 mt-auto relative z-10">
        <div className="glass p-4 rounded-2xl border border-white/10 bg-white/5 shadow-2xl">
          <div className="flex items-center gap-3 mb-4">
            <div className="relative">
              <img src="https://ui-avatars.com/api/?name=User&background=0D8ABC&color=fff" alt="Avatar" className="w-10 h-10 rounded-full border border-white/10" />
              <div className="absolute bottom-0 right-0 w-3 h-3 bg-brand-500 rounded-full border-2 border-stone-900"></div>
            </div>
            <div className="overflow-hidden">
              <p className="font-serif font-bold text-stone-200 truncate">{userName}</p>
              <p className="text-[10px] text-brand-400 uppercase tracking-wider">Member</p>
            </div>
          </div>
          <div className="flex gap-2">
            <button
              onClick={() => setActiveTab(AppTab.SETTINGS)}
              className="flex-1 py-2 rounded-lg bg-white/5 hover:bg-white/10 text-stone-300 text-xs transition-colors"
              title="Settings"
            >
              <i className="fa-solid fa-gear"></i>
            </button>
            <button
              onClick={onLogout}
              className="flex-1 py-2 rounded-lg bg-white/5 hover:bg-rose-500/20 text-stone-300 hover:text-rose-400 text-xs transition-colors"
              title="Logout"
            >
              <i className="fa-solid fa-arrow-right-from-bracket"></i>
            </button>
          </div>
        </div>
      </div>
    </nav>
  );
};

export default Sidebar;
