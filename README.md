# WebXpanse — Clarity

WebXpanse is a self-hosted business operating system. Clarity is its AI co-founder experience: it turns business context, customer activity, tasks, pipeline, and strategic assumptions into one evidence-backed founder priority and a measurable next action.

## OpenAI Build Week 2026

The Build Week entry focuses on a narrow daily loop for solo founders:

1. Build a structured picture of the business.
2. Rank the highest current constraint from real operating evidence.
3. Explain why it matters through Clarity.
4. Connect the founder's decision to a dated commitment and CRM action.
5. Report only verified automation outcomes.

Start with the [Build Week submission brief](docs/openai-build-week/README.md), [change log](docs/openai-build-week/BUILD_WEEK_CHANGELOG.md), [three-minute demo script](docs/openai-build-week/DEMO_SCRIPT.md), [Devpost copy](docs/openai-build-week/DEVPOST_SUBMISSION.md), and [final field package](docs/openai-build-week/SUBMISSION_FIELDS.md).

![Status](https://img.shields.io/badge/status-production--ready-green)
![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)
![License](https://img.shields.io/badge/license-Proprietary-red)

## 🚀 Features

### Core Features
- **Complete Contact Management** - Full CRUD operations with duplicate detection
- **Custom Fields System** - Flexible field creation for any data type
- **Activity Timeline** - Comprehensive activity tracking and history
- **Lead Scoring** - Activity-based scoring with time decay factors
- **Advanced Search** - Global search across all modules

### Communication
- **Email System** - Self-hosted SMTP with queue management
- **Email Tracking** - Open and click tracking
- **Email Templates** - WYSIWYG editor with variable support
- **WhatsApp Integration** - Business API integration with webhooks
- **Unified Inbox** - Multi-channel communication aggregation
- **Conversation Threading** - Organized conversation management

### Automation & Analytics
- **Workflow Automation** - Visual workflow builder with triggers and actions
- **Analytics Dashboard** - Real-time metrics and funnel analysis
- **Reporting Engine** - Custom report builder with PDF/Excel/CSV export
- **Scheduled Reports** - Automated report generation and email delivery

### Task & Calendar Management
- **Task Management** - Full task CRUD with priorities and assignments
- **Calendar/Events** - Event management with recurring events support
- **Reminders** - Email and in-app reminders

### Document Management
- **File Upload & Storage** - Secure document storage
- **Document Categories** - Organized document categorization
- **Version Control** - Document versioning with restore capability
- **File Preview** - Image and PDF preview support

### Additional Features
- **Tags/Labels System** - Color-coded tagging for organization
- **Notes/Comments** - Rich text notes with @mentions
- **Deals/Opportunities** - Sales pipeline management
- **Bulk Operations** - Bulk edit, delete, and status updates
- **Contact Merge** - Intelligent duplicate contact merging
- **Audit Logging** - Complete user action tracking
- **Notifications** - In-app notifications with preferences
- **User Management** - Role-based access control

### AI Integration
- **Contact Summarization** - AI-powered contact summaries
- **Tiered AI Strategy** - Local → Open Source → Premium
- **Cost Optimization** - AI usage tracking and budget management

## 📋 Requirements

- **PHP** 8.1 or higher
- **MySQL** 8.0 or higher
- **Composer** (for dependency management)
- **Redis** (optional, recommended for caching)
- **Web Server** (Apache/Nginx)

## 🛠️ Installation

### Quick Start

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd crm
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   # Edit .env with your database credentials
   ```

4. **Run database migrations**
   ```bash
   php database/migrations/migrate.php
   ```

5. **Create admin user**
   ```bash
   php scripts/create_admin_user.php
   ```

6. **Configure web server**
   - Point your web server to the `public/` directory
   - Ensure URL rewriting is enabled (for clean URLs)

7. **Start workers** (optional, for background processing)
   ```bash
   php cli/email_worker.php
   php cli/whatsapp_worker.php
   php cli/scheduled_email_worker.php
   php cli/scheduled_report_worker.php
   ```

8. **Access the application**
   - Open your browser: `http://localhost/crm/public/`
   - Login with your admin credentials

### Detailed Installation

See [Deployment Guide](docs/deployment.md) for detailed installation instructions including production setup.

## 📚 Documentation

- **[User Guide](docs/user-guide.md)** - Complete user documentation
- **[API Documentation](docs/api.md)** - REST API reference
- **[API Authentication Guide](docs/api-authentication.md)** - API authentication methods
- **[API Collection](docs/api-collection.json)** - Postman/Insomnia collection
- **[Developer Guide](docs/developer-guide.md)** - Development documentation
- **[Architecture](docs/architecture.md)** - System architecture
- **[Deployment Guide](docs/deployment.md)** - Production deployment
- **[Cron Jobs Setup](docs/CRON_SETUP.md)** - Cron jobs and background workers
- **[Email Assistant Capabilities](docs/email-assistant-capabilities.md)** - Personal Assistant skills and email commands
- **[Troubleshooting Guide](docs/troubleshooting.md)** - Common issues and solutions
- **[Maintenance Procedures](docs/maintenance.md)** - Regular maintenance tasks
- **[Contributing Guide](docs/contributing.md)** - Development guidelines
- **[FAQ](docs/faq.md)** - Frequently asked questions

## 🧪 Testing

Run the test suite:
```bash
composer test
```

For specific test suites:
```bash
# Unit tests only
vendor/bin/phpunit tests/Unit

# Integration tests only
vendor/bin/phpunit tests/Integration
```

## 🏗️ Project Structure

```
crm/
├── api/                 # API endpoints
├── cli/                 # CLI scripts and workers
├── config/              # Configuration files
├── core/                # Core framework classes
├── database/            # Database migrations
│   └── migrations/      # SQL migration files
├── docs/                # Documentation
├── modules/             # Business logic modules
├── public/              # Public web directory
│   └── assets/          # CSS, JS, images
├── services/            # External service integrations
├── tests/               # Test suite
├── uploads/             # User uploaded files
├── vendor/              # Composer dependencies
└── views/               # PHP templates
```

## 🔧 Configuration

### Environment Variables

Key configuration options in `.env`:

```env
# Database
DB_HOST=localhost
DB_NAME=crm
DB_USER=root
DB_PASS=

# Email (SMTP)
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=your-email@example.com
SMTP_PASS=your-password

# Personal Assistant SMTP (optional) - separate account for assistant replies/digests
# EMAIL_ASSISTANT_SMTP_HOST=...
# EMAIL_ASSISTANT_FROM_EMAIL=assistant@example.com

# WhatsApp (optional)
WHATSAPP_API_URL=
WHATSAPP_API_TOKEN=

# AI Services (optional)
AI_SERVICE_URL=
AI_API_KEY=

# Redis (optional)
REDIS_HOST=localhost
REDIS_PORT=6379
```

See [Configuration Guide](docs/deployment.md#configuration) for all available options.

## 🚀 Quick Start Guide

1. **Login** - Use your admin credentials
2. **Create Contacts** - Add your first contact
3. **Set Up Email** - Configure SMTP settings in Settings
4. **Create Workflow** - Set up your first automation
5. **Generate Report** - Create a custom report

For detailed instructions, see the [User Guide](docs/user-guide.md).

## 🔒 Security

- CSRF protection on all forms
- SQL injection prevention (prepared statements)
- XSS protection
- Secure session management
- Password hashing (bcrypt)
- Audit logging
- Role-based access control

## 📊 Performance

- Database indexing for fast queries
- Redis caching support
- Queue system for async processing
- Materialized views for analytics
- Optimized asset loading

## 🤝 Contributing

See [Contributing Guide](docs/contributing.md) for development guidelines.

## 📝 License

Proprietary - All rights reserved

## 🆘 Support

- **Documentation**: See [docs/](docs/) directory
- **FAQ**: [docs/faq.md](docs/faq.md)
- **Troubleshooting**: [docs/troubleshooting.md](docs/troubleshooting.md)

## 🎯 Roadmap

See [TODO.md](TODO.md) for current development priorities and upcoming features.

## 📈 Status

**Current Version**: 1.0.0-beta  
**Completion**: 94%  
**Status**: Production-ready (core features complete)

### Completion Breakdown
- ✅ Core Features: 100%
- ✅ Communication System: 100%
- ✅ Integrations: 80% (SMS ✅, iCal ✅, Webhooks ✅, API Keys ✅)
- ✅ Monitoring: 85%
- ✅ Documentation: 95% (All guides complete, API collection ready)
- ⚠️ Testing: 70%
- ✅ AI Features: 70%

---

**Built with ❤️ for businesses that value control and ownership of their data.**
