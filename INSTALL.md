# 🚀 Guia de Instalação - ERP Sistema

## 📋 Pré-requisitos

### Requisitos do Sistema
- **PHP 8.0+** com extensões:
  - PDO
  - MySQL
  - Redis
  - JSON
  - BCMath
  - GD
  - Zip
- **MySQL 8.0+**
- **Redis 6.0+**
- **Composer**
- **Node.js 16+** (para assets)

### Ambiente Recomendado
- **XAMPP/WAMP** (Windows)
- **LAMP/LEMP** (Linux)
- **Docker** (Multiplataforma)

---

## 🛠️ Instalação Rápida

### 1. Clone/Download do Projeto
```bash
# Se usando Git
git clone [repository-url] erp-sistema
cd erp-sistema

# Ou extraia o ZIP baixado
```

### 2. Configuração de Ambiente
```bash
# Copie o arquivo de configuração
copy .env.example .env

# Edite as configurações no .env
notepad .env
```

### 3. Instalação de Dependências
```bash
# Instale dependências PHP
composer install

# Instale dependências Node.js (se aplicável)
npm install
```

### 4. Configuração do Banco
```bash
# Crie o banco de dados MySQL
mysql -u root -p
CREATE DATABASE erp_sistema;
EXIT;

# Execute a instalação automática
php artisan install
```

### 5. Inicie o Servidor
```bash
# Servidor de desenvolvimento
php artisan serve

# Ou configure Apache/Nginx apontando para /public
```

---

## 🐳 Instalação com Docker

### 1. Clone e Configure
```bash
git clone [repository-url] erp-sistema
cd erp-sistema
copy .env.example .env
```

### 2. Suba os Containers
```bash
docker-compose up -d
```

### 3. Execute a Instalação
```bash
docker-compose exec app php artisan install
```

### 4. Acesse o Sistema
- **URL:** http://localhost
- **Admin:** admin@demo.com
- **Senha:** admin123

---

## ⚙️ Configuração Manual

### 1. Arquivo .env
```env
# Aplicação
APP_NAME="ERP Sistema"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost

# Banco de Dados
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=erp_sistema
DB_USERNAME=root
DB_PASSWORD=sua_senha

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# Email (opcional)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=seu_email@gmail.com
MAIL_PASSWORD=sua_senha
```

### 2. Banco de Dados
```bash
# Conecte ao MySQL
mysql -u root -p

# Crie o banco
CREATE DATABASE erp_sistema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Importe o schema
mysql -u root -p erp_sistema < database/schema.sql
```

### 3. Permissões (Linux/Mac)
```bash
chmod -R 755 storage/
chmod -R 755 public/assets/
chown -R www-data:www-data storage/
```

---

## 📱 Primeiro Acesso

### 1. Login Inicial
- **URL:** http://localhost/
- **Email:** admin@demo.com
- **Senha:** admin123

### 2. Configuração Inicial
1. **Perfil da Empresa**
   - Nome da empresa
   - CNPJ/CPF
   - Endereço
   - Logo

2. **Configurações Gerais**
   - Timezone
   - Moeda
   - Formato de data
   - Idioma

3. **Usuários e Permissões**
   - Criar usuários
   - Definir perfis
   - Configurar permissões

---

## 🔧 Comandos Úteis

### Artisan CLI
```bash
# Instalar sistema
php artisan install

# Migrar banco
php artisan migrate

# Limpar cache
php artisan cache:clear

# Processar fila
php artisan queue:work

# Criar backup
php artisan backup:create

# Criar usuário
php artisan user:create

# Servidor desenvolvimento
php artisan serve
```

### Maintenance
```bash
# Verificar logs
tail -f storage/logs/app-$(date +%Y-%m-%d).log

# Verificar status Redis
redis-cli ping

# Verificar status MySQL
mysql -u root -p -e "SHOW PROCESSLIST;"
```

---

## 📊 Estrutura dos Módulos

### Dashboard
- Métricas em tempo real
- Gráficos de vendas
- Alertas importantes
- Widgets configuráveis

### CRM
- Cadastro de clientes
- Pipeline de vendas
- Histórico de interações
- Segmentação

### Estoque
- Produtos e categorias
- Controle de estoque
- Movimentações
- Alertas de estoque baixo

### PDV
- Vendas rápidas
- Múltiplas formas de pagamento
- Impressão de cupons
- Controle de caixa

### Financeiro
- Contas a pagar/receber
- Fluxo de caixa
- Relatórios financeiros
- Conciliação bancária

### Marketing
- Campanhas
- Gestão de leads
- Automação
- Métricas

### BI
- Relatórios avançados
- Dashboards executivos
- Análises preditivas
- Exportações

---

## 🔒 Segurança

### Configurações Recomendadas
```bash
# Gere chave de aplicação única
php -r "echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . '\n';"

# Configure HTTPS em produção
# Configure firewall
# Mantenha sistema atualizado
```

### Backup Automático
```bash
# Adicione ao crontab (Linux)
0 2 * * * cd /path/to/erp && php artisan backup:create

# Ou configure task scheduler (Windows)
```

---

## 🐛 Solução de Problemas

### Erro de Conexão com Banco
1. Verifique credenciais no `.env`
2. Teste conexão: `mysql -u root -p`
3. Verifique se MySQL está rodando

### Erro de Redis
1. Instale Redis: `apt install redis-server`
2. Inicie serviço: `systemctl start redis`
3. Teste: `redis-cli ping`

### Permissões de Arquivo
```bash
# Linux/Mac
sudo chown -R www-data:www-data storage/
sudo chmod -R 755 storage/

# Windows (execute como admin)
icacls storage /grant Everyone:F /T
```

### Cache/Performance
```bash
# Limpe todos os caches
php artisan cache:clear

# Otimize autoloader
composer dump-autoload -o

# Configure OPcache no php.ini
opcache.enable=1
opcache.memory_consumption=256
```

---

## 📈 Próximos Passos

### 1. Configuração Avançada
- [ ] Configurar integrações (PIX, NFe, etc.)
- [ ] Personalizar dashboards
- [ ] Configurar relatórios
- [ ] Setup de backup automático

### 2. Personalização
- [ ] Logo e cores da empresa
- [ ] Campos personalizados
- [ ] Workflows específicos
- [ ] Integração com sistemas externos

### 3. Treinamento da Equipe
- [ ] Manual do usuário
- [ ] Treinamento por módulo
- [ ] Definição de processos
- [ ] Configuração de permissões

---

## 📞 Suporte

### Documentação
- **Manual do usuário:** `/docs/user-manual.md`
- **API Documentation:** `/docs/api.md`
- **Development Guide:** `/docs/development.md`

### Logs e Debug
- **Application Logs:** `storage/logs/`
- **Error Logs:** Verifique logs do servidor web
- **Debug Mode:** Configure `APP_DEBUG=true` no `.env`

### Performance Monitoring
- **Sistema:** `/admin/system-status`
- **Database:** `/admin/database-status`
- **Cache:** `/admin/cache-status`

---

🎉 **Parabéns!** Seu sistema ERP está pronto para uso. Explore os módulos e configure conforme suas necessidades!

Para dúvidas ou suporte, consulte a documentação completa em `/docs/` ou contate o suporte técnico.
