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
                    const grid = container.querySelector('.grid');
                    if (!grid) return;

                    grid.innerHTML = '';

                    data.slice(0, 3).forEach(t => {
                        const card = document.createElement('div');
                        card.className = 'bg-white p-6 rounded-2xl shadow-sm border border-slate-100 flex flex-col';

                        const stars = document.createElement('div');
                        stars.className = 'flex gap-1 mb-4 text-amber-400 text-xs';
                        const starEl = document.createElement('i');
                        starEl.className = 'fa-solid fa-star';
                        for (let i = 0; i < parseInt(t.rating); i++) {
                            stars.appendChild(starEl.cloneNode(false));
                        }

                        const quote = document.createElement('p');
                        quote.className = 'text-slate-600 italic mb-6 leading-relaxed flex-grow';
                        quote.textContent = '"' + t.quote + '"';

                        const footer = document.createElement('div');
                        footer.className = 'mt-auto flex items-center gap-3 pt-4 border-t border-slate-50';

                        const avatar = document.createElement('div');
                        avatar.className = 'w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-700';
                        avatar.textContent = t.name.charAt(0);

                        const name = document.createElement('span');
                        name.className = 'font-bold text-sm text-slate-900';
                        name.textContent = t.name;

                        footer.appendChild(avatar);
                        footer.appendChild(name);

                        if (t.result_metric) {
                            const badge = document.createElement('span');
                            badge.className = 'bg-emerald-50 text-emerald-700 px-2 py-1 rounded-md text-[10px] uppercase font-bold ml-auto';
                            badge.textContent = t.result_metric;
                            footer.appendChild(badge);
                        }

                        card.appendChild(stars);
                        card.appendChild(quote);
                        card.appendChild(footer);
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

        fetch(`${CONFIG.apiBase}/results.php`)
            .then(res => res.json())
            .then(data => {
                if (Array.isArray(data) && data.length > 0) {
                    const grid = container.querySelector('.grid') || container;
                    grid.innerHTML = '';

                    data.slice(0, 3).forEach(r => {
                        const card = document.createElement('div');
                        card.className = 'relative group overflow-hidden rounded-2xl shadow-lg border border-slate-100 bg-white';

                        const imgWrap = document.createElement('div');
                        imgWrap.className = 'h-48 overflow-hidden relative';

                        const img = document.createElement('img');
                        img.src = r.image_url;
                        img.className = 'w-full h-full object-cover transition-transform duration-700 group-hover:scale-105';
                        img.alt = r.metric_label;

                        const overlay = document.createElement('div');
                        overlay.className = 'absolute inset-0 bg-gradient-to-t from-black/60 to-transparent';

                        const metricWrap = document.createElement('div');
                        metricWrap.className = 'absolute bottom-4 left-4 text-white';

                        const metricVal = document.createElement('p');
                        metricVal.className = 'font-bold text-lg';
                        metricVal.textContent = r.metric_value;

                        const metricLabel = document.createElement('p');
                        metricLabel.className = 'text-xs text-white/80 uppercase tracking-widest';
                        metricLabel.textContent = r.metric_label;

                        metricWrap.appendChild(metricVal);
                        metricWrap.appendChild(metricLabel);
                        imgWrap.appendChild(img);
                        imgWrap.appendChild(overlay);
                        imgWrap.appendChild(metricWrap);

                        const body = document.createElement('div');
                        body.className = 'p-6';

                        const desc = document.createElement('p');
                        desc.className = 'text-slate-600 italic text-sm mb-4';
                        desc.textContent = '"' + r.description + '"';

                        const footer = document.createElement('div');
                        footer.className = 'flex items-center justify-between border-t border-slate-50 pt-3';

                        const userName = document.createElement('span');
                        userName.className = 'font-bold text-slate-900 text-sm';
                        userName.textContent = r.user_name;

                        footer.appendChild(userName);
                        body.appendChild(desc);
                        body.appendChild(footer);
                        card.appendChild(imgWrap);
                        card.appendChild(body);
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
