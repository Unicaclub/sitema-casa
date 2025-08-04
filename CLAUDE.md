# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Development Setup
```bash
# Install dependencies
composer install
npm install && npm run build

# Setup environment
cp .env.example .env
php artisan key:generate

# Database setup
php artisan migrate --seed

# Initialize security systems
php artisan security:initialize
php artisan security:ai:train-models

# Start development server
composer serve
# or alternatively: php -S localhost:8000 -t public
```

### Testing
```bash
# Run all tests
composer test

# Run specific test suites
composer test-unit          # Unit tests only
composer test-feature       # Feature tests only  
composer test-integration   # Integration tests only

# Run tests with coverage
composer test-coverage

# Run single test file
vendor/bin/phpunit tests/Unit/Auth/AuthenticationServiceTest.php

# Run single test method
vendor/bin/phpunit --filter testSuccessfulAuthentication tests/Unit/Auth/AuthenticationServiceTest.php

# Run security test suite
vendor/bin/phpunit --testsuite=Security
```

### Code Quality
```bash
# Run all quality checks
composer quality

# Individual quality tools
composer analyse    # PHPStan static analysis (level 9)
composer psalm      # Psalm static analysis
composer cs-check   # PHP_CodeSniffer style check
composer cs-fix     # Fix code style issues automatically

# Apply all fixes
composer quality-fix
```

### Database Operations
```bash
# Run migrations
composer migrate

# Seed database
composer seed  

# Fresh migration with seeding
composer fresh
```

### Events System Operations
```bash
# Database migration for events system
mysql -u root -p < database/migrations/008_create_eventos_sistema.sql

# Test events API endpoints
curl -X GET "http://localhost:8000/api/eventos/cliente/12345678901"
curl -X POST "http://localhost:8000/api/eventos/venda" -H "Content-Type: application/json" -d '{...}'

# Artisan console commands
php artisan security:initialize
php artisan security:ai:train-models
php artisan queue:work
php artisan websocket:serve
```

### Docker Operations
```bash
# Start full stack
docker-compose up -d

# View application logs
docker-compose logs -f app

# Rebuild and restart
docker-compose build app && docker-compose up -d --force-recreate app

# Database backup
docker-compose exec backup /scripts/backup.sh
```

## Architecture Overview

### Core Architecture
This is an **Enterprise ERP System** built with a modular PHP 8.2+ architecture implementing **Supreme Cybersecurity with AI**. The system follows a layered architecture pattern with clear separation of concerns.

**Key Architectural Principles:**
- **Modular Design**: Core framework + business modules
- **Multi-tenancy**: Enterprise-grade tenant isolation
- **Security-First**: 7 integrated security systems with AI
- **Performance-Optimized**: Advanced caching, query optimization, and rate limiting
- **Event-Driven**: EventBus for decoupled communication
- **Dependency Injection**: Container-based DI with contextual binding

### Directory Structure
```
src/
├── Core/                    # Framework core components
│   ├── Auth/               # Authentication & JWT management
│   ├── Cache/              # Multi-layer caching system
│   ├── Http/               # HTTP layer, routing, middleware
│   ├── Performance/        # Query optimization, memory management
│   ├── RateLimit/          # AI-powered rate limiting
│   └── Security/           # 7-system security architecture
├── Modules/                # Business domain modules
└── Shared/                 # Shared utilities and contracts
```

### Security Architecture (7-System Integration)
The system implements a **Security Operations Center (SOC)** that orchestrates 7 specialized security systems:

1. **SOCManager**: Central command and control with SOAR automation
2. **WAFManager**: Web Application Firewall with AI-powered OWASP Top 10 protection
3. **IDSManager**: Intrusion Detection/Prevention System with multi-layer analysis
4. **AIMonitoringManager**: 5 AI models for threat prediction and behavioral analysis
5. **ThreatIntelligenceManager**: Multi-source threat intelligence with MITRE ATT&CK mapping
6. **ZeroTrustManager**: "Never Trust, Always Verify" architecture
7. **PenTestManager**: Automated penetration testing and vulnerability assessment

All security systems report to the SOC and work together for unified threat detection and response.

