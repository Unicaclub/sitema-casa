# 🚀 Guia de Deploy - ERP Sistema Enterprise

## Visão Geral

Este guia fornece instruções completas para deploy do sistema ERP com arquitetura de segurança enterprise, incluindo todos os componentes de cibersegurança suprema com IA.

## 📋 Pré-requisitos

### Requisitos de Sistema

- **PHP**: 8.2 ou superior
- **Composer**: 2.4 ou superior
- **MySQL/MariaDB**: 8.0 ou superior
- **Redis**: 7.0 ou superior (para cache e sessões)
- **Node.js**: 18 ou superior (para assets)
- **Nginx/Apache**: Para servidor web
- **SSL Certificate**: Obrigatório para produção

### Requisitos de Hardware

**Mínimo (Desenvolvimento):**
- CPU: 4 cores
- RAM: 8GB
- Storage: 50GB SSD
- Bandwidth: 100Mbps

**Recomendado (Produção):**
- CPU: 8+ cores (Intel Xeon ou AMD EPYC)
- RAM: 32GB+ 
- Storage: 500GB+ NVMe SSD
- Bandwidth: 1Gbps+
- Redundância: Load Balancer + Multiple instances

### Dependências Externas

- **SMTP Server**: Para notificações de segurança
- **Backup Storage**: S3, Google Cloud Storage ou similar
- **Monitoring**: Integração com SIEM (opcional)
- **DNS**: Configuração adequada para subdomínios

## 🔧 Instalação

### 1. Preparação do Ambiente

```bash
# Clonar o repositório
git clone https://github.com/your-org/erp-sistema.git
cd erp-sistema

# Criar arquivo de ambiente
cp .env.example .env

# Instalar dependências PHP
composer install --no-dev --optimize-autoloader

# Instalar dependências Node.js
npm install
npm run production
```

### 2. Configuração do Banco de Dados

```sql
-- Criar banco de dados
CREATE DATABASE erp_sistema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Criar usuário dedicado
CREATE USER 'erp_user'@'localhost' IDENTIFIED BY 'sua_senha_segura';
GRANT ALL PRIVILEGES ON erp_sistema.* TO 'erp_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Configuração do .env

```bash
# Configurações básicas
APP_NAME="ERP Sistema Enterprise"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.suaempresa.com

# Banco de dados
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=erp_sistema
DB_USERNAME=erp_user
DB_PASSWORD=sua_senha_segura

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=redis_senha_segura
REDIS_PORT=6379

# E-mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.suaempresa.com
MAIL_PORT=587
MAIL_USERNAME=noreply@suaempresa.com
MAIL_PASSWORD=senha_email
MAIL_ENCRYPTION=tls

# Segurança
JWT_SECRET=sua_jwt_secret_key_256_bits
ENCRYPTION_KEY=sua_encryption_key_256_bits
AUDIT_RETENTION_DAYS=2555

# Backup
BACKUP_DISK=s3
AWS_ACCESS_KEY_ID=sua_aws_key
AWS_SECRET_ACCESS_KEY=sua_aws_secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=erp-backups

# Threat Intelligence APIs
VIRUSTOTAL_API_KEY=sua_virustotal_key
MISP_URL=https://misp.suaempresa.com
MISP_API_KEY=sua_misp_key

# Monitoring
SIEM_ENDPOINT=https://siem.suaempresa.com/api
SIEM_API_KEY=sua_siem_key
```

### 4. Executar Migrações

```bash
# Executar migrações do banco
php artisan migrate

# Popular dados iniciais
php artisan db:seed

# Gerar chaves de criptografia
php artisan key:generate
php artisan encrypt:keys

# Configurar permissões
chmod -R 755 storage/
chmod -R 755 bootstrap/cache/
chown -R www-data:www-data storage/
chown -R www-data:www-data bootstrap/cache/
```

## 🔒 Configuração de Segurança

### 1. Configuração do WAF

```bash
# Ativar WAF
php artisan security:waf:enable

# Configurar regras personalizadas
php artisan security:waf:configure --strict
```

### 2. Configuração do IDS/IPS

```bash
# Inicializar IDS
php artisan security:ids:setup

# Treinar modelos de IA
php artisan security:ai:train-models
```

### 3. Configuração Zero Trust

```bash
# Configurar arquitetura Zero Trust
php artisan security:zero-trust:setup

# Configurar políticas de acesso
php artisan security:policies:create
```

### 4. Configuração do SOC

```bash
# Inicializar SOC
php artisan security:soc:initialize

# Carregar playbooks
php artisan security:soc:load-playbooks

# Configurar automação SOAR
php artisan security:soar:configure
```

## 🌐 Configuração do Servidor Web

### Nginx Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name erp.suaempresa.com;
    root /var/www/erp-sistema/public;
    index index.php;

    # SSL Configuration
    ssl_certificate /path/to/ssl/cert.pem;
    ssl_certificate_key /path/to/ssl/private.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    add_header Content-Security-Policy "default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # WAF Integration
    location / {
        try_files $uri $uri/ /index.php?$query_string;
        
        # Rate Limiting
        limit_req zone=login burst=5 nodelay;
        limit_req zone=api burst=20 nodelay;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Security Dashboard
    location /security-dashboard {
        auth_basic "Security Dashboard";
        auth_basic_user_file /etc/nginx/.htpasswd;
    }

    # Block access to sensitive files
    location ~ /\.(env|git|svn) {
        deny all;
        return 404;
    }
}

# Rate Limiting Zones
http {
    limit_req_zone $binary_remote_addr zone=login:10m rate=1r/s;
    limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
}
```

