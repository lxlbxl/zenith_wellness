
import React from 'react';
import Sidebar from './Sidebar';
import BottomNav from './BottomNav';
import CookieBanner from './CookieBanner';
import CommandPalette from './ui/CommandPalette';
import InstallPWA from './ui/InstallPWA';
import { AppTab, Program, User } from '../types';

interface LayoutProps {
    children: React.ReactNode;
    activeTab: AppTab;
    setActiveTab: (tab: AppTab) => void;
    user: User;
    stats: any;
    programs: Program[];
    onLogout: () => void;
}

const Layout: React.FC<LayoutProps> = ({
    children,
    activeTab,
    setActiveTab,
    user,
    stats,
    programs,
    onLogout
}) => {
    return (
        <div className="flex h-screen bg-cream-50 text-stone-800 overflow-hidden font-sans selection:bg-brand-200">
            <CommandPalette onNavigate={setActiveTab} />

            {/* Desktop Sidebar - Hidden on mobile */}
            <div className="hidden md:block w-72 h-full relative z-20">
                <Sidebar
                    activeTab={activeTab}
                    setActiveTab={setActiveTab}
                    purchasedIds={stats.purchasedProgramIds}
                    programs={programs}
                    onLogout={onLogout}
                    userName={user.name}
                    userRole={user.role}
                    userPersona={user.persona}
                />
            </div>

            {/* Main Content Area */}
            <div className="flex-1 relative h-full overflow-hidden flex flex-col">

                {/* Mobile Top Bar (Optional, if needed for branding/menu on mobile) */}
                <div className="md:hidden flex items-center justify-between p-4 pb-2 z-10 bg-cream-50/80 backdrop-blur-sm sticky top-0">
                    <h1 className="text-xl font-bold text-stone-800 tracking-tight">Zenith</h1>
                    <div className="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-brand-700">
                        <span className="font-serif font-bold">{user.name.charAt(0)}</span>
                    </div>
                </div>

                {/* Scrollable Content */}
                <div className="flex-1 overflow-y-auto overflow-x-hidden p-4 md:p-10 pb-28 md:pb-10 relative scroll-smooth">
                    <div className="max-w-6xl mx-auto w-full">
                        {children}
                    </div>
                </div>

                {/* Mobile Bottom Nav - Fixed at bottom */}
                <div className="md:hidden fixed bottom-0 left-0 right-0 z-50">
                    <BottomNav activeTab={activeTab} setActiveTab={setActiveTab} />
                </div>
            </div>

            {/* Decorative Background Elements (Soft Orbs) */}
            <div className="fixed top-[-10%] right-[-5%] w-[500px] h-[500px] bg-brand-200/30 rounded-full blur-[100px] pointer-events-none z-0"></div>
            <div className="fixed bottom-[-10%] left-[-10%] w-[400px] h-[400px] bg-amber-100/40 rounded-full blur-[80px] pointer-events-none z-0"></div>

            <CookieBanner />
            <InstallPWA />

        </div>
    );
};

export default Layout;