### Authentication System
- **JWT-based**: Access/refresh token pairs with configurable TTL
- **Multi-factor**: TOTP support with backup codes
- **Token Management**: Blacklisting, rotation, and secure storage
- **Guards**: Multiple authentication guards (JWT, Session)
- **Providers**: Pluggable user providers (Database, LDAP ready)

### Performance System
- **QueryOptimizer**: AI-powered SQL optimization with caching
- **MemoryManager**: Advanced memory management with object pooling
- **RateLimitManager**: Intelligent rate limiting with behavioral analysis
- **CacheManager**: Multi-layer caching (Redis, File, Memory)
- **AssetOptimizer**: Compression and optimization for static assets

### Database Layer
- **Multi-connection**: Main database + reports database
- **Migration System**: Version-controlled schema management
- **Query Builder**: Eloquent ORM integration
- **Connection Pooling**: Optimized database connections
- **Audit Logging**: All database operations logged for compliance

### Testing Strategy
- **94 Total Tests**: Comprehensive test coverage
- **Unit Tests**: Core components and business logic
- **Integration Tests**: Database and external service integration
- **Feature Tests**: End-to-end application workflows
- **Security Tests**: Dedicated security component testing

### Configuration Management
Configuration files in `config/` directory:
- `database.php`: Database connections and settings
- `cache.php`: Cache configuration for Redis/File
- `security.php`: Security system settings
- `performance.php`: Performance optimization settings
- `auth.php`: Authentication and JWT settings

### API Architecture
RESTful API with two main categories:
- **Security APIs** (`/api/security/*`): SOC, WAF, IDS, AI monitoring endpoints
- **Business APIs** (`/api/*`): CRM, sales, financial, inventory management

### Environment Configuration
Environment variables for:
- Database connections (main + reports)
- Redis configuration
- JWT secrets and security keys
- External API keys (VirusTotal, etc.)
- Security system settings
- Performance thresholds

### Security Compliance
The system implements multiple compliance frameworks:
- LGPD (Brazilian Data Protection Law)
- GDPR (European Data Protection Regulation)
- ISO 27001 (Information Security Management)
- OWASP ASVS (Application Security Verification Standard)
- NIST Cybersecurity Framework
- SOC 2 Type II compliance

### Deployment Architecture
- **Docker**: Production-ready containerization with 8 services
- **Load Balancing**: Nginx with upstream PHP-FPM workers
- **Monitoring**: Prometheus + Grafana for metrics
- **Logging**: ELK stack (Elasticsearch + Kibana)
- **Backup**: Automated daily backups with 30-day retention
- **Health Checks**: Comprehensive service health monitoring

### Events VIP System
This system implements comprehensive event management with CPF-based integration:

**Core Services:**
- **ServicoEventos** (`src/Modules/Eventos/ServicoEventos.php`): Event management, client lookup by CPF, ticket sales
- **ServicoPDV** (`src/Modules/Eventos/ServicoPDV.php`): Point-of-sale integration with 3-digit CPF authentication
- **EventosController** (`src/Api/Controllers/EventosController.php`): RESTful API with 15 endpoints

**Database Schema:** 
- 12 main tables in `database/migrations/008_create_eventos_sistema.sql`
- CPF validation functions, triggers, and optimized indexes
- LGPD compliance with data protection features

**Frontend:**
- Real-time dashboard at `public/eventos-dashboard.html`
- Chart.js integration with live metrics updating every 30 seconds
- Modal interfaces for sales and entry validation

### Innovation Systems

**BlockchainIntegration** (`src/Core/Innovation/BlockchainIntegration.php`):
- Immutable transaction recording for critical ERP operations
- Smart contracts for business automation
- Proof-of-work mining with configurable difficulty
- Merkle tree verification for data integrity

**IoTDeviceManager** (`src/Core/Innovation/IoTDeviceManager.php`):
- Enterprise IoT device management with Zero Trust verification
- AI-powered anomaly detection for connected devices
- Automated firmware updates and security compliance
- Real-time device monitoring with WebSocket integration

### AI SUPREMACY ENGINE - Advanced AI Systems

