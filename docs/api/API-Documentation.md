# 📚 Documentação da API REST - Sistema ERP

## 🔗 Informações Gerais

- **Base URL:** `https://api.erp-sistema.com/api/v1`
- **Formato:** JSON
- **Autenticação:** Bearer Token (JWT)
- **Rate Limit:** 100 requests por 5 minutos por IP
- **Versionamento:** Através da URL (`/api/v1/`)

## 🔐 Autenticação

### Login
```http
POST /auth/login
Content-Type: application/json

{
  "email": "usuario@empresa.com",
  "password": "senha123",
  "two_factor_code": "123456" // opcional, se 2FA ativado
}
```

**Resposta de Sucesso (200):**
```json
{
  "success": true,
  "message": "Login realizado com sucesso",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "refresh_token": "def502004f8a5c...",
    "expires_in": 3600,
    "user": {
      "id": 1,
      "name": "João Silva",
      "email": "usuario@empresa.com",
      "company": {
        "id": 1,
        "name": "Empresa LTDA"
      },
      "permissions": ["dashboard.view", "sales.create"]
    }
  }
}
```

### Refresh Token
```http
POST /auth/refresh
Authorization: Bearer {refresh_token}
```

### Logout
```http
POST /auth/logout
Authorization: Bearer {token}
```

## 📊 Dashboard

### Métricas Principais
```http
GET /dashboard/metrics?period=30
Authorization: Bearer {token}
```

**Parâmetros:**
- `period` (opcional): Período em dias (padrão: 30)

**Resposta:**
```json
{
  "success": true,
  "data": {
    "sales": {
      "total": 156,
      "revenue": 87500.00,
      "avg_ticket": 560.90,
      "today_sales": 8,
      "today_revenue": 4200.00
    },
    "clients": {
      "total": 340,
      "new": 12
    },
    "stock": {
      "products_out_stock": 3
    },
    "financial": {
      "overdue_count": 5,
      "overdue_amount": 2500.00,
      "income": 85000.00,
      "expense": 45000.00,
      "balance": 40000.00
    }
  }
}
```

### Gráfico de Vendas
```http
GET /dashboard/sales-chart?type=monthly&period=12
Authorization: Bearer {token}
```

**Parâmetros:**
- `type`: `daily`, `weekly`, `monthly` (padrão: monthly)
- `period`: número de períodos (padrão: 12)

### Top Produtos
```http
GET /dashboard/top-products?limit=10&period=30
Authorization: Bearer {token}
```

### Alertas do Sistema
```http
GET /dashboard/alerts
Authorization: Bearer {token}
```

### Salvar Configuração de Widgets
```http
POST /dashboard/widgets/save
Authorization: Bearer {token}
Content-Type: application/json

{
  "widgets": [
    {"id": "sales_metrics", "position": 1, "size": "large"},
    {"id": "financial_summary", "position": 2, "size": "medium"}
  ]
}
```

## 👥 CRM - Clientes

### Listar Clientes
```http
GET /crm/clients?page=1&limit=20&search=joão&status=active
Authorization: Bearer {token}
```

**Parâmetros de Query:**
- `page`: Página (padrão: 1)
- `limit`: Itens por página (padrão: 20, máx: 100)
- `search`: Busca por nome, email ou documento
- `status`: `active`, `inactive`, `blocked`
- `created_from`: Data criação início (Y-m-d)
- `created_to`: Data criação fim (Y-m-d)

**Resposta:**
```json
{
  "success": true,
  "data": {
    "clients": [
      {
        "id": 1,
        "name": "João Silva",
        "email": "joao@email.com",
        "phone": "(11) 99999-9999",
        "document": "123.456.789-00",
        "company_name": "Silva & Cia",
        "status": "active",
        "total_purchases": 15,
        "total_spent": 5400.00,
        "last_purchase": "2025-08-01",
        "created_at": "2025-07-01T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 340,
      "last_page": 17,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

### Criar Cliente
```http
POST /crm/clients
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Maria Santos",
  "email": "maria@email.com",
  "phone": "(11) 88888-8888",
  "document": "987.654.321-00",
  "company_name": "Santos LTDA",
  "address": {
    "street": "Rua das Flores, 123",
    "city": "São Paulo",
    "state": "SP",
    "zip_code": "01234-567"
  },
  "notes": "Cliente preferencial"
}
```

### Obter Cliente
```http
GET /crm/clients/{id}
Authorization: Bearer {token}
```

### Atualizar Cliente
```http
PUT /crm/clients/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Maria Santos Silva",
  "phone": "(11) 77777-7777"
}
```

### Excluir Cliente
```http
DELETE /crm/clients/{id}
Authorization: Bearer {token}
```

## 📦 Estoque

### Listar Produtos
```http
GET /stock/products?page=1&category=eletrônicos&status=active&low_stock=true
Authorization: Bearer {token}
```

**Parâmetros:**
- `category`: Filtrar por categoria
- `status`: `active`, `inactive`
- `low_stock`: `true` para produtos com estoque baixo
- `out_of_stock`: `true` para produtos sem estoque

### Criar Produto
```http
POST /stock/products
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Smartphone XYZ",
  "sku": "SMART-001",
  "description": "Smartphone com 128GB",
  "category_id": 5,
  "purchase_price": 800.00,
  "sale_price": 1200.00,
  "current_stock": 50,
  "min_stock": 10,
  "max_stock": 200,
  "weight": 0.180,
  "dimensions": "15x7x0.8",
  "barcode": "1234567890123",
  "supplier_id": 3,
  "images": ["image1.jpg", "image2.jpg"]
}
```

### Movimentação de Estoque
```http
POST /stock/movements
Authorization: Bearer {token}
Content-Type: application/json

