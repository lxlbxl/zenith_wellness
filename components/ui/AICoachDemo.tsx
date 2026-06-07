import React, { useState, useEffect } from 'react';

interface Message {
    id: string;
    text: string;
    sender: 'coach' | 'user';
}

const AICoachDemo: React.FC<{ userName: string }> = ({ userName }) => {
    const [messages, setMessages] = useState<Message[]>([]);
    const [isTyping, setIsTyping] = useState(false);

    useEffect(() => {
        let isMounted = true;
        const demoFlow = async () => {
            setMessages([]); // Reset messages on start

            // Initial message
            await sleep(1500);
            if (isMounted) addMessage({ id: '1', text: `Hi ${userName}! I've analyzed your initial profile.`, sender: 'coach' });

            await sleep(2000);
            if (isMounted) setIsTyping(true);
            await sleep(2500);
            if (isMounted) setIsTyping(false);
            if (isMounted) addMessage({ id: '2', text: `You're showing signs of metabolic stress, which explains the afternoon fatigue you mentioned.`, sender: 'coach' });

            await sleep(2000);
            if (isMounted) setIsTyping(true);
            await sleep(3000);
            if (isMounted) setIsTyping(false);
            if (isMounted) addMessage({ id: '3', text: `Would you like me to optimize your morning routine for better glucose stability?`, sender: 'coach' });
        };

        demoFlow();
        return () => { isMounted = false; };
    }, [userName]);

    const addMessage = (m: Message) => {
        // Prevent duplicate IDs
        setMessages(prev => {
            if (prev.some(msg => msg.id === m.id)) return prev;
            return [...prev, m];
        });
    };

    const sleep = (ms: number) => new Promise(res => setTimeout(res, ms));

    return (
        <div className="flex flex-col gap-4 max-w-md mx-auto h-[300px] overflow-hidden p-2 relative">
            <div className="absolute top-2 right-2 z-10">
                <span className="px-2.5 py-1 bg-slate-900/80 text-white text-[9px] font-black uppercase tracking-widest rounded-full backdrop-blur-sm">
                    Preview
                </span>
            </div>
            <div className="flex-grow space-y-3 overflow-y-auto pr-2 custom-scrollbar">
                {messages.map(m => (
                    <div key={m.id} className={`flex ${m.sender === 'coach' ? 'justify-start' : 'justify-end'}`}>
                        <div className={`max-w-[85%] rounded-2xl px-4 py-3 text-sm shadow-sm ${m.sender === 'coach'
                            ? 'bg-white border border-slate-100 text-slate-700 rounded-tl-none'
                            : 'bg-indigo-600 text-white rounded-tr-none'
                            }`}>
                            {m.text}
                        </div>
                    </div>
                ))}
                {isTyping && (
                    <div className="flex justify-start">
                        <div className="bg-white border border-slate-100 rounded-2xl rounded-tl-none px-4 py-3 shadow-sm">
                            <div className="flex gap-1">
                                <span className="w-1.5 h-1.5 bg-slate-300 rounded-full animate-bounce"></span>
                                <span className="w-1.5 h-1.5 bg-slate-300 rounded-full animate-bounce [animation-delay:0.2s]"></span>
                                <span className="w-1.5 h-1.5 bg-slate-300 rounded-full animate-bounce [animation-delay:0.4s]"></span>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default AICoachDemo;