## 🧠 AI Engine Supremo (src/Core/AI/AIEngine.php)
- Comprehensive AI system with 15+ ML/DL models
- Ensemble prediction with model aggregation
- AutoML with hyperparameter optimization
- Transfer learning and federated learning capabilities
- Model drift detection and A/B testing capabilities
- AI explainability (XAI) features
- Real-time inference with <10ms response times
- Support for GPU acceleration and distributed computing

## 🔮 Ultra-Precision Predictive Models (src/Core/AI/PredictiveAnalytics/UltraPrecisionModels.php)
- Predição financeira com 99.9%+ de precisão
- Ensemble de 50+ algoritmos diferentes (Prophet, LSTM, Transformer, ARIMA, XGBoost, Neural ODE)
- Quantum-inspired prediction algorithms
- Ultra-precise demand forecasting com análise hierárquica
- Customer behavior prediction com segmentação avançada
- Dynamic ensemble fusion com adaptive weights
- Uncertainty quantification e confidence intervals
- XAI (Explainable AI) para interpretabilidade

## 🗣️ Supremo NLP Engine (src/Core/AI/NLP/SupremoNLPEngine.php)
- Análise de sentimento multi-idioma com 99.8% precisão
- Extração de entidades com 250+ tipos (NER ultra-avançado)
- Classificação de texto com 500+ categorias
- Sumarização inteligente (extractive, abstractive, hybrid)
- Question Answering com knowledge base
- Análise de tópicos com 8+ algoritmos (LDA, NMF, BERT, CTM)
- Tradução neural em tempo real (100+ idiomas)
- OCR multi-engine com correção inteligente
- Processamento multi-modal (texto, áudio, imagem)

## 👁️ Supremo Vision Engine (src/Core/AI/ComputerVision/SupremoVisionEngine.php)
- Reconhecimento de objetos com 99.9% precisão (1000+ classes)
- OCR ultra-avançado multi-idioma com 8+ engines
- Análise facial completa (emoções, idade, gênero, landmarks)
- Análise de documentos empresariais (invoices, contratos, recibos)
- Detecção de anomalias em tempo real com 8+ algoritmos
- Análise de qualidade de produtos especializada
- Segmentação semântica pixel-perfect
- Anti-spoofing e liveness detection
- Verificação de assinaturas digitais
- Processamento de imagens médicas

### Key Integration Points
- **VirusTotal API**: Real-time threat intelligence
- **Redis**: Session storage, caching, and rate limiting
- **MySQL**: Primary data storage with query optimization
- **Elasticsearch**: Log aggregation and search
- **Prometheus**: Metrics collection and alerting

### Queue System & WebSocket Integration
**QueueManager** (`src/Core/Queue/QueueManager.php`):
- Enterprise queue system supporting 100,000+ jobs/minute
- AI-powered job classification and dynamic priority adjustment
- Auto-scaling worker management based on load
- Redis-based distributed queue with failure recovery

**WebSocketServer** (`src/Core/WebSocket/WebSocketServer.php`):
- Real-time communication supporting 100,000+ concurrent connections
- JWT authentication with channel-based permissions
- Broadcasting system for events and notifications
- Integration with queue system for message processing

### Performance & Monitoring Systems
**UltimatePerformanceEngine** (`src/Core/Performance/UltimatePerformanceEngine.php`):
- JIT compilation optimization and quantum-inspired algorithms
- AI-powered query optimization with learning capabilities
- Predictive scaling based on usage patterns
- Memory optimization with advanced garbage collection

**AlertManager & MLPredictor** (`src/Core/Performance/`):
- Real-time performance monitoring with 50+ metrics
- Machine learning models for performance prediction
- Automated alert system with escalation rules
- Integration with external monitoring tools (Prometheus/Grafana)

### Development Workflow
1. All changes must pass quality checks (`composer quality`)
2. Security tests are mandatory for security-related changes  
3. Performance impact must be analyzed for core changes
4. Database changes require migrations with proper rollback scripts
5. API changes require documentation updates in `docs/api/`
6. Docker configuration testing required for production deployment
7. Events system changes must include CPF validation testing
8. AI model changes require performance benchmark validation