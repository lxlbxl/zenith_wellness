import React, { useState, useEffect } from 'react';
import { UserStats, RecommendedResource } from '../types';
import { getSmartRecommendations, generateWorkshopCurriculum } from '../services/geminiService';
import Skeleton from './Skeleton';

interface ResourcesProps {
  userId: string;
  stats: UserStats;
}

interface ExtendedResource extends RecommendedResource {
  duration?: string;
}

const Resources: React.FC<ResourcesProps> = ({ userId, stats }) => {
  const [items, setItems] = useState<ExtendedResource[]>([]);
  const [loading, setLoading] = useState(true);
  const [aiError, setAiError] = useState<string | null>(null);
  const [selectedResource, setSelectedResource] = useState<ExtendedResource | null>(null);
  const [showBookingModal, setShowBookingModal] = useState(false);
  const [showCurriculumModal, setShowCurriculumModal] = useState(false);
  const [curriculum, setCurriculum] = useState<any>(null);
  const [loadingCurriculum, setLoadingCurriculum] = useState(false);

  const fetchRecs = async () => {
    setLoading(true);
    setAiError(null);
    try {
      const data = await getSmartRecommendations(userId, stats);
      setItems(data);
    } catch (err: any) {
      console.error(err);
      setAiError(err?.message || "Could not load personalized recommendations.");
      // Fallback data so the page isn't empty
      setItems([
        { title: "Mastering Deep Work", type: "article", description: "Learn how to enter the flow state faster.", link: "#", duration: "6 min read" },
        { title: "Box Breathing Technique", type: "exercise", description: "Reduce stress in under 2 minutes.", link: "#", duration: "2 min session" },
        { title: "Morning Routine of High Achievers", type: "video", description: "Set your day up for success.", link: "#", duration: "12 min video" }
      ]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchRecs();
  }, [userId, stats.focusMinutes, stats.macros.calories, stats.moodHistory.length]);

  const getIcon = (type: string) => {
    switch (type) {
      case 'video': return 'fa-play-circle';
      case 'article': return 'fa-file-lines';
      case 'exercise': return 'fa-dumbbell';
      default: return 'fa-star';
    }
  };

  const getBadgeColor = (type: string) => {
    switch (type) {
      case 'video': return 'bg-rose-100 text-rose-600';
      case 'article': return 'bg-indigo-100 text-indigo-600';
      case 'exercise': return 'bg-emerald-100 text-emerald-600';
      default: return 'bg-slate-100 text-slate-600';
    }
  };

  return (
    <div className="space-y-8 animate-in fade-in duration-700">
      <div>
        <h2 className="text-2xl font-bold text-slate-800">Your Learning Library</h2>
        <p className="text-slate-500">Smart-curated resources tailored to your recent focus patterns and mood shifts.</p>
      </div>

      {aiError && (
        <div className="bg-amber-50 border-2 border-amber-200 rounded-2xl p-4 flex items-center gap-4">
          <div className="w-10 h-10 bg-amber-100 rounded-xl flex items-center justify-center flex-shrink-0">
            <i className="fa-solid fa-robot text-amber-600"></i>
          </div>
          <div className="flex-1">
            <p className="text-sm font-bold text-amber-800">Smart Recommendations Unavailable</p>
            <p className="text-xs text-amber-600">Showing curated defaults. {aiError}</p>
          </div>
          <button
            onClick={fetchRecs}
            className="px-4 py-2 bg-amber-600 text-white rounded-xl text-xs font-bold hover:bg-amber-700 transition-all"
          >
            Retry
          </button>
        </div>
      )}

      {loading ? (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <Skeleton className="h-40 rounded-2xl mb-4" count={3} />
          <div className="space-y-2 mt-4">
            <Skeleton className="h-6 w-3/4 rounded-lg" count={3} />
            <Skeleton className="h-4 w-1/2 rounded-lg" count={3} />
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {items.map((item, idx) => (
            <div
              key={idx}
              onClick={() => setSelectedResource(item)}
              className="group bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col cursor-pointer"
            >
              <div className="h-48 bg-slate-900 relative">
                <img
                  src={`https://picsum.photos/seed/${item.title}/400/300`}
                  alt={item.title}
                  className="w-full h-full object-cover opacity-60 group-hover:opacity-80 transition-opacity"
                />
                <div className="absolute top-4 left-4">
                  <span className={`px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider ${getBadgeColor(item.type)}`}>
                    {item.type}
                  </span>
                </div>
                <div className="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                  <div className="w-12 h-12 bg-white rounded-full flex items-center justify-center text-slate-900 shadow-lg">
                    <i className="fa-solid fa-arrow-up-right-from-square"></i>
                  </div>
                </div>
              </div>
              <div className="p-6 flex-1 flex flex-col">
                <div className="flex items-center gap-2 mb-3 text-slate-400 text-[10px] font-bold uppercase tracking-wide">
                  <i className={`fa-solid ${getIcon(item.type)} text-xs`}></i>
                  <span>{item.duration || '5 MIN READ'}</span>
                </div>
                <h3 className="text-lg font-bold text-slate-800 mb-2 leading-tight group-hover:text-indigo-600 transition-colors">
                  {item.title}
                </h3>
                <p className="text-sm text-slate-500 line-clamp-2 mb-4">
                  {item.description}
                </p>
                <div className="mt-auto">
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      if (item.link && item.link !== '#') {
                        window.open(item.link, '_blank');
                      } else {
                        setSelectedResource(item);
                      }
                    }}
                    className="text-indigo-600 font-bold text-xs flex items-center gap-2 hover:gap-3 transition-all"
                  >
                    START NOW <i className="fa-solid fa-chevron-right"></i>
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Enhanced Elite Workshop Section */}
      <div className="bg-slate-900 p-8 rounded-[2.5rem] text-white flex flex-col md:flex-row items-center gap-8 relative overflow-hidden">
        {/* Background blobs for aesthetics */}
        <div className="absolute top-0 right-0 w-64 h-64 bg-indigo-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 -mr-32 -mt-32"></div>
        <div className="absolute bottom-0 left-0 w-64 h-64 bg-emerald-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 -ml-32 -mb-32"></div>

        <div className="relative z-10 flex-1">
          <div className="inline-flex items-center gap-2 bg-indigo-500/20 text-indigo-300 px-3 py-1 rounded-full text-xs font-bold mb-6 border border-indigo-500/30">
            <i className="fa-solid fa-calendar-star"></i> LIVE NEXT TUESDAY
          </div>
          <h3 className="text-3xl font-bold mb-4">Zenith Elite: Peak Performance Workshop</h3>
          <p className="text-slate-400 max-w-lg mb-8 leading-relaxed">
            Join world-renowned psychologists and productivity experts for a live session on building sustainable high-performance habits. Exclusively for Pro members.
          </p>
          <div className="flex flex-wrap gap-4">
            <button
              onClick={() => setShowBookingModal(true)}
              className="px-8 py-3 bg-indigo-600 hover:bg-indigo-700 rounded-2xl font-bold transition-all shadow-lg shadow-indigo-600/20"
            >
              Reserve Seat
            </button>
            <button
              onClick={async () => {
                setShowCurriculumModal(true);
                if (!curriculum) {
                  setLoadingCurriculum(true);
                  const data = await generateWorkshopCurriculum(userId, 'Zenith Elite: Peak Performance Workshop');
                  setCurriculum(data);
                  setLoadingCurriculum(false);
                }
              }}
              className="px-8 py-3 bg-white/10 hover:bg-white/20 rounded-2xl font-bold transition-all border border-white/10"
            >
              View Curriculum
            </button>
          </div>
        </div>

        <div className="relative z-10 w-full md:w-auto">
          <div className="bg-white/5 backdrop-blur-md p-6 rounded-3xl border border-white/10 flex flex-col items-center">
            <div className="flex -space-x-4 mb-4">
              {[1, 2, 3, 4].map(i => (
                <img key={i} src={`https://picsum.photos/seed/face${i}/48`} className="w-12 h-12 rounded-full border-2 border-slate-900" alt="Attendee" />
              ))}
              <div className="w-12 h-12 rounded-full bg-slate-800 border-2 border-slate-900 flex items-center justify-center text-xs font-bold">
                +140
              </div>
            </div>
            <p className="text-sm font-medium text-slate-300">Join 150+ members today</p>
          </div>
        </div>
      </div>

      {/* Resource Detail Modal */}
      {selectedResource && (
        <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4" onClick={() => setSelectedResource(null)}>
          <div className="bg-white rounded-3xl overflow-hidden max-w-xl w-full shadow-2xl" onClick={e => e.stopPropagation()}>
            <img src={`https://picsum.photos/seed/${selectedResource.title}/600/300`} className="w-full h-48 object-cover" alt={selectedResource.title} />
            <div className="p-8">
              <span className={`px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider ${getBadgeColor(selectedResource.type)}`}>
                {selectedResource.type}
              </span>
              <h3 className="text-2xl font-black text-slate-900 mt-4 mb-2">{selectedResource.title}</h3>
              <p className="text-slate-500 mb-6">{selectedResource.description}</p>
              <div className="flex gap-4">
                <button
                  onClick={() => selectedResource.link && window.open(selectedResource.link, '_blank')}
                  className="flex-1 py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700"
                >
                  Start Learning
                </button>
                <button onClick={() => setSelectedResource(null)} className="px-6 py-3 bg-slate-100 rounded-xl font-bold text-slate-600 hover:bg-slate-200">
                  Close
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Booking Modal */}
      {showBookingModal && (
        <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4" onClick={() => setShowBookingModal(false)}>
          <div className="bg-white rounded-3xl overflow-hidden max-w-md w-full shadow-2xl p-8" onClick={e => e.stopPropagation()}>
            <h3 className="text-2xl font-black text-slate-900 mb-4">Reserve Your Seat</h3>
            <p className="text-slate-500 mb-6">Enter your email to reserve your spot for the Peak Performance Workshop.</p>
            <input type="email" placeholder="your@email.com" className="w-full px-4 py-3 border border-slate-200 rounded-xl mb-4 focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
            <button className="w-full py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700">
              Confirm Reservation
            </button>
            <button onClick={() => setShowBookingModal(false)} className="w-full mt-3 py-3 text-slate-500 font-bold hover:text-slate-700">
              Cancel
            </button>
          </div>
        </div>
      )}

      {/* Curriculum Modal */}
      {showCurriculumModal && (
        <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4" onClick={() => setShowCurriculumModal(false)}>
          <div className="bg-white rounded-3xl overflow-hidden max-w-2xl w-full shadow-2xl max-h-[80vh] overflow-y-auto" onClick={e => e.stopPropagation()}>
            <div className="p-8">
              <h3 className="text-2xl font-black text-slate-900 mb-2">{curriculum?.title || 'Workshop Curriculum'}</h3>
              <p className="text-slate-400 text-sm mb-6">{curriculum?.duration || 'Loading...'}</p>

              {loadingCurriculum ? (
                <div className="flex items-center justify-center py-12">
                  <div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
                </div>
              ) : curriculum ? (
                <div className="space-y-6">
                  <div>
                    <h4 className="font-bold text-slate-800 mb-2">Learning Objectives</h4>
                    <ul className="list-disc list-inside text-slate-600 space-y-1">
                      {curriculum.objectives?.map((obj: string, i: number) => <li key={i}>{obj}</li>)}
                    </ul>
                  </div>
                  <div>
                    <h4 className="font-bold text-slate-800 mb-2">Session Breakdown</h4>
                    <div className="space-y-2">
                      {curriculum.sessions?.map((s: any, i: number) => (
                        <div key={i} className="bg-slate-50 p-4 rounded-xl">
                          <div className="flex justify-between items-center">
                            <span className="font-bold text-slate-800">{s.topic}</span>
                            <span className="text-xs text-slate-400">{s.time}</span>
                          </div>
                          <p className="text-sm text-slate-500 mt-1">{s.description}</p>
                        </div>
                      ))}
                    </div>
                  </div>
                  <div>
                    <h4 className="font-bold text-slate-800 mb-2">Materials Included</h4>
                    <div className="flex flex-wrap gap-2">
                      {curriculum.materials?.map((m: string, i: number) => (
                        <span key={i} className="px-3 py-1 bg-indigo-50 text-indigo-600 rounded-full text-xs font-bold">{m}</span>
                      ))}
                    </div>
                  </div>
                </div>
              ) : null}

              <button onClick={() => setShowCurriculumModal(false)} className="w-full mt-8 py-3 bg-slate-100 rounded-xl font-bold text-slate-600 hover:bg-slate-200">
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Resources;
