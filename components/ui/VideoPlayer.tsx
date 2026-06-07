import React, { useState, useRef } from 'react';

interface VideoPlayerProps {
    src: string;
    poster?: string;
    duration?: string;
}

const VideoPlayer: React.FC<VideoPlayerProps> = ({ src, poster, duration }) => {
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
        <div className="relative rounded-3xl overflow-hidden shadow-2xl group border-4 border-white aspect-video bg-slate-900">
            <video
                ref={videoRef}
                src={src}
                poster={poster}
                className="w-full h-full object-cover"
                onEnded={() => setIsPlaying(false)}
                playsInline
                muted
            />
            
            {/* Overlay */}
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
            
            {/* Play/Pause control on hover if playing */}
            {isPlaying && (
                <div 
                    className="absolute inset-0 opacity-0 hover:opacity-100 transition-opacity bg-black/20 flex items-center justify-center cursor-pointer"
                    onClick={togglePlay}
                >
                    <div className="w-12 h-12 bg-white/20 backdrop-blur-md rounded-full flex items-center justify-center border border-white/30">
                        <i className="fa-solid fa-pause text-white"></i>
                    </div>
                </div>
            )}
        </div>
    );
};

export default VideoPlayer;