### Apache Configuration

```apache
<VirtualHost *:443>
    ServerName erp.suaempresa.com
    DocumentRoot /var/www/erp-sistema/public
    
    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /path/to/ssl/cert.pem
    SSLCertificateKeyFile /path/to/ssl/private.key
    SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    
    # Security Headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    
    <Directory /var/www/erp-sistema/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    # Block sensitive files
    <FilesMatch "\.(env|git|svn)">
        Require all denied
    </FilesMatch>
</VirtualHost>
```

## 📊 Monitoramento e Logs

### 1. Configuração de Logs

```bash
# Configurar rotação de logs
sudo logrotate -d /etc/logrotate.d/erp-sistema

# Configurar syslog para eventos de segurança
echo "local0.*    /var/log/erp-security.log" >> /etc/rsyslog.conf
systemctl restart rsyslog
```

### 2. Monitoramento de Performance

```bash
# Instalar ferramentas de monitoramento
apt install htop iotop nethogs

# Configurar Prometheus metrics (opcional)
php artisan metrics:configure
```

### 3. Health Checks

```bash
# Configurar health checks automáticos
crontab -e

# Adicionar linha:
*/5 * * * * /usr/bin/php /var/www/erp-sistema/artisan health:check
```

## 🔄 Backup e Disaster Recovery

### 1. Configuração de Backup Automático

```bash
# Configurar backup diário
php artisan backup:configure

# Testar backup
php artisan backup:run --only-db
php artisan backup:run --only-files

# Configurar cron
0 2 * * * /usr/bin/php /var/www/erp-sistema/artisan backup:clean
0 3 * * * /usr/bin/php /var/www/erp-sistema/artisan backup:run
```

### 2. Plano de Disaster Recovery

```bash
# Testar procedimentos de recuperação
php artisan disaster-recovery:test

# Simular falha de sistema
php artisan disaster-recovery:simulate

# Restaurar de backup
php artisan backup:restore --backup-id=backup_20231215_030000
```

## 🔧 Manutenção

### Comandos de Manutenção Regulares

```bash
# Limpeza de cache (diário)
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Otimização (semanal)
php artisan config:cache
php artisan route:cache
php artisan view:cache
composer dump-autoload -o

# Atualizações de segurança (diário)
php artisan security:update-signatures
php artisan security:threat-intel:sync
php artisan security:ai:retrain

# Limpeza de logs antigos (mensal)
php artisan logs:cleanup --days=90
```

### Performance Tuning

```bash
# Configurar OPcache
echo "opcache.enable=1" >> /etc/php/8.2/fpm/conf.d/10-opcache.ini
echo "opcache.memory_consumption=256" >> /etc/php/8.2/fpm/conf.d/10-opcache.ini
echo "opcache.max_accelerated_files=10000" >> /etc/php/8.2/fpm/conf.d/10-opcache.ini

# Configurar PHP-FPM
echo "pm.max_children = 50" >> /etc/php/8.2/fpm/pool.d/www.conf
echo "pm.start_servers = 5" >> /etc/php/8.2/fpm/pool.d/www.conf
echo "pm.min_spare_servers = 5" >> /etc/php/8.2/fpm/pool.d/www.conf
echo "pm.max_spare_servers = 35" >> /etc/php/8.2/fpm/pool.d/www.conf
```

## 🛡️ Checklist de Segurança Pós-Deploy

- [ ] SSL/TLS configurado corretamente
- [ ] Firewall configurado (apenas portas necessárias abertas)
- [ ] WAF ativo e configurado
- [ ] IDS/IPS funcionando
- [ ] Backup automático testado
- [ ] Monitoramento de logs ativo
- [ ] Chaves de criptografia rotacionadas
- [ ] Usuários administrativos configurados
- [ ] Autenticação 2FA ativada
- [ ] Políticas de senha implementadas
- [ ] Auditoria de compliance executada
- [ ] Testes de penetração realizados
- [ ] Documentação de incident response atualizada

## 🚨 Troubleshooting

### Problemas Comuns

**1. Erro de conexão com banco de dados**
```bash
# Verificar conectividade
php artisan tinker
DB::connection()->getPdo();
```

**2. Problemas de permissão**
```bash
# Corrigir permissões
sudo chown -R www-data:www-data storage/ bootstrap/cache/
sudo chmod -R 755 storage/ bootstrap/cache/
```

**3. Erro de SSL**
```bash
# Verificar certificado
openssl x509 -in /path/to/cert.pem -text -noout
```

**4. Performance lenta**
```bash
# Verificar logs de performance
tail -f storage/logs/performance.log

# Analisar queries lentas
php artisan db:monitor
```

## 📞 Suporte

Para suporte técnico ou emergências de segurança:

- **Email**: security@suaempresa.com
- **Phone**: +55 11 9999-9999 (24/7)
- **Escalation**: CTO/CISO

## 📝 Changelog

### v2.0.0 - Cibersegurança Suprema com IA
- Implementação completa do SOC (Security Operations Center)
- WAF inteligente com IA
- Sistema IDS/IPS avançado
- Arquitetura Zero Trust
- Monitoramento AI 24/7
- Threat Intelligence multi-fonte
- Penetration Testing automatizado
- Dashboard de segurança enterprise

---

**⚠️ IMPORTANTE**: Este sistema contém componentes de segurança críticos. Qualquer alteração deve ser aprovada pela equipe de segurança e testada em ambiente de homologação antes do deploy em produção.