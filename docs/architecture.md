# System Architecture

Technical architecture documentation for the WebXpanse business operating platform.

## Overview

The CRM system follows a modular, layered architecture with clear separation of concerns.

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────┐
│                    Client Layer                          │
│              (Browser, Mobile App, API Clients)         │
└────────────────────┬────────────────────────────────────┘
                     │ HTTP/HTTPS
┌────────────────────▼────────────────────────────────────┐
│                  Web Server Layer                       │
│              (Apache/Nginx + PHP-FPM)                   │
└────────────────────┬────────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────────┐
│              Application Layer                           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │
│  │   Public     │  │     API      │  │     CLI     │   │
│  │  Interface   │  │  Endpoints    │  │   Workers   │   │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘   │
│         │                  │                  │          │
│  ┌──────▼──────────────────▼──────────────────▼──────┐  │
│  │              Business Logic Layer                   │  │
│  │              (Modules/*.php)                        │  │
│  └──────┬─────────────────────────────────────────────┘  │
│         │                                                 │
│  ┌──────▼─────────────────────────────────────────────┐  │
│  │              Service Layer                          │  │
│  │         (Email, WhatsApp, AI, etc.)                 │  │
│  └──────┬─────────────────────────────────────────────┘  │
│         │                                                 │
│  ┌──────▼─────────────────────────────────────────────┐  │
│  │              Core Framework                         │  │
│  │    (Database, Auth, Security, Session, etc.)        │  │
│  └─────────────────────────────────────────────────────┘  │
└────────────────────┬────────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────────┐
│              Data Layer                                  │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │
│  │   MySQL      │  │    Redis     │  │   File      │   │
│  │  Database    │  │    Cache     │  │  Storage    │   │
│  └──────────────┘  └──────────────┘  └──────────────┘   │
└─────────────────────────────────────────────────────────┘
```

## Component Architecture

### Front Controller Pattern

All web requests go through `public/index.php`:

```
Request → public/index.php → Router → Module → Response
```

### Module Pattern

Business logic is organized in modules:

```
modules/
├── Contacts.php          # Contact management
├── Activities.php        # Activity tracking
├── EmailService.php      # Email operations
├── Workflows.php         # Automation
└── ...
```

Each module:
- Handles specific domain logic
- Uses Database class for data access
- Uses Security class for validation
- Can use other modules/services

### Service Layer

External integrations in services:

```
services/
├── EmailService.php      # Email sending
├── WhatsAppService.php   # WhatsApp integration
├── AIService.php         # AI operations
└── ...
```

Services:
- Handle external API calls
- Manage queues
- Provide abstraction over external systems

## Data Flow

### Contact Creation Flow

```
1. User submits form (public/contacts_create.php)
   ↓
2. Validate CSRF token
   ↓
3. Call Contacts::create()
   ↓
4. Validate and sanitize input
   ↓
5. Check for duplicates
   ↓
6. Insert into database (Database::execute())
   ↓
7. Log activity (Activities::log())
   ↓
8. Create notification (Notifications::create())
   ↓
9. Log audit trail (AuditLogger::log())
   ↓
10. Return success response
```

### Email Sending Flow

```
1. User composes email (public/email_compose.php)
   ↓
2. Validate and prepare email data
   ↓
3. Add to email queue (EmailQueue::add())
   ↓
4. Return success to user
   ↓
5. Email worker picks up job (cli/email_worker.php)
   ↓
6. Process email (SMTPClient::send())
   ↓
7. Update queue status
   ↓
8. Track email (EmailTracking::track())
```

## Database Schema

### Core Tables

```
users
├── id (PK)
├── email
├── password_hash
├── role
└── created_at

contacts
├── id (PK)
├── uuid
├── first_name
├── last_name
├── email
├── phone
├── company
├── stage
├── lead_score
└── created_at

activities
├── id (PK)
├── contact_id (FK)
├── user_id (FK)
├── activity_type
├── description
└── created_at

emails
├── id (PK)
├── contact_id (FK)
├── to_email
├── subject
├── body_html
├── status
└── created_at
```

### Relationships

- **users** → **contacts** (assigned_to)
- **contacts** → **activities** (one-to-many)
- **contacts** → **emails** (one-to-many)
- **contacts** → **tasks** (one-to-many)
- **contacts** → **events** (one-to-many)
- **contacts** → **deals** (one-to-many)
- **contacts** → **notes** (one-to-many)
- **contacts** → **documents** (one-to-many)

## Security Architecture

### Authentication Flow

```
1. User submits login form
   ↓
2. Validate credentials (Auth::login())
   ↓
3. Verify password (password_verify())
   ↓
4. Create session (Session::start())
   ↓
5. Set session variables
   ↓
6. Redirect to dashboard
```

### Authorization

- **Role-based**: Admin vs User
- **Permission checks**: Before sensitive operations
- **CSRF protection**: On all state-changing operations
- **Input validation**: All user input sanitized

### Security Layers

1. **Input Validation**: Security::sanitizeInput()
2. **SQL Injection Prevention**: Prepared statements
3. **XSS Prevention**: htmlspecialchars()
4. **CSRF Protection**: Token validation
5. **Session Security**: Secure session management
6. **Password Hashing**: bcrypt

## Caching Strategy

### Cache Levels

1. **Application Cache** (Redis):
   - Query results
   - Computed values
   - Session data

2. **Database Cache**:
   - Materialized views
   - Query result cache

3. **Browser Cache**:
   - Static assets
   - CSS/JS files

## Queue System

### Queue Architecture

```
┌─────────────┐
│   Producer  │ (Adds jobs to queue)
└──────┬──────┘
       │
┌──────▼──────────────────┐
│    Queue Table          │ (email_queue, whatsapp_queue)
└──────┬──────────────────┘
       │
┌──────▼──────┐
│   Worker    │ (Processes jobs)
└─────────────┘
```

### Queue Flow

1. Job added to queue table
2. Worker polls queue
3. Worker processes job
4. Worker updates status
5. Worker logs result

## API Architecture

### RESTful Design

- **GET**: Retrieve resources
- **POST**: Create resources
- **PUT/PATCH**: Update resources
- **DELETE**: Delete resources

### API Structure

```
/api/
├── contacts.php          # Contacts API
├── activities.php        # Activities API
├── email_templates.php   # Templates API
└── ...
```

### Authentication

- Session-based (web interface)
- API key (future implementation)
- CSRF tokens for state-changing operations

## Worker Architecture

### Worker Types

1. **Email Worker**: Processes email queue
2. **WhatsApp Worker**: Processes WhatsApp queue
3. **Scheduled Email Worker**: Handles scheduled emails
4. **Scheduled Report Worker**: Generates scheduled reports

### Worker Process

```
1. Worker starts
   ↓
2. Connect to database
   ↓
3. Poll queue for pending jobs
   ↓
4. Process job
   ↓
5. Update job status
   ↓
6. Log result
   ↓
7. Sleep and repeat
```

## File Structure

### Directory Organization

```
crm/
├── api/              # API endpoints (thin controllers)
├── cli/              # CLI scripts and workers
├── config/           # Configuration files
├── core/             # Core framework (reusable)
├── database/         # Database migrations
├── docs/             # Documentation
├── modules/          # Business logic (fat models)
├── public/           # Public web directory
├── services/         # External service integrations
├── tests/            # Test suite
├── uploads/          # User uploaded files
└── views/            # Presentation layer
```

### Separation of Concerns

- **public/**: HTTP request handling, form processing
- **modules/**: Business logic, data validation
- **services/**: External integrations
- **core/**: Framework utilities
- **views/**: Presentation templates

## Technology Stack

### Backend

- **PHP 8.1+**: Server-side language
- **MySQL 8.0+**: Relational database
- **Redis**: Caching and sessions (optional)
- **Composer**: Dependency management

### Frontend

- **HTML5**: Markup
- **CSS3**: Styling
- **JavaScript**: Client-side interactivity
- **Quill.js**: Rich text editor
- **Chart.js**: Data visualization

### Infrastructure

- **Apache/Nginx**: Web server
- **PHP-FPM**: PHP process manager
- **Supervisor/systemd**: Process management
- **Let's Encrypt**: SSL certificates

## Scalability Considerations

### Horizontal Scaling

- Load balancer (Nginx/HAProxy)
- Multiple app servers
- Database replication
- Shared session storage (Redis)

### Vertical Scaling

- More CPU/RAM
- Faster storage (SSD)
- Database optimization
- Caching layer

### Database Scaling

- Read replicas
- Table partitioning
- Query optimization
- Connection pooling

## Performance Optimization

### Caching Strategy

1. **Query Result Caching**: Cache expensive queries
2. **Computed Value Caching**: Cache calculations
3. **Session Caching**: Redis for sessions
4. **Static Asset Caching**: CDN for assets

### Database Optimization

1. **Indexes**: On frequently queried columns
2. **Materialized Views**: For analytics
3. **Query Optimization**: Avoid N+1 problems
4. **Connection Pooling**: Manage connections

### Code Optimization

1. **Lazy Loading**: Load data on demand
2. **Pagination**: Limit data fetched
3. **Eager Loading**: Load related data efficiently
4. **Query Batching**: Reduce database calls

## Monitoring Architecture

### Health Checks

- **API Endpoint**: `/api/health.php`
- **Database**: Connection status
- **Cache**: Redis connection
- **Workers**: Process status

### Logging

- **Application Logs**: User actions, errors
- **Access Logs**: Web server logs
- **Error Logs**: PHP errors
- **Worker Logs**: Background job processing

### Metrics

- **Performance**: Response times, query times
- **Usage**: User activity, API calls
- **Resources**: CPU, memory, disk
- **Errors**: Error rates, types

---

## Design Decisions

### Why PHP?

- Widely available hosting
- Large ecosystem
- Good performance
- Easy deployment

### Why MySQL?

- Reliable and proven
- Good performance
- ACID compliance
- Wide support

### Why Modular Architecture?

- Easy to maintain
- Clear separation of concerns
- Testable components
- Reusable code

### Why Queue System?

- Async processing
- Better user experience
- Scalability
- Reliability

---

*Last Updated: 2026-01-24*
