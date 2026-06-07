// public/js/conversion.js
/**
 * Zenith Wellness Conversion Engine
 * Handles Countdown, Spots Remaining, and Live Activity for static sales pages.
 */

(function () {
    // Expose for inline scripts
    window.initCountdowns = initCountdowns;
    window.initSpots = initSpots;
    window.initTestimonials = initTestimonials;
    window.initResults = initResults;

    // --- Configuration ---
    const CONFIG = {
        apiBase: '/api',
        cohortDate: new Date(new Date().setDate(new Date().getDate() + 3)), // Mock next date
    };

    // --- Components ---

    // 4. Testimonials Section
    function initTestimonials() {
        const container = document.querySelector('[data-component="testimonials"]');
        if (!container) return;

        fetch(`${CONFIG.apiBase}/testimonials.php`)
            .then(res => res.json())
            .then(data => {
                if (Array.isArray(data) && data.length > 0) {
                    const grid = container.querySelector('.grid'); // Expecting a grid container
                    if (!grid) return;

                    grid.innerHTML = ''; // Clear defaults

                    // Take top 3
                    data.slice(0, 3).forEach(t => {
                        const card = document.createElement('div');
                        card.className = 'bg-white p-6 rounded-2xl shadow-sm border border-slate-100 flex flex-col';
                        card.innerHTML = `
                            <div class="flex gap-1 mb-4 text-amber-400 text-xs">
                                ${Array(parseInt(t.rating)).fill('<i class="fa-solid fa-star"></i>').join('')}
                            </div>
                            <p class="text-slate-600 italic mb-6 leading-relaxed flex-grow">"${t.quote}"</p>
                            <div class="mt-auto flex items-center gap-3 pt-4 border-t border-slate-50">
                                <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-700">${t.name.charAt(0)}</div>
                                <span class="font-bold text-sm text-slate-900">${t.name}</span>
                                ${t.result_metric ? `<span class="bg-emerald-50 text-emerald-700 px-2 py-1 rounded-md text-[10px] uppercase font-bold ml-auto">${t.result_metric}</span>` : ''}
                            </div>
                        `;
                        grid.appendChild(card);
                    });
                }
            })
            .catch(console.error);
    }

    // 5. Results Gallery
    function initResults() {
        const container = document.querySelector('[data-component="results"]');
        if (!container) return;

        // Similar logic for results if we had a dedicated API or just inject into grid
        // For now, let's assume static pages might not have a dynamic results section grid yet.
        // If they do, we'd fetch from /api/results.php

        fetch(`${CONFIG.apiBase}/results.php`)
            .then(res => res.json())
            .then(data => {
                if (Array.isArray(data) && data.length > 0) {
                    const grid = container.querySelector('.grid') || container;
                    grid.innerHTML = '';

                    data.slice(0, 3).forEach(r => {
                        const card = document.createElement('div');
                        card.className = 'relative group overflow-hidden rounded-2xl shadow-lg border border-slate-100 bg-white';
                        card.innerHTML = `
                            <div class="h-48 overflow-hidden relative">
                               <img src="${r.image_url}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" />
                               <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                               <div class="absolute bottom-4 left-4 text-white">
                                  <p class="font-bold text-lg">${r.metric_value}</p>
                                  <p class="text-xs text-white/80 uppercase tracking-widest">${r.metric_label}</p>
                               </div>
                            </div>
                            <div class="p-6">
                               <p class="text-slate-600 italic text-sm mb-4">"${r.description}"</p>
                               <div class="flex items-center justify-between border-t border-slate-50 pt-3">
                                  <span class="font-bold text-slate-900 text-sm">${r.user_name}</span>
                               </div>
                            </div>
                         `;
                        grid.appendChild(card);
                    });
                }
            })
            .catch(console.error);
    }

    // 1. Countdown Timer
    function initCountdowns() {
        const elements = document.querySelectorAll('[data-component="countdown"]');
        if (elements.length === 0) return;

        function updateTimers() {
            const now = new Date().getTime();
            const target = CONFIG.cohortDate.getTime();
            const diff = target - now;

            if (diff < 0) return;

            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            elements.forEach(el => {
                const type = el.dataset.type || 'inline';
                if (type === 'box') {
                    // Expects HTML structure with classes .d, .h, .m, .s
                    el.querySelector('.d').innerText = String(days).padStart(2, '0');
                    el.querySelector('.h').innerText = String(hours).padStart(2, '0');
                    el.querySelector('.m').innerText = String(minutes).padStart(2, '0');
                    el.querySelector('.s').innerText = String(seconds).padStart(2, '0');
                } else {
                    el.innerText = `${days}d ${hours}h ${minutes}m ${seconds}s`;
                }
            });
        }

        setInterval(updateTimers, 1000);
        updateTimers();
    }

    // 2. Spots Remaining
    function initSpots() {
        const elements = document.querySelectorAll('[data-component="spots"]');
        if (elements.length === 0) return;

        fetch(`${CONFIG.apiBase}/cohorts_spots.php`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    elements.forEach(el => {
                        const remaining = data.data.remaining;
                        const total = data.data.total;

                        // Update text
                        const countEl = el.querySelector('.count');
                        if (countEl) countEl.innerText = `${remaining} / ${total}`;

                        // Update bar
                        const barEl = el.querySelector('.progress-bar');
                        if (barEl) {
                            barEl.style.width = `${data.data.percentFull}%`;
                            if (remaining < 10) barEl.classList.add('bg-red-500');
                        }
                    });
                }
            })
            .catch(console.error);
    }

    // 3. Live Activity Feed
    function initLiveFeed() {
        if (!document.querySelector('body')) return; // Safety

        const container = document.createElement('div');
        container.id = 'live-feed-container';
        container.className = 'fixed bottom-4 left-4 z-50 transition-all duration-500 transform translate-y-10 opacity-0 pointer-events-none';
        container.innerHTML = `
            <div class="bg-white/90 backdrop-blur-md border border-slate-200 shadow-xl rounded-full px-4 py-2 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-bold text-xs ring-2 ring-white user-initial">A</div>
                <div class="flex flex-col">
                    <span class="text-xs font-bold text-slate-800 user-text">...</span>
                    <span class="text-[10px] text-slate-500 program-text">...</span>
                </div>
            </div>
        `;
        document.body.appendChild(container);

        function showNotification(activity) {
            container.querySelector('.user-initial').innerText = activity.name.charAt(0);
            container.querySelector('.user-text').innerText = `${activity.name} from ${activity.location}`;
            container.querySelector('.program-text').innerHTML = `joined <strong>${activity.program}</strong> ${activity.time}`;

            container.classList.remove('translate-y-10', 'opacity-0', 'pointer-events-none');

            setTimeout(() => {
                container.classList.add('translate-y-10', 'opacity-0', 'pointer-events-none');
            }, 5000);
        }

        function fetchAndShow() {
            fetch(`${CONFIG.apiBase}/recent_enrollments.php`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        const random = data.data[Math.floor(Math.random() * data.data.length)];
                        showNotification(random);
                    }
                })
                .catch(console.error);
        }

        setTimeout(fetchAndShow, 2000);
        setInterval(fetchAndShow, 30000);
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
        initCountdowns();
        initSpots();
        initLiveFeed();
        initTestimonials();
        initResults();
    });

})();
