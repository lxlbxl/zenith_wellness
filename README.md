# Zenith Wellness

A comprehensive wellness platform built with React, TypeScript, and Vite.

## 🌟 Features

### Core Components
- **Dashboard** - Personal wellness overview and activity tracking
- **Habit Tracker** - Build and maintain healthy habits
- **Goal Manager** - Set and track wellness goals
- **Journal** - Daily reflection and mood tracking
- **Challenge Hub** - Participate in wellness challenges
- **Cohort Lounge** - Community features and group activities
- **Coach Panel** - Coaching and guidance interface
- **Admin Panel** - Administrative controls

### Health Tracking
- **Cycle Tracker** - Menstrual cycle tracking
- **Meal Tracker** - Nutrition and meal planning
- **Workout Logger** - Exercise tracking
- **Focus Timer** - Productivity and focus sessions
- **Activity Tracker** - Daily activity monitoring

### User Experience
- **Onboarding Quiz** - Personalized onboarding experience
- **Profile Settings** - User profile management
- **Privacy Center** - Privacy controls and settings
- **Notification Center** - Real-time notifications
- **Payment Modal** - Secure payment processing
- **Cookie Banner** - GDPR compliance

### Gamification
- **Trophy Cabinet** - Achievements and rewards
- **Gamification Hub** - Points, badges, and leaderboards

## 🏗️ Project Structure

```
zenith_wellness/
├── api/                    # PHP backend API
│   ├── auth/              # Authentication handlers
│   ├── controllers/       # API controllers
│   ├── database/          # Database configuration
│   ├── middleware/        # API middleware
│   ├── migrations/        # Database migrations
│   ├── services/          # Business logic services
│   └── webhooks/          # Webhook handlers
├── components/            # React components
│   ├── admin/            # Admin-specific components
│   ├── funnels/          # Sales funnel components
│   ├── payment/          # Payment components
│   ├── sales/            # Sales components
│   └── ui/               # Reusable UI components
├── hooks/                 # Custom React hooks
├── services/              # API and business logic services
├── tests/                 # Playwright end-to-end tests
├── utils/                 # Utility functions
├── public/                # Static assets
└── release/               # Release builds
```

## 🚀 Getting Started

### Prerequisites
- Node.js 18+ 
- PHP 8.0+
- MySQL 8.0+
- Composer (for PHP dependencies)

### Installation

1. Install frontend dependencies:
```bash
cd zenith_wellness
npm install
```

2. Install backend dependencies:
```bash
cd api
composer install
```

3. Configure database:
- Copy `api/config.php` and update database credentials
- Run database migrations via `api/migrations/`

### Development

Start the development server:
```bash
npm run dev
```

Run the backend:
```bash
# Windows PowerShell
.\tools\start_backend.ps1
```

### Building for Production

```bash
npm run build
```

## 🧪 Testing

Run end-to-end tests:
```bash
npm run test:e2e
```

## 🧪 A/B Experiment Engine

The platform includes a built-in A/B testing engine with Thompson Sampling bandit allocation, Bayesian statistics, and automatic promotion.

### Cron Jobs

The following cron job keeps experiment statistics up to date:

```bash
# Refresh experiment stats every 5 minutes
*/5 * * * * php /path/to/api/scripts/refresh_experiment_stats.php > /dev/null 2>&1
```

This script:
1. Refreshes `experiment_segment_stats` from raw `experiment_events`
2. Computes Monte Carlo prob_best (configured via `EXPERIMENT_MC_SAMPLES`, default 20000)
3. Runs SRM detection via chi-square test
4. Checks auto-promote conditions

### Seed Data

Initial experiments can be seeded with:

```bash
php api/seed_experiments.php
```

### Configuration

See `api/.env.example` for experiment engine environment variables:

| Variable | Description | Default |
|---|---|---|
| `EXPERIMENT_ENGINE_ENABLED` | Master toggle for the experiment engine | `true` |
| `EXPERIMENT_HOLDOUT_PERCENT` | Global holdout percentage | `0` |
| `EXPERIMENT_MC_SAMPLES` | Monte Carlo draws for prob_best | `20000` |
| `EXPERIMENT_AI_GENERATION_ENABLED` | Kill switch for AI variant generation | `false` |
| `VARIANT_GEN_MODEL` | AI model for variant generation | `gemini-1.5-flash` |
| `VARIANT_GEN_MAX_CANDIDATES` | Max candidates per generation | `5` |
| `VARIANT_GEN_MONTHLY_TOKEN_CEILING` | Monthly token budget for AI gen | `1000000` |
| `INSIGHT_DECAY_DAYS` | Days before an insight decays | `90` |

### Frontend Integration

The frontend reads `VITE_EXPERIMENTS_ENABLED` from `.env` to enable/disable experiment hooks.

## 📱 Responsive Design

The application is fully responsive and optimized for:
- Desktop (1920px+)
- Tablet (768px - 1024px)
- Mobile (320px - 767px)

## 🔐 Security Features

- JWT-based authentication
- Rate limiting on API endpoints
- Input validation and sanitization
- CORS protection
- SQL injection prevention
- XSS protection

## 🛠️ Tech Stack

### Frontend
- React 18
- TypeScript
- Vite
- Tailwind CSS

### Backend
- PHP 8.0+
- MySQL
- RESTful API architecture

### Testing
- Playwright

## 📄 License

Proprietary - Zenith Wellness

## 🤝 Contributing

This is a private project. Please contact the maintainers for contribution guidelines.

## 📞 Support

For support and questions, please refer to the internal documentation.