{
  "product_id": 1,
  "type": "in", // "in" ou "out"
  "quantity": 20,
  "reason": "purchase", // "purchase", "sale", "adjustment", "return"
  "reference_id": 123, // ID da compra/venda relacionada
  "notes": "Reposição de estoque"
}
```

## 💰 Financeiro

### Contas a Receber
```http
GET /financial/receivables?status=pending&due_date_from=2025-08-01
Authorization: Bearer {token}
```

**Parâmetros:**
- `status`: `pending`, `paid`, `partial`, `overdue`, `cancelled`
- `due_date_from/to`: Filtros de data de vencimento
- `client_id`: Filtrar por cliente

### Criar Conta a Receber
```http
POST /financial/receivables
Authorization: Bearer {token}
Content-Type: application/json

{
  "client_id": 1,
  "description": "Venda #123",
  "amount": 1500.00,
  "due_date": "2025-09-01",
  "installments": 3, // opcional, para parcelamento
  "payment_method": "credit_card",
  "category_id": 2,
  "reference_type": "sale",
  "reference_id": 123
}
```

### Registrar Pagamento
```http
POST /financial/receivables/{id}/payments
Authorization: Bearer {token}
Content-Type: application/json

{
  "amount": 500.00,
  "payment_date": "2025-08-03",
  "payment_method": "bank_transfer",
  "notes": "Pagamento parcial",
  "bank_account_id": 1
}
```

### Contas a Pagar
```http
GET /financial/payables
Authorization: Bearer {token}
```

### Fluxo de Caixa
```http
GET /financial/cash-flow?start_date=2025-08-01&end_date=2025-08-31
Authorization: Bearer {token}
```

## 🛒 PDV (Ponto de Venda)

### Criar Venda
```http
POST /pos/sales
Authorization: Bearer {token}
Content-Type: application/json

{
  "client_id": 1, // opcional
  "items": [
    {
      "product_id": 1,
      "quantity": 2,
      "unit_price": 50.00,
      "discount": 5.00
    },
    {
      "product_id": 2,
      "quantity": 1,
      "unit_price": 30.00,
      "discount": 0.00
    }
  ],
  "payment": {
    "method": "credit_card",
    "amount": 125.00,
    "installments": 1
  },
  "discount_type": "percentage", // "percentage" ou "amount"
  "discount_value": 0,
  "notes": "Venda balcão"
}
```

**Resposta:**
```json
{
  "success": true,
  "message": "Venda criada com sucesso",
  "data": {
    "id": 156,
    "number": "VD-2025-0156",
    "total_amount": 125.00,
    "status": "completed",
    "created_at": "2025-08-03T14:30:00Z",
    "items": [
      {
        "product_name": "Produto A",
        "quantity": 2,
        "unit_price": 50.00,
        "total_price": 100.00
      }
    ]
  }
}
```

### Listar Vendas
```http
GET /pos/sales?date_from=2025-08-01&status=completed
Authorization: Bearer {token}
```

### Cancelar Venda
```http
POST /pos/sales/{id}/cancel
Authorization: Bearer {token}
Content-Type: application/json

{
  "reason": "Produto com defeito",
  "refund_payment": true
}
```

## 📈 Relatórios

### Relatório de Vendas
```http
GET /reports/sales?start_date=2025-08-01&end_date=2025-08-31&format=json
Authorization: Bearer {token}
```

**Parâmetros:**
- `format`: `json`, `csv`, `pdf`
- `group_by`: `day`, `week`, `month`, `product`, `client`

### Relatório Financeiro
```http
GET /reports/financial?month=2025-08&type=summary
Authorization: Bearer {token}
```

### Relatório de Estoque
```http
GET /reports/inventory?category=all&status=low_stock
Authorization: Bearer {token}
```

## ⚙️ Sistema

### Configurações da Empresa
```http
GET /system/company/settings
Authorization: Bearer {token}
```

### Atualizar Configurações
```http
PUT /system/company/settings
Authorization: Bearer {token}
Content-Type: application/json

