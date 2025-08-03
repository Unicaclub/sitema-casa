@echo off
echo ==========================================
echo    ERP SISTEMA - SETUP AUTOMATICO
echo ==========================================
echo.

echo [1/6] Verificando pre-requisitos...
php --version >nul 2>&1
if errorlevel 1 (
    echo ❌ PHP nao encontrado! Instale PHP 8.0+ primeiro.
    pause
    exit /b 1
)
echo ✅ PHP encontrado

echo.
echo [2/6] Copiando arquivo de configuracao...
if not exist ".env" (
    copy ".env.example" ".env"
    echo ✅ Arquivo .env criado
) else (
    echo ⚠️  Arquivo .env ja existe
)

echo.
echo [3/6] Gerando chave da aplicacao...
for /f "delims=" %%i in ('php -r "echo 'APP_KEY=base64:' . base64_encode(random_bytes(32));"') do set APP_KEY=%%i
echo %APP_KEY% >> .env.tmp
findstr /v "APP_KEY=" .env > .env.tmp2
type .env.tmp2 > .env
type .env.tmp >> .env
del .env.tmp .env.tmp2
echo ✅ Chave gerada e configurada

echo.
echo [4/6] Criando diretorios necessarios...
if not exist "storage\logs" mkdir "storage\logs"
if not exist "storage\uploads" mkdir "storage\uploads"
if not exist "storage\cache" mkdir "storage\cache"
if not exist "storage\backups" mkdir "storage\backups"
echo ✅ Diretorios criados

echo.
echo [5/6] Configurando permissoes...
icacls "storage" /grant Everyone:F /T >nul 2>&1
icacls "public\assets" /grant Everyone:F /T >nul 2>&1
echo ✅ Permissoes configuradas

echo.
echo [6/6] Sistema pronto!
echo.
echo ==========================================
echo           CONFIGURACAO MANUAL
echo ==========================================
echo.
echo Proximos passos:
echo.
echo 1. Configure o banco de dados no arquivo .env:
echo    - DB_HOST=127.0.0.1
echo    - DB_DATABASE=erp_sistema
echo    - DB_USERNAME=root
echo    - DB_PASSWORD=sua_senha
echo.
echo 2. Configure o Redis no arquivo .env:
echo    - REDIS_HOST=127.0.0.1
echo    - REDIS_PORT=6379
echo.
echo 3. Execute a instalacao:
echo    php artisan install
echo.
echo 4. Inicie o servidor:
echo    php artisan serve
echo.
echo ==========================================

pause
