import React, { useState, useRef } from 'react';

interface VideoPlayerProps {
    src: string;
    poster?: string;
    duration?: string;
    onClose?: () => void;
    onPurchase?: () => void;
    videoUrl?: string;
}

const VideoPlayer: React.FC<VideoPlayerProps> = ({ src, poster, duration, onClose, onPurchase }) => {
    const [isPlaying, setIsPlaying] = useState(false);
    const videoRef = useRef<HTMLVideoElement>(null);

    const togglePlay = () => {
        if (videoRef.current) {
            if (isPlaying) {
                videoRef.current.pause();
            } else {
                videoRef.current.play();
            }
            setIsPlaying(!isPlaying);
        }
    };

    return (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="relative w-full max-w-4xl bg-slate-900 rounded-3xl overflow-hidden shadow-2xl border border-slate-700">
                {/* Close button */}
                {onClose && (
                    <button
                        onClick={onClose}
                        className="absolute top-4 right-4 z-10 w-10 h-10 bg-black/50 hover:bg-black/70 rounded-full flex items-center justify-center transition-colors"
                    >
                        <i className="fa-solid fa-xmark text-white text-xl"></i>
                    </button>
                )}

                {/* Video player */}
                <div className="aspect-video relative">
                    <video
                        ref={videoRef}
                        src={src}
                        poster={poster}
                        className="w-full h-full object-cover"
                        onEnded={() => setIsPlaying(false)}
                        playsInline
                        muted
                    />

                    {/* Play overlay */}
                    {!isPlaying && (
                        <div
                            className="absolute inset-0 bg-black/40 backdrop-blur-[2px] flex flex-col items-center justify-center cursor-pointer transition-all group-hover:bg-black/30"
                            onClick={togglePlay}
                        >
                            <div className="w-20 h-20 bg-white/20 backdrop-blur-md rounded-full flex items-center justify-center border border-white/30 transform transition-transform group-hover:scale-110">
                                <div className="w-16 h-16 bg-white rounded-full flex items-center justify-center shadow-lg">
                                    <i className="fa-solid fa-play text-2xl text-indigo-600 ml-1"></i>
                                </div>
                            </div>

                            {duration && (
                                <div className="mt-4 px-3 py-1 bg-black/50 backdrop-blur-md rounded-full text-[10px] font-bold text-white uppercase tracking-widest border border-white/10">
                                    {duration} Walkthrough
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* CTA section */}
                {onPurchase && (
                    <div className="p-6 bg-slate-800 border-t border-slate-700 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <p className="text-white font-bold text-lg">Ready to transform your health?</p>
                        <button
                            onClick={onPurchase}
                            className="bg-indigo-600 text-white px-8 py-3 rounded-xl font-black uppercase tracking-widest hover:bg-indigo-500 hover:scale-[1.02] transition-all shadow-xl shadow-indigo-900/20 whitespace-nowrap"
                        >
                            Join the Program
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
};

export default VideoPlayer;
