# ERP Sistema - Docker Production Setup

## 🐳 Docker Configuration

Este projeto está configurado para execução em ambiente de produção usando Docker com as melhores práticas de segurança e performance.

## 📋 Pré-requisitos

- Docker Engine 20.10+
- Docker Compose 2.0+
- 4GB RAM mínimo (8GB recomendado)
- 20GB espaço em disco (50GB+ para produção)

## 🚀 Quick Start

### 1. Configuração do Ambiente

```bash
# Copiar arquivo de ambiente
cp .env.example .env

# Editar configurações (OBRIGATÓRIO)
nano .env
```

**Configurações obrigatórias no .env:**
```bash
# Senhas de segurança (ALTERAR)
DB_PASSWORD=sua_senha_mysql_super_segura
DB_ROOT_PASSWORD=sua_senha_root_mysql_super_segura
REDIS_PASSWORD=sua_senha_redis_super_segura
JWT_SECRET=sua_chave_jwt_256_bits_super_segura_32_caracteres_minimo
GRAFANA_PASSWORD=sua_senha_grafana

# API Keys
VIRUSTOTAL_API_KEY=sua_chave_virustotal
```

### 2. Deploy

```bash
# Build e inicialização
docker-compose up -d --build

# Verificar status
docker-compose ps
```

### 3. Verificação

```bash
# Health checks
docker-compose exec app /usr/local/bin/healthcheck.sh

# Logs
docker-compose logs -f app
```

## 🏗️ Arquitetura

### Serviços

| Serviço | Porta | Descrição |
|---------|-------|-----------|
| **nginx** | 80, 443 | Load Balancer & Reverse Proxy |
| **app** | 9000 | Aplicação PHP-FPM (2 réplicas) |
| **database** | 3306 | MySQL 8.0 |
| **redis** | 6379 | Cache & Sessions |
| **elasticsearch** | 9200 | Logs & Search |
| **kibana** | 5601 | Log Visualization |
| **prometheus** | 9090 | Metrics Collection |
| **grafana** | 3000 | Metrics Dashboard |
| **backup** | - | Automated Backups |

### Volumes Persistentes

- `mysql_data`: Dados do MySQL
- `redis_data`: Dados do Redis
- `elasticsearch_data`: Dados do Elasticsearch
- `prometheus_data`: Métricas do Prometheus
- `grafana_data`: Dashboards do Grafana
- `backup_data`: Backups automatizados

## 🔒 Segurança

### Recursos Implementados

- ✅ **Multi-stage Docker builds** para imagens otimizadas
- ✅ **Non-root containers** para segurança
- ✅ **Health checks** em todos os serviços
- ✅ **Rate limiting** no Nginx
- ✅ **Security headers** configurados
- ✅ **WAF integrado** para proteção de aplicação
- ✅ **Backup automatizado** com retenção
- ✅ **Monitoramento completo** com alertas

### Headers de Segurança

```nginx
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=63072000
Content-Security-Policy: default-src 'self'
```

### Rate Limiting

- **Login**: 5 requests/minuto
- **API**: 100 requests/minuto
- **Geral**: 300 requests/minuto

## 📊 Monitoramento

### Dashboards Disponíveis

- **Grafana**: http://localhost:3000 (admin/admin)
- **Kibana**: http://localhost:5601
- **Prometheus**: http://localhost:9090
- **Security Dashboard**: http://localhost/security-dashboard

### Métricas Coletadas

- Performance da aplicação
- Uso de recursos (CPU, RAM, Disk)
- Métricas de segurança (ataques bloqueados)
- Status dos serviços
- Logs de aplicação e segurança

## 💾 Backup & Restore

### Backup Automatizado

```bash
# Backup manual
docker-compose exec backup /scripts/backup.sh

# Verificar backups
docker-compose exec backup ls -la /backups/
```

### Restore

```bash
# Restaurar MySQL
docker-compose exec database mysql -u root -p < backup.sql

# Restaurar Redis
docker-compose exec redis redis-cli --rdb /data/restore.rdb
```

## 🔧 Configurações Avançadas

### SSL/HTTPS

```bash
# 1. Colocar certificados em docker/ssl/
# 2. Descomentar configuração HTTPS no nginx
# 3. Atualizar .env
HTTPS_ENABLED=true
```

### Scaling

```bash
# Escalar aplicação
docker-compose up -d --scale app=4

# Escalar com recursos limitados
docker-compose --compatibility up -d
```

### Performance Tuning

**PHP-FPM** (`docker/php/www.conf`):
```ini
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
```

**MySQL** (`docker/mysql/my.cnf`):
```ini
innodb_buffer_pool_size = 1G
max_connections = 200
query_cache_size = 64M
```

**Redis** (`docker/redis/redis.conf`):
```ini
maxmemory 512mb
maxmemory-policy allkeys-lru
```

## 🚨 Troubleshooting

### Problemas Comuns

**1. Erro de Conexão com Banco**
```bash
# Verificar status
docker-compose exec database mysqladmin ping -h localhost -u root -p

# Logs do MySQL
docker-compose logs database
```

**2. Erro de Memória**
```bash
# Verificar uso de recursos
docker stats

# Aumentar limite no docker-compose.yml
deploy:
  resources:
    limits:
      memory: 2G
```

**3. Erro de Permissão**
```bash
# Corrigir permissões
docker-compose exec app chown -R www:www /var/www/html/storage
```

### Comandos Úteis

```bash
# Reiniciar serviço específico
docker-compose restart app

# Ver logs em tempo real
docker-compose logs -f --tail=100 app

# Executar comando no container
docker-compose exec app php -v

# Limpar tudo (CUIDADO!)
docker-compose down -v --remove-orphans
```

## 📈 Otimização para Produção

### Checklist Pré-Deploy

- [ ] Senhas alteradas no `.env`
- [ ] SSL configurado
- [ ] Backups testados
- [ ] Monitoramento configurado
- [ ] Health checks passando
- [ ] Rate limiting configurado
- [ ] Logs centralizados
- [ ] Alertas configurados

### Recursos Recomendados

**Servidor Mínimo:**
- 4 CPU cores
- 8GB RAM
- 50GB SSD
- 100Mbps network

**Servidor Recomendado:**
- 8 CPU cores
- 16GB RAM
- 100GB SSD
- 1Gbps network

## 🔗 Links Úteis

- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Reference](https://docs.docker.com/compose/)
- [Nginx Configuration](https://nginx.org/en/docs/)
- [MySQL Performance Tuning](https://dev.mysql.com/doc/refman/8.0/en/optimization.html)
- [Redis Configuration](https://redis.io/topics/config)

## 📧 Suporte

Para problemas relacionados ao Docker:
1. Verificar logs: `docker-compose logs`
2. Consultar documentação oficial
3. Verificar recursos do sistema
4. Revisar configurações de rede