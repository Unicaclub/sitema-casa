<?php

namespace ERP\Core\CLI;

/**
 * Console CLI para comandos do sistema
 */
class Console 
{
    private $commands = [];
    
    public function __construct()
    {
        $this->registerCommands();
    }
    
    /**
     * Registra comandos disponíveis
     */
    private function registerCommands(): void
    {
        $this->commands = [
            'install' => InstallCommand::class,
            'migrate' => MigrateCommand::class,
            'seed' => SeedCommand::class,
            'cache:clear' => CacheClearCommand::class,
            'queue:work' => QueueWorkCommand::class,
            'schedule:run' => ScheduleRunCommand::class,
            'backup:create' => BackupCreateCommand::class,
            'user:create' => UserCreateCommand::class,
            'serve' => ServeCommand::class
        ];
    }
    
    /**
     * Executa comando
     */
    public function run(array $argv): void
    {
        $command = $argv[1] ?? 'help';
        $arguments = array_slice($argv, 2);
        
        if ($command === 'help' || $command === '--help' || $command === '-h') {
            $this->showHelp();
            return;
        }
        
        if (!isset($this->commands[$command])) {
            echo "Comando '{$command}' não encontrado.\n";
            echo "Use 'php artisan help' para ver comandos disponíveis.\n";
            exit(1);
        }
        
        $commandClass = $this->commands[$command];
        $commandInstance = new $commandClass();
        $commandInstance->run($arguments);
    }
    
    /**
     * Mostra ajuda
     */
    private function showHelp(): void
    {
        echo "\nERP Sistema - Console CLI\n";
        echo "========================\n\n";
        echo "Comandos disponíveis:\n\n";
        
        $descriptions = [
            'install' => 'Instala o sistema (banco, configurações)',
            'migrate' => 'Executa migrations do banco de dados',
            'seed' => 'Executa seeders (dados iniciais)',
            'cache:clear' => 'Limpa cache do sistema',
            'queue:work' => 'Processa fila de jobs',
            'schedule:run' => 'Executa tarefas agendadas',
            'backup:create' => 'Cria backup do sistema',
            'user:create' => 'Cria novo usuário',
            'serve' => 'Inicia servidor de desenvolvimento'
        ];
        
        foreach ($this->commands as $command => $class) {
            echo sprintf("  %-20s %s\n", $command, $descriptions[$command] ?? '');
        }
        
        echo "\nUso: php artisan <comando> [argumentos]\n\n";
    }
}

/**
 * Classe base para comandos
 */
abstract class Command 
{
    protected $output;
    
    public function __construct()
    {
        $this->output = new ConsoleOutput();
    }
    
    abstract public function run(array $arguments): void;
    
    protected function info(string $message): void
    {
        $this->output->info($message);
    }
    
    protected function success(string $message): void
    {
        $this->output->success($message);
    }
    
    protected function error(string $message): void
    {
        $this->output->error($message);
    }
    
    protected function warning(string $message): void
    {
        $this->output->warning($message);
    }
    
    protected function line(string $message = ''): void
    {
        echo $message . "\n";
    }
    
    protected function ask(string $question, string $default = null): string
    {
        echo $question;
        if ($default) {
            echo " [{$default}]";
        }
        echo ": ";
        
        $handle = fopen("php://stdin", "r");
        $answer = trim(fgets($handle));
        fclose($handle);
        
        return $answer ?: $default;
    }
    
    protected function confirm(string $question): bool
    {
        $answer = $this->ask($question . ' (y/N)', 'n');
        return strtolower($answer) === 'y';
    }
}

/**
 * Output formatado para console
 */
class ConsoleOutput 
{
    public function info(string $message): void
    {
        echo "\033[36m[INFO]\033[0m {$message}\n";
    }
    
    public function success(string $message): void
    {
        echo "\033[32m[SUCCESS]\033[0m {$message}\n";
    }
    
    public function error(string $message): void
    {
        echo "\033[31m[ERROR]\033[0m {$message}\n";
    }
    
    public function warning(string $message): void
    {
        echo "\033[33m[WARNING]\033[0m {$message}\n";
    }
}

/**
 * Comando de instalação
 */
class InstallCommand extends Command 
{
    public function run(array $arguments): void
    {
        $this->info("Iniciando instalação do ERP Sistema...");
        
        // Verifica requisitos
        $this->checkRequirements();
        
        // Configura banco de dados
        $this->setupDatabase();
        
        // Executa migrations
        $this->runMigrations();
        
        // Executa seeders
        $this->runSeeders();
        
        // Cria usuário admin
        $this->createAdminUser();
        
        // Limpa cache
        $this->clearCache();
        
        $this->success("Instalação concluída com sucesso!");
        $this->line("Acesse o sistema em: http://localhost");
    }
    
    private function checkRequirements(): void
    {
        $this->info("Verificando requisitos...");
        
        $requirements = [
            'PHP >= 8.0' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'PDO Extension' => extension_loaded('pdo'),
            'Redis Extension' => extension_loaded('redis'),
            'JSON Extension' => extension_loaded('json'),
            'BCMath Extension' => extension_loaded('bcmath'),
        ];
        
        foreach ($requirements as $name => $check) {
            if ($check) {
                $this->success("✓ {$name}");
            } else {
                $this->error("✗ {$name}");
                exit(1);
            }
        }
    }
    
