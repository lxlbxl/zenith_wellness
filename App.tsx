import React, { useState, useEffect } from 'react';
import { Routes, Route, Navigate, useNavigate, useLocation, useSearchParams } from 'react-router-dom';
import ErrorBoundary from './components/ErrorBoundary';
import { AppTab, UserStats, Mood, DailyGoals, Program, UserPersona, User } from './types';
import Layout from './components/Layout';
import Dashboard from './components/Dashboard';
import CoachPanel from './components/CoachPanel';
import ChallengeHub from './components/ChallengeHub';
import MealTracker from './components/MealTracker';
import Login from './components/Login';
import CohortLounge from './components/CohortLounge';
import CycleTracker from './components/CycleTracker';
import Resources from './components/Resources';
import AdminPanel from './components/AdminPanel';
import ProfileSettings from './components/ProfileSettings';
import RoutinesAndHabits from './components/RoutinesAndHabits';
import GoalManager from './components/GoalManager';
import Journal from './components/Journal';
import WellnessLogger from './components/WellnessLogger';
import GamificationHub from './components/GamificationHub';
import NotificationCenter from './components/NotificationCenter';
import MobileMenu from './components/MobileMenu';
import AIChatWidget from './components/ui/AIChatWidget';
import XPOverlay from './components/ui/XPOverlay';
import { ProtectedRoute } from './ProtectedRoute';
import { useAuth } from './AuthContext';
import { db } from './services/db';
import { api } from './services/api';
import SalesPageTrust from './components/sales/SalesPageTrust';
import SalesPageBioSync from './components/sales/SalesPageBioSync';
import SalesPageCohort from './components/sales/SalesPageCohort';
import SalesPageExecutive from './components/sales/SalesPageExecutive';
import SalesPageAesthetic from './components/sales/SalesPageAesthetic';
import SalesPagePCOS from './components/sales/SalesPagePCOS';
import QuizFunnel from './components/funnels/QuizFunnel';
import ChallengeLanding from './components/funnels/ChallengeLanding';
import SignupFlow from './components/funnels/SignupFlow';
import { analytics } from './src/analytics';
import { ExperimentProvider } from './src/experiments/ExperimentProvider';

const FALLBACK_PROGRAMS: Program[] = [
  {
    id: 'prod_1', title: 'Productivity Power', description: 'Master deep work protocols.',
    startDate: '2023-01-01', category: 'productivity',
    image: 'https://picsum.photos/seed/prod/400/300',
    objectives: ['Deep Work Flow', 'Email Management', 'Peak Cognitive Window'],
    price: 8900, duration: 21
  },
  {
    id: 'weight_1', title: 'Metabolic Reset', description: 'Next cohort starts soon.',
    startDate: new Date(Date.now() + 1036800000).toISOString(), category: 'wellness',
    image: 'https://picsum.photos/seed/weight/400/300',
    objectives: ['Basal Metabolic Rate Optimization', 'Muscle Preservation', 'Fat Oxidation'],
    price: 8900, duration: 21
  },
];

const transformCohortToProgram = (cohort: any): Program => ({
  id: cohort.id, title: cohort.title, description: cohort.description || '',
  startDate: cohort.start_date, endDate: cohort.end_date,
  category: cohort.category || 'wellness',
  image: cohort.image_url || cohort.image || `https://picsum.photos/seed/${cohort.id}/400/300`,
  objectives: cohort.objectives ? (typeof cohort.objectives === 'string' ? JSON.parse(cohort.objectives) : cohort.objectives) : [],
  price: cohort.price || 8900, duration: cohort.duration_days || 21,
  maxParticipants: cohort.max_participants || 100,
  participantCount: cohort.participant_count || 0,
  isPublic: cohort.is_public === 1 || cohort.is_public === true
});

const DEFAULT_STATS: UserStats = {
  focusMinutes: 120, completedTasks: 8,
  moodHistory: [{ date: new Date().toISOString(), mood: 'happy' }],
  dailyStreak: 0, goals: { focusMinutes: 180, completedTasks: 12, targetMood: 'ecstatic' },
  purchasedProgramIds: [],
  macros: { protein: 45, carbs: 120, fats: 30, calories: 1250 },
  cohortProgress: {}
};

