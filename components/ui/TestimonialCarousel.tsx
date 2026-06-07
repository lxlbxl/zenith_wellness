import React, { useState, useEffect } from 'react';
import Testimonial, { TestimonialData } from './Testimonial';
import { api } from '../../services/api';

const TestimonialCarousel: React.FC = () => {
    const [testimonials, setTestimonials] = useState<TestimonialData[]>([]);
    const [activeIndex, setActiveIndex] = useState(0);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchTestimonials = async () => {
            try {
                const res = await api.get<TestimonialData[] | { data: TestimonialData[] }>('/testimonials.php');
                const data = Array.isArray(res) ? res : (res as any).data;
                if (Array.isArray(data)) {
                    setTestimonials(data);
                }
            } catch (err) {
                console.error("Failed to load testimonials", err);
            } finally {
                setLoading(false);
            }
        };
        fetchTestimonials();
    }, []);

    const next = () => {
        setActiveIndex((current) => (current + 1) % testimonials.length);
    };

    const prev = () => {
        setActiveIndex((current) => (current - 1 + testimonials.length) % testimonials.length);
    };

    if (loading) return <div className="h-64 bg-slate-50 rounded-2xl animate-pulse"></div>;
    if (testimonials.length === 0) return null;

    // For larger screens, show grid of 3. For mobile, show 1.
    // We'll stick to a simple responsive carousel logic:
    // Mobile: Swipe-like control. Desktop: Grid or Carousel?
    // Let's implement a single-item carousel for simplicity and impact.

    const current = testimonials[activeIndex];

    return (
        <div className="relative max-w-2xl mx-auto py-8">
            <div className="absolute top-1/2 -left-12 -translate-y-1/2 hidden md:block">
                <button onClick={prev} className="w-10 h-10 rounded-full bg-white shadow-lg border border-slate-100 text-slate-400 hover:text-indigo-600 hover:scale-110 transition-all flex items-center justify-center">
                    <i className="fa-solid fa-chevron-left"></i>
                </button>
            </div>

            <div className="overflow-hidden p-4">
                <div className="animate-in fade-in slide-in-from-right-8 duration-500" key={activeIndex}>
                    <Testimonial data={current} />
                </div>
            </div>

            <div className="absolute top-1/2 -right-12 -translate-y-1/2 hidden md:block">
                <button onClick={next} className="w-10 h-10 rounded-full bg-white shadow-lg border border-slate-100 text-slate-400 hover:text-indigo-600 hover:scale-110 transition-all flex items-center justify-center">
                    <i className="fa-solid fa-chevron-right"></i>
                </button>
            </div>

            <div className="flex justify-center gap-2 mt-4">
                {testimonials.map((_, idx) => (
                    <button
                        key={idx}
                        onClick={() => setActiveIndex(idx)}
                        className={`w-2 h-2 rounded-full transition-all duration-300 ${idx === activeIndex ? 'bg-indigo-600 w-4' : 'bg-slate-300'}`}
                    />
                ))}
            </div>
        </div>
    );
};

export default TestimonialCarousel;
