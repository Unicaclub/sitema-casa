<?php

declare(strict_types=1);

namespace ERP\Core\Backup;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Cache\CacheInterface;
use ERP\Core\Excecoes\ExcecaoValidacao;
use Carbon\Carbon;
use ZipArchive;

/**
 * Gerenciador de Backup do Sistema
 * 
 * Sistema completo de backup e restauração para dados e arquivos
 * 
 * @package ERP\Core\Backup
 */
final class GerenciadorBackup
{
    private string $diretorioBackups;
    private array $tabelasExcluidas = ['sessions', 'cache_entries', 'logs'];
    
    public function __construct(
        private DatabaseManager $database,
        private CacheInterface $cache
    ) {
        $this->diretorioBackups = $_ENV['BACKUP_PATH'] ?? storage_path('backups');
        $this->garantirDiretorioBackup();
    }
    
    /**
     * Criar backup completo do sistema
     */
    public function criarBackupCompleto(string $tenantId, array $opcoes = []): array
    {
        $inicioBackup = microtime(true);
        $nomeBackup = $this->gerarNomeBackup($tenantId, 'completo');
        
        try {
            $arquivoBackup = $this->diretorioBackups . DIRECTORY_SEPARATOR . $nomeBackup . '.zip';
            $zip = new ZipArchive();
            
            if ($zip->open($arquivoBackup, ZipArchive::CREATE) !== TRUE) {
                throw new ExcecaoValidacao('Não foi possível criar o arquivo de backup');
            }
            
            // Backup do banco de dados
            $arquivoBanco = $this->criarBackupBanco($tenantId);
            $zip->addFile($arquivoBanco, 'database.sql');
            
            // Backup de arquivos de configuração
            $this->adicionarArquivosConfiguracao($zip, $tenantId);
            
            // Backup de arquivos uploads (se especificado)
            if ($opcoes['incluir_uploads'] ?? true) {
                $this->adicionarArquivosUploads($zip, $tenantId);
            }
            
            // Metadados do backup
            $metadados = [
                'versao_sistema' => '2.0.0',
                'tenant_id' => $tenantId,
                'data_backup' => Carbon::now()->toISOString(),
                'tipo_backup' => 'completo',
                'opcoes' => $opcoes,
                'hash_verificacao' => $this->calcularHashVerificacao($tenantId),
            ];
            
            $zip->addFromString('backup_metadata.json', json_encode($metadados, JSON_PRETTY_PRINT));
            $zip->close();
            
            // Limpar arquivo temporário do banco
            @unlink($arquivoBanco);
            
            $tempoExecucao = round((microtime(true) - $inicioBackup) * 1000, 2);
            $tamanhoArquivo = filesize($arquivoBackup);
            
            // Registrar backup no banco
            $backupId = $this->registrarBackup([
                'tenant_id' => $tenantId,
                'nome_arquivo' => $nomeBackup . '.zip',
                'caminho_arquivo' => $arquivoBackup,
                'tipo' => 'completo',
                'tamanho_bytes' => $tamanhoArquivo,
                'tempo_execucao_ms' => $tempoExecucao,
                'hash_verificacao' => $metadados['hash_verificacao'],
                'status' => 'concluido',
                'created_at' => Carbon::now(),
            ]);
            
            return [
                'backup_id' => $backupId,
                'nome_arquivo' => $nomeBackup . '.zip',
                'caminho' => $arquivoBackup,
                'tamanho_mb' => round($tamanhoArquivo / 1024 / 1024, 2),
                'tempo_execucao_ms' => $tempoExecucao,
                'data_criacao' => Carbon::now()->format('d/m/Y H:i:s'),
                'hash_verificacao' => $metadados['hash_verificacao'],
            ];
            
        } catch (\Exception $e) {
            // Limpar arquivos temporários em caso de erro
            if (isset($arquivoBanco) && file_exists($arquivoBanco)) {
                @unlink($arquivoBanco);
            }
            if (isset($arquivoBackup) && file_exists($arquivoBackup)) {
                @unlink($arquivoBackup);
            }
            
            throw new ExcecaoValidacao('Erro ao criar backup: ' . $e->getMessage());
        }
    }
    
