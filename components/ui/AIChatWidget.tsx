import React, { useState, useEffect, useRef } from 'react';

interface AIChatWidgetProps {
    userId: string;
    isFreeTier: boolean;
    onUpgrade?: () => void;
}

const AIChatWidget: React.FC<AIChatWidgetProps> = ({ userId, isFreeTier, onUpgrade }) => {
    const [isOpen, setIsOpen] = useState(false);
    const [messages, setMessages] = useState<{ role: 'user' | 'ai'; content: string }[]>([
        { role: 'ai', content: "Hello! I'm Zenith IQ. How is your energy level today?" }
    ]);
    const [input, setInput] = useState('');
    const [msgCount, setMsgCount] = useState(0);

    const endRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        endRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    const handleSend = () => {
        if (!input.trim()) return;

        if (isFreeTier && msgCount >= 3) {
            setMessages(prev => [...prev, { role: 'user', content: input }]);
            setTimeout(() => {
                setMessages(prev => [
                    ...prev,
                    { role: 'ai', content: "I'd love to help with that, but you've reached your daily limit on the Free Tier. Unlock unlimited intelligent coaching with the Full Access Pass." }
                ]);
                // Trigger upgrade flow?
            }, 500);
            setInput('');
            return;
        }

        setMessages(prev => [...prev, { role: 'user', content: input }]);
        setMsgCount(c => c + 1);
        setInput('');

        // Simulate AI response
        setTimeout(() => {
            setMessages(prev => [...prev, { role: 'ai', content: "That sounds challenging. Have you tried increasing your electrolyte intake before your morning routine?" }]);
        }, 1000);
    };

    return (
        <>
            {/* Floating Button */}
            <button
                onClick={() => setIsOpen(!isOpen)}
                className={`fixed bottom-6 right-6 w-14 h-14 rounded-full shadow-2xl flex items-center justify-center z-50 transition-all duration-300 hover:scale-110 ${isOpen ? 'bg-slate-800 text-white rotate-45' : 'bg-gradient-to-br from-indigo-600 to-purple-600 text-white'}`}
            >
                <i className={`fa-solid ${isOpen ? 'fa-plus' : 'fa-brain'}`}></i>
            </button>

            {/* Chat Window */}
            {isOpen && (
                <div className="fixed bottom-24 right-6 w-80 md:w-96 bg-white rounded-2xl shadow-2xl border border-slate-100 flex flex-col overflow-hidden z-50 animate-in slide-in-from-bottom-10 h-[500px]">
                    {/* Header */}
                    <div className="bg-slate-900 p-4 flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 flex items-center justify-center shadow-lg shadow-indigo-500/20">
                            <i className="fa-solid fa-brain text-white"></i>
                        </div>
                        <div>
                            <h3 className="text-white font-bold text-sm">Zenith IQ</h3>
                            <p className="text-slate-400 text-xs">Smart Health Orchestrator</p>
                        </div>
                        {isFreeTier && (
                            <span className="ml-auto bg-slate-800 text-slate-300 text-[10px] px-2 py-1 rounded border border-slate-700">Preview</span>
                        )}
                    </div>

                    {/* Messages */}
                    <div className="flex-1 overflow-y-auto p-4 space-y-4 bg-slate-50">
                        {messages.map((m, i) => (
                            <div key={i} className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                                <div className={`max-w-[80%] rounded-2xl p-3 text-sm ${m.role === 'user' ? 'bg-indigo-600 text-white rounded-br-none' : 'bg-white text-slate-700 shadow-sm border border-slate-100 rounded-bl-none'}`}>
                                    {m.content}
                                </div>
                            </div>
                        ))}

                        {isFreeTier && msgCount >= 3 && (
                            <div className="p-4 bg-amber-50 rounded-xl border border-amber-100 text-center">
                                <p className="text-xs font-bold text-amber-800 mb-2">Daily Limit Reached</p>
                                <button onClick={onUpgrade} className="w-full bg-slate-900 text-white py-2 rounded-lg text-xs font-bold shadow-lg shadow-slate-900/10 hover:bg-slate-800 transition-colors">
                                    Unlock Unlimited Access
                                </button>
                            </div>
                        )}
                        <div ref={endRef} />
                    </div>

                    {/* Input */}
                    <div className="p-3 bg-white border-t border-slate-100">
                        <div className="flex gap-2">
                            <input
                                type="text"
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleSend()}
                                placeholder="Ask about your health..."
                                className="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                disabled={isFreeTier && msgCount >= 3}
                            />
                            <button
                                onClick={handleSend}
                                disabled={!input.trim() || (isFreeTier && msgCount >= 3)}
                                className="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                <i className="fa-solid fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
};

export default AIChatWidget;
