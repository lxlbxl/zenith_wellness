import React from 'react';
import VideoPlayer from '../ui/VideoPlayer';

interface SalesVideoModalProps {
    isOpen: boolean;
    onClose: () => void;
    src?: string;
    poster?: string;
    duration?: string;
    title?: string;
}

const SalesVideoModal: React.FC<SalesVideoModalProps> = ({
    isOpen,
    onClose,
    src,
    poster,
    duration,
    title = 'Walkthrough',
}) => {
    if (!isOpen) return null;

    return (
        <div
            className="fixed inset-0 z-50 bg-black/90 backdrop-blur-sm flex items-center justify-center p-4 md:p-8"
            onClick={onClose}
        >
            <div
                className="relative w-full max-w-4xl bg-slate-900 rounded-3xl overflow-hidden shadow-2xl border border-slate-700 animate-in zoom-in-95 duration-300"
                onClick={e => e.stopPropagation()}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-slate-700">
                    <h3 className="font-bold text-white text-lg">{title}</h3>
                    <button
                        onClick={onClose}
                        className="w-8 h-8 flex items-center justify-center rounded-full bg-slate-800 text-slate-400 hover:text-white hover:bg-slate-700 transition-colors"
                    >
                        <i className="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                {/* Video */}
                <div className="p-4 md:p-6">
                    {src ? (
                        <VideoPlayer src={src} poster={poster} duration={duration} />
                    ) : (
                        <div className="aspect-video bg-slate-800 rounded-2xl flex items-center justify-center">
                            <div className="text-center text-slate-500">
                                <i className="fa-solid fa-video text-4xl mb-3"></i>
                                <p className="text-sm">Video coming soon</p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default SalesVideoModal;