// Map URL paths to AppTab values
const PATH_TO_TAB: Record<string, AppTab> = {
  '/app/dashboard': AppTab.DASHBOARD,
  '/app/challenges': AppTab.CHALLENGES,
  '/app/meal-tracker': AppTab.MEAL_TRACKER,
  '/app/coach': AppTab.COACH,
  '/app/cohort-lounge': AppTab.COHORT_LOUNGE,
  '/app/routines': AppTab.ROUTINES,
  '/app/goals': AppTab.GOALS,
  '/app/journal': AppTab.JOURNAL,
  '/app/wellness': AppTab.WELLNESS,
  '/app/gamification': AppTab.GAMIFICATION,
  '/app/cycle-tracker': AppTab.CYCLE_TRACKER,
  '/app/menu': AppTab.MENU,
  '/app/resources': AppTab.RESOURCES,
  '/app/admin': AppTab.ADMIN,
  '/app/settings': AppTab.SETTINGS,
};

// ---- Landing / Auth Pages ----

const LandingPage: React.FC = () => {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const variant = searchParams.get('v') || 'trust';

  // Backwards compat: redirect legacy hash-based links (e.g. /#signup → /?v=signup)
  useEffect(() => {
    const hash = window.location.hash.replace('#', '');
    if (hash && ['login', 'trust', 'biosync', 'cohort', 'quiz', 'challenge', 'signup'].includes(hash)) {
      navigate(`/?v=${hash}`, { replace: true });
    }
  }, []);

  const validVariants = ['trust', 'biosync', 'cohort', 'executive', 'aesthetic', 'pcos', 'quiz', 'challenge', 'signup', 'login'];
  const safeVariant = validVariants.includes(variant) ? variant : 'trust';

  const handleLogin = (user: User) => {
    login(user);
    navigate('/app/dashboard', { replace: true });
  };

  const handleSwitchToLogin = () => navigate('/?v=login');

  switch (safeVariant) {
    case 'login':
      return <Login onLogin={handleLogin} onSwitchToSales={() => navigate('/?v=trust')} />;
    case 'quiz':
      return <QuizFunnel onComplete={(result, email) => console.log('Quiz completed:', result, email)} onLogin={handleLogin} />;
    case 'challenge':
      return <ChallengeLanding onRegister={handleLogin} onSwitchToLogin={handleSwitchToLogin} />;
    case 'signup':
      return <SignupFlow onComplete={handleLogin} onSwitchToLogin={handleSwitchToLogin} />;
    case 'biosync':
      return <SalesPageBioSync onLogin={handleLogin} onSwitchToLogin={handleSwitchToLogin} />;
    case 'cohort':
      return <SalesPageCohort onLogin={handleLogin} onSwitchToLogin={handleSwitchToLogin} />;
    case 'executive':
      return <SalesPageExecutive onLogin={handleLogin} onSwitchToLogin={handleSwitchToLogin} />;
    case 'aesthetic':
      return <SalesPageAesthetic onLogin={handleLogin} onSwitchToLogin={handleSwitchToLogin} />;
    case 'pcos':
      return <SalesPagePCOS onLogin={handleLogin} onSwitchToLogin={handleSwitchToLogin} />;
    default:
      return <SalesPageTrust onLogin={handleLogin} onSwitchToLogin={handleSwitchToLogin} />;
  }
};

// ---- App Shell (authenticated) ----