    /**
     * Restaurar backup do sistema
     */
    public function restaurarBackup(string $caminhoBackup, array $opcoes = []): array
    {
        if (!file_exists($caminhoBackup)) {
            throw new ExcecaoValidacao('Arquivo de backup não encontrado');
        }
        
        $inicioRestauracao = microtime(true);
        
        try {
            $zip = new ZipArchive();
            if ($zip->open($caminhoBackup) !== TRUE) {
                throw new ExcecaoValidacao('Não foi possível abrir o arquivo de backup');
            }
            
            // Extrair e validar metadados
            $metadadosJson = $zip->getFromName('backup_metadata.json');
            if (!$metadadosJson) {
                throw new ExcecaoValidacao('Backup inválido: metadados não encontrados');
            }
            
            $metadados = json_decode($metadadosJson, true);
            $this->validarMetadadosBackup($metadados);
            
            // Criar diretório temporário para extração
            $diretorioTemp = $this->criarDiretorioTemporario();
            $zip->extractTo($diretorioTemp);
            $zip->close();
            
            // Restaurar banco de dados
            if (!($opcoes['pular_banco'] ?? false)) {
                $this->restaurarBancoDados($diretorioTemp . '/database.sql', $metadados['tenant_id']);
            }
            
            // Restaurar arquivos de configuração
            if (!($opcoes['pular_configuracoes'] ?? false)) {
                $this->restaurarArquivosConfiguracao($diretorioTemp, $metadados['tenant_id']);
            }
            
            // Restaurar uploads
            if (!($opcoes['pular_uploads'] ?? false)) {
                $this->restaurarArquivosUploads($diretorioTemp, $metadados['tenant_id']);
            }
            
            // Limpar diretório temporário
            $this->limparDiretorioTemporario($diretorioTemp);
            
            // Limpar cache
            $this->cache->flush();
            
            $tempoExecucao = round((microtime(true) - $inicioRestauracao) * 1000, 2);
            
            return [
                'sucesso' => true,
                'tenant_id' => $metadados['tenant_id'],
                'data_backup_original' => $metadados['data_backup'],
                'tempo_restauracao_ms' => $tempoExecucao,
                'data_restauracao' => Carbon::now()->format('d/m/Y H:i:s'),
            ];
            
        } catch (\Exception $e) {
            throw new ExcecaoValidacao('Erro ao restaurar backup: ' . $e->getMessage());
        }
    }
    
    /**
     * Listar backups disponíveis
     */
    public function listarBackups(string $tenantId = null): array
    {
        $query = $this->database->table('backups_sistema')
            ->select([
                'id',
                'tenant_id',
                'nome_arquivo',
                'tipo',
                'tamanho_bytes',
                'tempo_execucao_ms',
                'status',
                'created_at'
            ])
            ->orderBy('created_at', 'desc');
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        $backups = $query->get()->toArray();
        
        return array_map(function($backup) {
            return [
                'id' => $backup['id'],
                'tenant_id' => $backup['tenant_id'],
                'nome_arquivo' => $backup['nome_arquivo'],
                'tipo' => $backup['tipo'],
                'tamanho_mb' => round($backup['tamanho_bytes'] / 1024 / 1024, 2),
                'tempo_execucao_segundos' => round($backup['tempo_execucao_ms'] / 1000, 2),
                'status' => $backup['status'],
                'data_criacao' => Carbon::parse($backup['created_at'])->format('d/m/Y H:i:s'),
                'existe_arquivo' => file_exists($this->diretorioBackups . '/' . $backup['nome_arquivo']),
            ];
        }, $backups);
    }
    
    /**
     * Excluir backup
     */
    public function excluirBackup(int $backupId): bool
    {
        $backup = $this->database->table('backups_sistema')
            ->where('id', $backupId)
            ->first();
        
        if (!$backup) {
            throw new ExcecaoValidacao('Backup não encontrado');
        }
        
        $caminhoArquivo = $this->diretorioBackups . '/' . $backup->nome_arquivo;
        
        // Remover arquivo físico
        if (file_exists($caminhoArquivo)) {
            unlink($caminhoArquivo);
        }
        
        // Remover registro do banco
        return $this->database->table('backups_sistema')
            ->where('id', $backupId)
            ->delete() > 0;
    }
    
