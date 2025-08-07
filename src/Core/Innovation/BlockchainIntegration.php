<?php

declare(strict_types=1);

namespace ERP\Core\Innovation;

use ERP\Core\Security\Encryption\SupremoEncryptionEngine;
use ERP\Core\Performance\UltimatePerformanceEngine;

/**
 * Blockchain Integration Engine
 * 
 * Sistema avançado de integração blockchain para transparência
 * e imutabilidade de transações críticas do ERP
 */
class BlockchainIntegration
{
    private SupremoEncryptionEngine $encryption;
    private UltimatePerformanceEngine $performance;
    private array $blockchain = [];
    private string $lastBlockHash = '';
    
    public function __construct(
        SupremoEncryptionEngine $encryption,
        UltimatePerformanceEngine $performance
    ) {
        $this->encryption = $encryption;
        $this->performance = $performance;
        $this->initializeGenesisBlock();
    }
    
    /**
     * Adiciona transação crítica ao blockchain
     */
    public function addCriticalTransaction(array $transaction): string
    {
        $startTime = microtime(true);
        
        $block = [
            'index' => count($this->blockchain),
            'timestamp' => time(),
            'transaction' => $this->encryption->encryptSupremo(json_encode($transaction)),
            'previous_hash' => $this->lastBlockHash,
            'nonce' => 0,
            'merkle_root' => $this->calculateMerkleRoot([$transaction])
        ];
        
        $block['hash'] = $this->mineBlock($block);
        $this->blockchain[] = $block;
        $this->lastBlockHash = $block['hash'];
        
        $this->performance->recordMetric('blockchain_transaction_time', microtime(true) - $startTime);
        
        return $block['hash'];
    }
    