{
  "company_name": "Nova Empresa LTDA",
  "email": "contato@novaempresa.com",
  "phone": "(11) 3333-3333",
  "logo": "logo.png",
  "settings": {
    "tax_rate": 10.5,
    "currency": "BRL",
    "timezone": "America/Sao_Paulo"
  }
}
```

### Upload de Arquivos
```http
POST /system/upload
Authorization: Bearer {token}
Content-Type: multipart/form-data

{
  "file": [arquivo],
  "type": "product_image", // "product_image", "company_logo", "document"
  "reference_id": 123 // opcional
}
```

## 📋 Códigos de Status

| Código | Descrição |
|--------|-----------|
| 200 | OK - Sucesso |
| 201 | Created - Recurso criado |
| 204 | No Content - Sucesso sem conteúdo |
| 400 | Bad Request - Dados inválidos |
| 401 | Unauthorized - Token inválido/expirado |
| 403 | Forbidden - Sem permissão |
| 404 | Not Found - Recurso não encontrado |
| 422 | Validation Error - Erro de validação |
| 429 | Rate Limit - Muitas requisições |
| 500 | Internal Error - Erro interno |

## 🔧 Formato de Erro Padrão

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Os dados fornecidos são inválidos",
    "details": {
      "email": ["O campo email é obrigatório"],
      "phone": ["O formato do telefone é inválido"]
    }
  },
  "timestamp": "2025-08-03T14:30:00Z"
}
```

## 🔍 Filtros e Ordenação

### Filtros Comuns
- `search`: Busca em campos de texto
- `status`: Filtrar por status
- `created_from/to`: Filtros de data de criação
- `updated_from/to`: Filtros de data de atualização

### Ordenação
```http
GET /crm/clients?sort_by=name&sort_order=asc
```

- `sort_by`: Campo para ordenação
- `sort_order`: `asc` ou `desc`

### Paginação
```http
GET /crm/clients?page=2&limit=50
```

- `page`: Número da página (início: 1)
- `limit`: Itens por página (máx: 100)

## 🔐 Permissões

O sistema utiliza permissões granulares baseadas em módulos:

- `dashboard.*` - Acesso ao dashboard
- `crm.*` - Gestão de clientes
- `stock.*` - Gestão de estoque
- `pos.*` - Ponto de venda
- `financial.*` - Gestão financeira
- `reports.*` - Relatórios
- `system.*` - Configurações do sistema

Permissões específicas:
- `*.view` - Visualizar
- `*.create` - Criar
- `*.edit` - Editar
- `*.delete` - Excluir

## 📝 Webhooks

Configure webhooks para receber notificações de eventos:

```http
POST /system/webhooks
Authorization: Bearer {token}
Content-Type: application/json

{
  "url": "https://meusite.com/webhook",
  "events": ["sale.created", "payment.received"],
  "secret": "webhook_secret_key"
}
```

**Eventos Disponíveis:**
- `sale.created` - Nova venda criada
- `sale.cancelled` - Venda cancelada
- `payment.received` - Pagamento recebido
- `stock.low` - Estoque baixo
- `client.created` - Novo cliente

## 🚀 SDKs e Integrações

### cURL
```bash
curl -X GET "https://api.erp-sistema.com/api/v1/dashboard/metrics" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

### JavaScript/Node.js
```javascript
const response = await fetch('https://api.erp-sistema.com/api/v1/dashboard/metrics', {
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json'
  }
});
const data = await response.json();
```

### PHP
```php
$curl = curl_init();
curl_setopt_array($curl, [
  CURLOPT_URL => 'https://api.erp-sistema.com/api/v1/dashboard/metrics',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer YOUR_TOKEN',
    'Content-Type: application/json'
  ]
]);
$response = curl_exec($curl);
$data = json_decode($response, true);
```

## 🐛 Suporte e Troubleshooting

### Rate Limiting
Se receber erro 429, aguarde o tempo especificado no header `Retry-After`.

### Autenticação
Tokens JWT expiram em 1 hora. Use refresh token para renovar.

### Validação
Campos obrigatórios retornam erro 422 com detalhes específicos.

### Logs
Todas as requisições são logadas para auditoria e troubleshooting.

## 📚 Recursos Adicionais

- **Postman Collection**: [Download](https://api.erp-sistema.com/postman)
- **OpenAPI Spec**: [Download](https://api.erp-sistema.com/openapi.json)
- **Status Page**: [https://status.erp-sistema.com](https://status.erp-sistema.com)
- **Suporte**: support@erp-sistema.com