    /**
     * Programar backup automático
     */
    public function programarBackupAutomatico(string $tenantId, array $configuracao): array
    {
        $configId = $this->database->table('backup_configuracoes')->insertGetId([
            'tenant_id' => $tenantId,
            'frequencia' => $configuracao['frequencia'], // diaria, semanal, mensal
            'hora_execucao' => $configuracao['hora_execucao'] ?? '02:00',
            'manter_backups' => $configuracao['manter_backups'] ?? 30, // dias
            'incluir_uploads' => $configuracao['incluir_uploads'] ?? true,
            'ativo' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        
        return [
            'configuracao_id' => $configId,
            'tenant_id' => $tenantId,
            'frequencia' => $configuracao['frequencia'],
            'proximo_backup' => $this->calcularProximoBackup($configuracao['frequencia']),
        ];
    }
    
    /**
     * Criar backup do banco de dados
     */
    private function criarBackupBanco(string $tenantId): string
    {
        $arquivoTemp = tempnam(sys_get_temp_dir(), 'backup_db_');
        $comandoMysqldump = $this->construirComandoMysqldump($tenantId);
        
        exec($comandoMysqldump . ' > ' . $arquivoTemp, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new ExcecaoValidacao('Erro ao criar backup do banco de dados');
        }
        
        return $arquivoTemp;
    }
    
    /**
     * Construir comando mysqldump
     */
    private function construirComandoMysqldump(string $tenantId): string
    {
        $config = $this->database->getConfig();
        
        $comando = sprintf(
            'mysqldump -h%s -P%s -u%s -p%s --single-transaction --routines --triggers %s',
            escapeshellarg($config['host']),
            escapeshellarg($config['port']),
            escapeshellarg($config['username']),
            escapeshellarg($config['password']),
            escapeshellarg($config['database'])
        );
        
        // Adicionar filtro por tenant nas tabelas relevantes
        foreach ($this->obterTabelasTenant() as $tabela) {
            $comando .= sprintf(' --where="tenant_id=\'%s\'"', $tenantId);
        }
        
        // Excluir tabelas desnecessárias
        foreach ($this->tabelasExcluidas as $tabela) {
            $comando .= ' --ignore-table=' . $config['database'] . '.' . $tabela;
        }
        
        return $comando;
    }
    
    /**
     * Gerar nome único para backup
     */
    private function gerarNomeBackup(string $tenantId, string $tipo): string
    {
        return sprintf(
            'backup_%s_%s_%s',
            $tenantId,
            $tipo,
            Carbon::now()->format('Y-m-d_H-i-s')
        );
    }
    
    /**
     * Calcular hash de verificação
     */
    private function calcularHashVerificacao(string $tenantId): string
    {
        // Hash baseado em registros críticos para verificar integridade
        $dados = [
            'tenant_id' => $tenantId,
            'timestamp' => Carbon::now()->timestamp,
            'total_vendas' => $this->database->table('vendas')->where('tenant_id', $tenantId)->count(),
            'total_clientes' => $this->database->table('clientes')->where('tenant_id', $tenantId)->count(),
        ];
        
        return hash('sha256', json_encode($dados));
    }
    
    /**
     * Garantir que diretório de backup existe
     */
    private function garantirDiretorioBackup(): void
    {
        if (!is_dir($this->diretorioBackups)) {
            mkdir($this->diretorioBackups, 0755, true);
        }
    }
    
    /**
     * Registrar backup no banco de dados
     */
    private function registrarBackup(array $dados): int
    {
        return $this->database->table('backups_sistema')->insertGetId($dados);
    }
    
    /**
     * Obter tabelas que possuem tenant_id
     */
    private function obterTabelasTenant(): array
    {
        return [
            'vendas',
            'clientes', 
            'produtos',
            'contas_receber',
            'contas_pagar',
            'transacoes_financeiras',
            'movimentacoes_estoque',
            'notificacoes',
        ];
    }
    
    // Métodos auxiliares (implementação simplificada para demonstração)
    private function adicionarArquivosConfiguracao(ZipArchive $zip, string $tenantId): void {}
    private function adicionarArquivosUploads(ZipArchive $zip, string $tenantId): void {}
    private function validarMetadadosBackup(array $metadados): void {}
    private function criarDiretorioTemporario(): string { return sys_get_temp_dir() . '/restore_' . uniqid(); }
    private function restaurarBancoDados(string $arquivo, string $tenantId): void {}
    private function restaurarArquivosConfiguracao(string $diretorio, string $tenantId): void {}
    private function restaurarArquivosUploads(string $diretorio, string $tenantId): void {}
    private function limparDiretorioTemporario(string $diretorio): void {}
    private function calcularProximoBackup(string $frequencia): string { return Carbon::now()->addDay()->format('d/m/Y H:i'); }
}