    /**
     * Verifica integridade do blockchain
     */
    public function verifyBlockchainIntegrity(): bool
    {
        for ($i = 1; $i < count($this->blockchain); $i++) {
            $currentBlock = $this->blockchain[$i];
            $previousBlock = $this->blockchain[$i - 1];
            
            if ($currentBlock['previous_hash'] !== $previousBlock['hash']) {
                return false;
            }
            
            if (! $this->isValidHash($currentBlock)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Cria smart contract para automação
     */
    public function createSmartContract(string $contractCode, array $conditions): string
    {
        $contract = [
            'id' => uniqid('contract_', true),
            'code' => $contractCode,
            'conditions' => $conditions,
            'created_at' => time(),
            'status' => 'active',
            'executions' => 0
        ];
        
        $contractHash = $this->addCriticalTransaction([
            'type' => 'smart_contract_creation',
            'contract' => $contract
        ]);
        
        return $contractHash;
    }
    
    /**
     * Executa smart contract
     */
    public function executeSmartContract(string $contractId, array $params): array
    {
        $contract = $this->findContract($contractId);
        
        if (! $contract || ! $this->validateContractConditions($contract, $params)) {
            return ['success' => false, 'error' => 'Contract validation failed'];
        }
        
        $executionResult = $this->runContractCode($contract['code'], $params);
        
        $this->addCriticalTransaction([
            'type' => 'smart_contract_execution',
            'contract_id' => $contractId,
            'params' => $params,
            'result' => $executionResult
        ]);
        
        return ['success' => true, 'result' => $executionResult];
    }
    
    /**
     * Consulta transações por critério
     */
    public function queryTransactions(array $criteria): array
    {
        $results = [];
        
        foreach ($this->blockchain as $block) {
            $decryptedTransaction = json_decode(
                $this->encryption->decryptSupremo($block['transaction']), 
                true
            );
            
            if ($this->matchesCriteria($decryptedTransaction, $criteria)) {
                $results[] = [
                    'block_hash' => $block['hash'],
                    'timestamp' => $block['timestamp'],
                    'transaction' => $decryptedTransaction
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Exporta blockchain para auditoria
     */
    public function exportForAudit(): array
    {
        $export = [];
        
        foreach ($this->blockchain as $block) {
            $export[] = [
                'index' => $block['index'],
                'timestamp' => date('Y-m-d H:i:s', $block['timestamp']),
                'hash' => $block['hash'],
                'previous_hash' => $block['previous_hash'],
                'transaction_count' => 1,
                'merkle_root' => $block['merkle_root']
            ];
        }
        
        return $export;
    }
    
    private function initializeGenesisBlock(): void
    {
        $genesisBlock = [
            'index' => 0,
            'timestamp' => time(),
            'transaction' => $this->encryption->encryptSupremo(json_encode([
                'type' => 'genesis',
                'message' => 'ERP Sistema Blockchain Genesis Block'
            ])),
            'previous_hash' => '0',
            'nonce' => 0,
            'merkle_root' => '0'
        ];
        
        $genesisBlock['hash'] = $this->calculateHash($genesisBlock);
        $this->blockchain[] = $genesisBlock;
        $this->lastBlockHash = $genesisBlock['hash'];
    }
    
    private function mineBlock(array $block): string
    {
        $difficulty = 4; // Proof of Work difficulty
        $target = str_repeat('0', $difficulty);
        
        while (substr($this->calculateHash($block), 0, $difficulty) !== $target) {
            $block['nonce']++;
        }
        
        return $this->calculateHash($block);
    }
    
    private function calculateHash(array $block): string
    {
        $data = $block['index'] . $block['timestamp'] . $block['transaction'] . 
                $block['previous_hash'] . $block['nonce'] . $block['merkle_root'];
        
        return hash('sha256', $data);
    }
    
    private function calculateMerkleRoot(array $transactions): string
    {
        if (empty($transactions)) {
            return '0';
        }
        
        $hashes = array_map(fn($tx) => hash('sha256', json_encode($tx)), $transactions);
        
        while (count($hashes) > 1) {
            $newHashes = [];
            for ($i = 0; $i < count($hashes); $i += 2) {
                $left = $hashes[$i];
                $right = $hashes[$i + 1] ?? $left;
                $newHashes[] = hash('sha256', $left . $right);
            }
            $hashes = $newHashes;
        }
        
        return $hashes[0];
    }
    
    private function isValidHash(array $block): bool
    {
        return $this->calculateHash($block) === $block['hash'];
    }
    
    private function findContract(string $contractId): ?array
    {
        foreach ($this->blockchain as $block) {
            $transaction = json_decode(
                $this->encryption->decryptSupremo($block['transaction']), 
                true
            );
            
            if ($transaction['type'] === 'smart_contract_creation' && 
                $transaction['contract']['id'] === $contractId) {
                return $transaction['contract'];
            }
        }
        
        return null;
    }
    
    private function validateContractConditions(array $contract, array $params): bool
    {
        foreach ($contract['conditions'] as $condition) {
            if (! $this->evaluateCondition($condition, $params)) {
                return false;
            }
        }
        
        return true;
    }
    
    private function evaluateCondition(array $condition, array $params): bool
    {
        $field = $condition['field'];
        $operator = $condition['operator'];
        $value = $condition['value'];
        
        if (! isset($params[$field])) {
            return false;
        }
        
        $paramValue = $params[$field];
        
        return match ($operator) {
            '==' => $paramValue == $value,
            '!=' => $paramValue != $value,
            '>' => $paramValue > $value,
            '<' => $paramValue < $value,
            '>=' => $paramValue >= $value,
            '<=' => $paramValue <= $value,
            'in' => in_array($paramValue, $value),
            'not_in' => !in_array($paramValue, $value),
            default => false
        };
    }
    
    private function runContractCode(string $code, array $params): mixed
    {
        // Sandbox seguro para execução de contratos
        $sandbox = [
            'params' => $params,
            'result' => null
        ];
        
        // Simulação de execução (em produção usaria sandbox real)
        eval("\$sandbox['result'] = $code;");
        
        return $sandbox['result'];
    }
    
    private function matchesCriteria(array $transaction, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            if (! isset($transaction[$key]) || $transaction[$key] !== $value) {
                return false;
            }
        }
        
        return true;
    }
}
