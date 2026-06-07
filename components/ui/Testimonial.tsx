import React from 'react';

export interface TestimonialData {
    id: string;
    quote: string;
    name: string;
    rating: number;
    result_metric?: string;
}

interface TestimonialProps {
    data: TestimonialData;
    variant?: 'card' | 'minimal';
}

const Testimonial: React.FC<TestimonialProps> = ({ data, variant = 'card' }) => {
    if (variant === 'minimal') {
        return (
            <div className="border-l-4 border-indigo-200 pl-4 py-2 italic text-slate-600 my-4">
                "{data.quote}"
                <div className="mt-2 text-xs font-bold text-slate-900 not-italic">— {data.name}</div>
            </div>
        );
    }

    return (
        <div className="bg-white p-6 rounded-2xl shadow-lg border border-slate-100 flex flex-col h-full relative overflow-hidden group hover:shadow-xl transition-shadow duration-300">
            <div className="absolute top-0 right-0 p-4 opacity-10 text-indigo-200">
                <i className="fa-solid fa-quote-right text-6xl"></i>
            </div>

            <div className="flex gap-1 mb-4 text-amber-400 text-xs">
                {[...Array(5)].map((_, i) => (
                    <i key={i} className={`fa-solid fa-star ${i < data.rating ? '' : 'text-slate-200'}`}></i>
                ))}
            </div>

            <p className="text-slate-600 italic mb-6 leading-relaxed flex-grow relative z-10">"{data.quote}"</p>

            <div className="mt-auto flex items-center justify-between pt-4 border-t border-slate-50">
                <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-100 to-purple-100 flex items-center justify-center text-xs font-bold text-indigo-700">
                        {data.name.charAt(0)}
                    </div>
                    <span className="font-bold text-sm text-slate-900">{data.name}</span>
                </div>

                {data.result_metric && (
                    <span className="bg-emerald-50 text-emerald-700 px-2 py-1 rounded-md text-[10px] font-bold uppercase tracking-wide">
                        {data.result_metric}
                    </span>
                )}
            </div>
        </div>
    );
};

export default Testimonial;