const AppShell: React.FC = () => {
  const { user, logout, updateUser } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [stats, setStats] = useState<UserStats>(DEFAULT_STATS);
  const [activeTab, setActiveTab] = useState<AppTab>(AppTab.DASHBOARD);
  const [activeCohortId, setActiveCohortId] = useState<string | null>(null);
  const [programs, setPrograms] = useState<Program[]>(FALLBACK_PROGRAMS);
  const [programsLoading, setProgramsLoading] = useState(true);

  // Sync URL path → activeTab
  useEffect(() => {
    const tab = PATH_TO_TAB[location.pathname];
    if (tab !== undefined) setActiveTab(tab);
  }, [location.pathname]);

  // Track page views
  useEffect(() => {
    analytics.pageView('app_' + activeTab);
  }, [activeTab]);

  // Persist stats
  useEffect(() => {
    if (user) db.saveStats(user.id, stats).catch(console.error);
  }, [stats, user]);

  // Load stats on mount
  useEffect(() => {
    if (user) {
      db.getStats(user.id).then(s => s && setStats(s));
    }
  }, [user?.id]);

  // Fetch cohorts
  useEffect(() => {
    const fetchCohorts = async () => {
      try {
        const response = await api.get<{ cohorts: any[] }>('/cohorts');
        if (response.cohorts?.length > 0) {
          setPrograms(response.cohorts.map(transformCohortToProgram));
        }
      } catch (err) {
        console.warn('Failed to fetch cohorts, using fallback:', err);
      } finally {
        setProgramsLoading(false);
      }
    };
    fetchCohorts();
  }, []);

  // Refetch cohorts when navigating to challenges
  useEffect(() => {
    if (activeTab === AppTab.CHALLENGES && user && !programsLoading) {
      api.get<{ cohorts: any[] }>('/cohorts').then(response => {
        if (response.cohorts?.length > 0) {
          setPrograms(response.cohorts.map(transformCohortToProgram));
        }
      }).catch(() => {});
    }
  }, [activeTab, user, programsLoading]);

  const handleLogout = () => {
    logout();
    navigate('/');
  };

  const handleMoodCheckIn = (mood: Mood) => {
    setStats(prev => ({ ...prev, moodHistory: [...prev.moodHistory, { date: new Date().toISOString(), mood }] }));
  };

  const handleUpdateGoals = (newGoals: DailyGoals) => {
    setStats(prev => ({ ...prev, goals: newGoals }));
  };

  const handleMealLogged = (macros: any) => {
    setStats(prev => ({
      ...prev,
      macros: {
        protein: prev.macros.protein + macros.protein,
        carbs: prev.macros.carbs + macros.carbs,
        fats: prev.macros.fats + macros.fats,
        calories: prev.macros.calories + macros.calories,
      }
    }));
  };

  const handleTogglePersona = (p: UserPersona) => {
    let purchased = ['prod_1'];
    if (p === 'lead') purchased = [];
    if (p === 'newbie') purchased = ['weight_1'];
    if (p === 'active') purchased = ['prod_1'];
    if (p === 'veteran') purchased = ['prod_1', 'skin_1', 'pcos_1'];
    db.updateUserPersona(user!.email, p);
    const updated = db.getSession();
    if (updated) { updateUser(updated); setStats(prev => ({ ...prev, purchasedProgramIds: purchased })); }
  };

  const handleOpenCohort = (id: string) => {
    setActiveCohortId(id);
    setActiveTab(AppTab.COHORT_LOUNGE);
    navigate('/app/cohort-lounge');
  };

  const handleSetActiveTab = (tab: AppTab) => {
    setActiveTab(tab);
    const path = Object.entries(PATH_TO_TAB).find(([, v]) => v === tab)?.[0];
    if (path) navigate(path);
  };

  if (!user) return null;

  return (
    <Layout activeTab={activeTab} setActiveTab={handleSetActiveTab} user={user} stats={stats} programs={programs} onLogout={handleLogout}>
      <ErrorBoundary>
        <div className="absolute top-4 right-4 z-40 md:top-8 md:right-8">
          <NotificationCenter user={user} />
        </div>

        <div className="space-y-8 pb-20">
          {activeTab !== AppTab.COHORT_LOUNGE && user.role === 'admin' && (
            <div className="flex items-center gap-2 bg-amber-50 p-2 rounded-2xl border border-amber-200 shadow-sm w-fit mb-4 mx-auto md:mx-0">
              <span className="text-[9px] font-black uppercase text-amber-600 px-2 tracking-widest">
                <i className="fa-solid fa-flask mr-1"></i>Debug Mode:
              </span>
              {(['lead', 'newbie', 'active', 'veteran'] as UserPersona[]).map(p => (
                <button key={p} onClick={() => handleTogglePersona(p)}
                  className={`px-3 py-1 rounded-xl text-[10px] font-bold uppercase transition-all ${user.persona === p ? 'bg-amber-600 text-white shadow-md' : 'text-amber-700 hover:bg-amber-100'}`}>
                  {p}
                </button>
              ))}
            </div>
          )}

          <div className="animate-in fade-in duration-500">
            {activeTab === AppTab.DASHBOARD && (
              <Dashboard user={user} stats={{ ...stats, persona: user.persona }}
                onMoodCheckIn={handleMoodCheckIn} onUpdateGoals={handleUpdateGoals}
                onNavigate={handleSetActiveTab} onOpenPayment={() => handleSetActiveTab(AppTab.CHALLENGES)}
                defaultProgram={programs.find(p => p.id === 'weight_1') || programs[0]}
                programs={programs} userRole={user.role} />
            )}
            {activeTab === AppTab.CHALLENGES && (
              <ChallengeHub userId={user.id} userEmail={user.email} userName={user.name}
                programs={programs} stats={{ ...stats, persona: user.persona }}
                onOpenCohort={handleOpenCohort}
                onPurchaseSuccess={() => db.getStats(user.id).then(s => s && setStats(s))} />
            )}
            {activeTab === AppTab.MEAL_TRACKER && (
              <MealTracker userId={user.id} stats={{ ...stats, persona: user.persona }} onMealLogged={handleMealLogged} />
            )}
            {activeTab === AppTab.COACH && <CoachPanel user={user} stats={{ ...stats, persona: user.persona }} />}
            {activeTab === AppTab.COHORT_LOUNGE && activeCohortId && (
              <CohortLounge user={user} stats={stats} program={programs.find(p => p.id === activeCohortId)!}
                onClose={() => handleSetActiveTab(AppTab.CHALLENGES)} />
            )}
            {activeTab === AppTab.ROUTINES && (
              <RoutinesAndHabits user={user} currentCyclePhase={stats.cycleStats?.currentPhase} />
            )}
            {activeTab === AppTab.GOALS && <GoalManager user={user} onNavigate={handleSetActiveTab} />}
            {activeTab === AppTab.JOURNAL && <Journal user={user} />}
            {activeTab === AppTab.WELLNESS && <WellnessLogger user={user} />}
            {activeTab === AppTab.GAMIFICATION && <GamificationHub user={user} />}
            {activeTab === AppTab.CYCLE_TRACKER && <CycleTracker userId={user.id} />}
            {activeTab === AppTab.MENU && <MobileMenu onNavigate={handleSetActiveTab} userRole={user.role} />}
            {activeTab === AppTab.RESOURCES && <Resources userId={user.id} stats={{ ...stats, persona: user.persona }} />}
            {activeTab === AppTab.ADMIN && user.role === 'admin' && <AdminPanel />}
            {activeTab === AppTab.SETTINGS && (
              <ProfileSettings user={user} onClose={() => handleSetActiveTab(AppTab.DASHBOARD)}
                onLogout={handleLogout} onUserUpdate={(u) => updateUser(u)} />
            )}
          </div>
        </div>
      </ErrorBoundary>
      <AIChatWidget userId={user.id} isFreeTier={user.persona !== 'active' && user.persona !== 'veteran'}
        onUpgrade={() => handleSetActiveTab(AppTab.CHALLENGES)} />
      <XPOverlay />
    </Layout>
  );
};

// ---- Root Router ----

const App: React.FC = () => {
  const { user, isLoading } = useAuth();

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-cream-50">
        <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-brand-500"></div>
      </div>
    );
  }

  return (
    <ExperimentProvider>
      <Routes>
        {/* Public landing pages */}
        <Route path="/" element={!user ? <LandingPage /> : <Navigate to="/app/dashboard" replace />} />

        {/* Auth pages */}
        <Route path="/login" element={!user ? <Login onLogin={() => {}} onSwitchToSales={() => {}} /> : <Navigate to="/app/dashboard" replace />} />
        <Route path="/quiz" element={!user ? <QuizFunnel onComplete={() => {}} onLogin={() => {}} /> : <Navigate to="/app/dashboard" replace />} />
        <Route path="/signup" element={!user ? <SignupFlow onComplete={() => {}} onSwitchToLogin={() => {}} /> : <Navigate to="/app/dashboard" replace />} />

        {/* Protected /app/* routes */}
        <Route path="/app/*" element={<ProtectedRoute><AppShell /></ProtectedRoute>} />
      </Routes>
    </ExperimentProvider>
  );
};

export default App;