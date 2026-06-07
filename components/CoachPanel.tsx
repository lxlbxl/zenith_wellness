
import React, { useState, useRef, useEffect } from 'react';
import { UserStats, ChatMessage, User } from '../types';
import { getWellnessCoaching } from '../services/geminiService';
import { db } from '../services/db';

interface CoachPanelProps {
  user: User;
  stats: UserStats;
}

const CoachPanel: React.FC<CoachPanelProps> = ({ user, stats }) => {
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [loadingTooLong, setLoadingTooLong] = useState(false);
  const scrollRef = useRef<HTMLDivElement>(null);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Load chat history from persistent storage on mount
  useEffect(() => {
    const loadHistory = async () => {
      const history = await db.getChatHistory(user.id);
      if (history.length > 0) {
        setMessages(history);
      } else {
        // Initial greeting if no history
        const greeting: ChatMessage = {
          role: 'model',
          content: `System Online. Greetings, ${user.name}. I've processed your metabolic data for today. You're currently sitting at ${stats.macros.calories}kcal. Based on your ${user.persona} profile, how is your energy level responding to the current reset block?`,
          timestamp: new Date()
        };
        setMessages([greeting]);
        db.saveChatHistory(user.id, [greeting]);
      }
    };
    loadHistory();
  }, [user.id, user.name, user.persona, stats.macros.calories]);

  // Save messages whenever they change
  useEffect(() => {
    if (messages.length > 0) {
      db.saveChatHistory(user.id, messages);
    }
  }, [messages, user.id]);

  useEffect(() => {
    if (scrollRef.current) {
      scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
    }
  }, [messages, isLoading]);

  const handleSendMessage = async (e?: React.FormEvent) => {
    e?.preventDefault();
    if (!input.trim() || isLoading) return;

    const userMessage: ChatMessage = {
      role: 'user',
      content: input,
      timestamp: new Date()
    };

    setMessages(prev => [...prev, userMessage]);
    setInput('');
    setIsLoading(true);
    setLoadingTooLong(false);
    timeoutRef.current = setTimeout(() => setLoadingTooLong(true), 8000);

    try {
      const response = await getWellnessCoaching(user, stats, messages, input);
      const coachMessage: ChatMessage = {
        role: 'model',
        content: response || "Metabolic sync failed. Please re-state your physiological feedback.",
        timestamp: new Date()
      };
      setMessages(prev => [...prev, coachMessage]);
    } catch (err: any) {
      console.error(err);
      const errorMessage: ChatMessage = {
        role: 'model',
        content: err?.message || "Connection interrupted. Take a deep breath, and let's try again.",
        timestamp: new Date()
      };
      setMessages(prev => [...prev, errorMessage]);
    } finally {
      setIsLoading(false);
      setLoadingTooLong(false);
      if (timeoutRef.current) clearTimeout(timeoutRef.current);
    }
  };

  return (
    <div className="flex flex-col h-[calc(100vh-180px)] md:h-[calc(100vh-120px)] bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden animate-in fade-in duration-500">
      <div className="p-6 border-b border-slate-50 flex items-center justify-between glass">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-white text-xl shadow-lg shadow-indigo-100">
            <i className="fa-solid fa-robot"></i>
          </div>
          <div>
            <h2 className="font-black text-slate-900 tracking-tight">ZENITH ORCHESTRATOR</h2>
            <div className="flex items-center gap-2">
              <span className="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
              <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Coaching Engine: V2.5-FLASH</span>
            </div>
          </div>
        </div>
      </div>

      <div
        ref={scrollRef}
        className="flex-1 overflow-y-auto p-8 space-y-8 bg-slate-50/20"
      >
        {messages.map((msg, idx) => (
          <div
            key={idx}
            className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
          >
            <div className={`max-w-[85%] md:max-w-[70%] flex gap-4 ${msg.role === 'user' ? 'flex-row-reverse' : 'flex-row'}`}>
              <div className={`mt-1 flex-shrink-0 w-8 h-8 rounded-xl flex items-center justify-center ${msg.role === 'user' ? 'bg-slate-900 text-white' : 'bg-indigo-50 text-indigo-600'
                }`}>
                <i className={`fa-solid ${msg.role === 'user' ? 'fa-fingerprint' : 'fa-brain-circuit'} text-xs`}></i>
              </div>
              <div className={`p-6 rounded-[2rem] shadow-sm relative ${msg.role === 'user'
                  ? 'bg-slate-900 text-white rounded-tr-none'
                  : 'bg-white text-slate-700 border border-slate-100 rounded-tl-none'
                }`}>
                <p className="text-sm leading-relaxed whitespace-pre-wrap">{msg.content}</p>
                <p className={`text-[9px] font-black uppercase mt-3 tracking-widest opacity-50 ${msg.role === 'user' ? 'text-right' : 'text-left'}`}>
                  {msg.timestamp.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                </p>
              </div>
            </div>
          </div>
        ))}
        {isLoading && (
          <div className="flex justify-start">
            <div className="bg-white p-6 rounded-[2rem] shadow-sm border border-slate-100">
              <div className="flex gap-2">
                <div className="w-2 h-2 bg-indigo-600 rounded-full animate-bounce"></div>
                <div className="w-2 h-2 bg-indigo-600 rounded-full animate-bounce [animation-delay:0.2s]"></div>
                <div className="w-2 h-2 bg-indigo-600 rounded-full animate-bounce [animation-delay:0.4s]"></div>
              </div>
              {loadingTooLong && (
                <p className="text-[10px] text-slate-400 font-bold mt-3 animate-in fade-in">Still processing your request...</p>
              )}
            </div>
          </div>
        )}
      </div>

      <form onSubmit={handleSendMessage} className="p-6 bg-white border-t border-slate-50 flex gap-4">
        <input
          type="text"
          value={input}
          onChange={(e) => setInput(e.target.value)}
          placeholder="Share metabolic feedback..."
          className="flex-1 bg-slate-50 border border-slate-100 rounded-2xl px-6 py-4 text-sm focus:outline-none focus:ring-4 focus:ring-indigo-500/10 transition-all font-medium"
        />
        <button
          disabled={isLoading || !input.trim()}
          className="w-14 h-14 bg-indigo-600 text-white rounded-2xl flex items-center justify-center hover:bg-indigo-700 disabled:opacity-50 transition-all shadow-xl shadow-indigo-100 group"
        >
          <i className="fa-solid fa-bolt-auto group-hover:scale-125 transition-transform"></i>
        </button>
      </form>
    </div>
  );
};

export default CoachPanel;