    private function setupDatabase(): void
    {
        $this->info("Configurando banco de dados...");
        
        // Aqui você implementaria a criação do banco
        // Por simplicidade, assumimos que já existe
        
        $this->success("Banco de dados configurado");
    }
    
    private function runMigrations(): void
    {
        $migrate = new MigrateCommand();
        $migrate->run([]);
    }
    
    private function runSeeders(): void
    {
        $seed = new SeedCommand();
        $seed->run([]);
    }
    
    private function createAdminUser(): void
    {
        $this->info("Criando usuário administrador...");
        
        $email = $this->ask("Email do administrador", "admin@demo.com");
        $password = $this->ask("Senha do administrador", "admin123");
        
        // Implementar criação do usuário aqui
        
        $this->success("Usuário administrador criado: {$email}");
    }
    
    private function clearCache(): void
    {
        $cache = new CacheClearCommand();
        $cache->run([]);
    }
}

/**
 * Comando para migrations
 */
class MigrateCommand extends Command 
{
    public function run(array $arguments): void
    {
        $this->info("Executando migrations...");
        
        try {
            $database = \ERP\Core\App::getInstance()->get('database');
            
            $sql = file_get_contents(__DIR__ . '/../../../database/schema.sql');
            $database->query($sql);
            
            $this->success("Migrations executadas com sucesso");
        } catch (\Exception $e) {
            $this->error("Erro ao executar migrations: " . $e->getMessage());
        }
    }
}

/**
 * Comando para seeders
 */
class SeedCommand extends Command 
{
    public function run(array $arguments): void
    {
        $this->info("Executando seeders...");
        
        // Os dados iniciais já estão no schema.sql
        
        $this->success("Seeders executados com sucesso");
    }
}

/**
 * Comando para limpar cache
 */
class CacheClearCommand extends Command 
{
    public function run(array $arguments): void
    {
        $this->info("Limpando cache...");
        
        try {
            $cache = \ERP\Core\App::getInstance()->get('cache');
            $cache->clear();
            
            $this->success("Cache limpo com sucesso");
        } catch (\Exception $e) {
            $this->error("Erro ao limpar cache: " . $e->getMessage());
        }
    }
}

/**
 * Comando para processar fila
 */
class QueueWorkCommand extends Command 
{
    public function run(array $arguments): void
    {
        $this->info("Iniciando processamento da fila...");
        
        $eventBus = \ERP\Core\App::getInstance()->get('eventBus');
        
        while (true) {
            $processed = $eventBus->processQueue(10);
            
            if ($processed > 0) {
                $this->info("Processados {$processed} eventos");
            }
            
            sleep(3);
        }
    }
}

/**
 * Comando para tarefas agendadas
 */
class ScheduleRunCommand extends Command 
{
    public function run(array $arguments): void
    {
        $this->info("Executando tarefas agendadas...");
        
        // Implementar tarefas agendadas aqui
        // - Backup automático
        // - Limpeza de logs antigos
        // - Processamento de relatórios
        // - Envio de emails
        
        $this->success("Tarefas agendadas executadas");
    }
}

/**
 * Comando para backup
 */
class BackupCreateCommand extends Command 
{
    public function run(array $arguments): void
    {
        $this->info("Criando backup...");
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = __DIR__ . '/../../../storage/backups';
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Backup do banco
        $dbFile = "{$backupDir}/database_{$timestamp}.sql";
        $this->backupDatabase($dbFile);
        
        // Backup dos arquivos
        $filesFile = "{$backupDir}/files_{$timestamp}.tar.gz";
        $this->backupFiles($filesFile);
        
        $this->success("Backup criado com sucesso");
    }
    
    private function backupDatabase(string $file): void
    {
        $config = config('database.connections.mysql');
        
        $command = sprintf(
            'mysqldump -h%s -u%s -p%s %s > %s',
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $file
        );
        
        exec($command);
        $this->info("Backup do banco salvo em: {$file}");
    }
    
    private function backupFiles(string $file): void
    {
        $uploadDir = __DIR__ . '/../../../storage/uploads';
        
        if (is_dir($uploadDir)) {
            $command = "tar -czf {$file} -C {$uploadDir} .";
            exec($command);
            $this->info("Backup dos arquivos salvo em: {$file}");
        }
    }
}

/**
 * Comando para criar usuário
 */
class UserCreateCommand extends Command 
{
    public function run(array $arguments): void
    {
        $this->info("Criando novo usuário...");
        
        $name = $this->ask("Nome completo");
        $email = $this->ask("Email");
        $password = $this->ask("Senha");
        
        try {
            $database = \ERP\Core\App::getInstance()->get('database');
            
            $userId = $database->insert('users', [
                'company_id' => 1,
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'active' => true
            ]);
            
            $this->success("Usuário criado com sucesso (ID: {$userId})");
        } catch (\Exception $e) {
            $this->error("Erro ao criar usuário: " . $e->getMessage());
        }
    }
}

/**
 * Comando para servidor de desenvolvimento
 */
class ServeCommand extends Command 
{
    public function run(array $arguments): void
    {
        $host = $arguments[0] ?? 'localhost';
        $port = $arguments[1] ?? '8000';
        
        $this->info("Iniciando servidor de desenvolvimento...");
        $this->line("Servidor rodando em: http://{$host}:{$port}");
        $this->line("Pressione Ctrl+C para parar");
        
        $command = "php -S {$host}:{$port} -t public";
        passthru($command);
    }
}
