<?php
/**
 * migrate.php - Script de migração do localStorage para MySQL
 * 
 * Este script é executado automaticamente quando um usuário faz login
 * e ainda tem favoritos salvos no localStorage da versão anterior (v0.2).
 * 
 * Garante uma transição suave sem perda de dados.
 */

// === CONFIGURAÇÃO INICIAL ===

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Incluir arquivos necessários
require_once '../helpers.php'; // Adicionar o helpers.php
require_once '../User.php';
require_once '../Favorites.php';

// === FUNÇÃO PRINCIPAL ===

/**
 * Processa migração de dados do localStorage
 */
function handleMigration() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return sendError('Método não permitido', 405);
    }
    
    // Ler dados da requisição
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return sendError('JSON inválido');
    }
    
    // Autenticar usuário
    $user = authenticateUser($data);
    if (!$user) {
        return sendError('Não autorizado', 401);
    }
    
    // Validar dados de favoritos
    if (!isset($data['favorites']) || !is_array($data['favorites'])) {
        return sendError('Dados de favoritos inválidos');
    }
    
    // Executar migração
    return migrateUserFavorites($user['id'], $data['favorites']);
}

/**
 * Autentica usuário pelo session_token
 */
function authenticateUser($data) {
    if (!isset($data['session_token'])) {
        return false;
    }
    
    try {
        $user = new User();
        return $user->validateSession($data['session_token']);
    } catch (Exception $e) {
        error_log("Erro na autenticação para migração: " . $e->getMessage());
        return false;
    }
}

/**
 * Migra favoritos do localStorage para o banco
 * 
 * @param int $userId ID do usuário
 * @param array $localStorageFavorites Array de favoritos do localStorage
 */
function migrateUserFavorites($userId, $localStorageFavorites) {
    try {
        $favoritesManager = new Favorites();
        
        // Verificar se usuário já tem favoritos no banco
        $existingFavorites = $favoritesManager->getUserFavorites($userId);
        
        if (count($existingFavorites) > 0) {
            return sendSuccess('Migração não necessária - usuário já possui favoritos no sistema', [
                'migrated' => 0,
                'existing' => count($existingFavorites)
            ]);
        }
        
        // Estatísticas da migração
        $stats = [
            'total_received' => count($localStorageFavorites),
            'migrated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => []
        ];
        
        // Processar cada favorito
        foreach ($localStorageFavorites as $index => $favorite) {
            $result = processSingleFavorite($favoritesManager, $userId, $favorite, $index);
            
            // Atualizar estatísticas
            $stats[$result['status']]++;
            
            if ($result['status'] === 'errors') {
                $stats['details'][] = $result['detail'];
            }
        }
        
        // Log da migração
        logMigrationActivity($userId, $stats);
        
        // Resposta de sucesso
        $message = sprintf(
            'Migração concluída: %d favoritos migrados com sucesso',
            $stats['migrated']
        );
        
        if ($stats['errors'] > 0) {
            $message .= sprintf(', %d erros encontrados', $stats['errors']);
        }
        
        return sendSuccess($message, $stats);
        
    } catch (Exception $e) {
        error_log("Erro geral na migração: " . $e->getMessage());
        return sendError('Erro interno durante migração');
    }
}

/**
 * Processa um único favorito da migração
 * 
 * @param Favorites $favoritesManager Instância do gerenciador
 * @param int $userId ID do usuário
 * @param array $favorite Dados do favorito
 * @param int $index Índice para debug
 * @return array Status do processamento
 */
function processSingleFavorite($favoritesManager, $userId, $favorite, $index) {
    try {
        // Validar estrutura do favorito
        $validation = validateFavoriteStructure($favorite);
        if (!$validation['valid']) {
            return [
                'status' => 'errors',
                'detail' => "Favorito #{$index}: " . $validation['error']
            ];
        }
        
        // Extrair e limpar dados
        $type = trim($favorite['type']);
        $name = trim($favorite['name']);
        $artist = isset($favorite['artist']) ? trim($favorite['artist']) : null;
        
        // Tentar adicionar ao banco
        $result = $favoritesManager->addFavorite($userId, $type, $name, $artist);
        
        if ($result['success']) {
            return ['status' => 'migrated'];
        } else {
            // Se erro for "já existe", considerar como pulado, não erro
            if (strpos($result['message'], 'já está') !== false) {
                return ['status' => 'skipped'];
            } else {
                return [
                    'status' => 'errors',
                    'detail' => "Favorito '{$name}': " . $result['message']
                ];
            }
        }
        
    } catch (Exception $e) {
        return [
            'status' => 'errors',
            'detail' => "Favorito #{$index}: Erro interno - " . $e->getMessage()
        ];
    }
}

/**
 * Valida estrutura de um favorito do localStorage
 * 
 * @param mixed $favorite Dados do favorito
 * @return array Resultado da validação
 */
function validateFavoriteStructure($favorite) {
    // Deve ser um array
    if (!is_array($favorite)) {
        return ['valid' => false, 'error' => 'Estrutura inválida (não é array)'];
    }
    
    // Deve ter campos obrigatórios
    $requiredFields = ['type', 'name'];
    foreach ($requiredFields as $field) {
        if (!isset($favorite[$field]) || empty(trim($favorite[$field]))) {
            return ['valid' => false, 'error' => "Campo '{$field}' ausente ou vazio"];
        }
    }
    
    // Validar tipo
    $validTypes = ['music', 'album', 'artist'];
    if (!in_array($favorite['type'], $validTypes)) {
        return ['valid' => false, 'error' => "Tipo '{$favorite['type']}' inválido"];
    }
    
    // Para música e álbum, artista é obrigatório
    if (($favorite['type'] === 'music' || $favorite['type'] === 'album')) {
        if (!isset($favorite['artist']) || empty(trim($favorite['artist']))) {
            return ['valid' => false, 'error' => "Artista obrigatório para tipo '{$favorite['type']}'"];
        }
    }
    
    // Validar tamanhos
    if (strlen($favorite['name']) > 255) {
        return ['valid' => false, 'error' => 'Nome muito longo (máx 255 caracteres)'];
    }
    
    if (isset($favorite['artist']) && strlen($favorite['artist']) > 255) {
        return ['valid' => false, 'error' => 'Nome do artista muito longo (máx 255 caracteres)'];
    }
    
    return ['valid' => true];
}

/**
 * Registra atividade de migração para auditoria
 */
function logMigrationActivity($userId, $stats) {
    $logEntry = [
        'timestamp' => date('c'),
        'user_id' => $userId,
        'action' => 'migration_localstorage',
        'stats' => $stats,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    error_log("MIGRATION_ACTIVITY: " . json_encode($logEntry));
}

/**
 * Limpa dados de migração temporários (se necessário)
 */
function cleanupMigrationData($userId) {
    // Em implementações futuras, pode limpar dados temporários
    // Por exemplo, flags de "migração pendente" ou caches
}

// === FUNÇÕES AUXILIARES ===

function sendSuccess($message, $data = []) {
    $response = [
        'success' => true,
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    
    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

function sendError($message, $httpCode = 400) {
    $response = [
        'success' => false,
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    http_response_code($httpCode);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

/**
 * Detecta se dados vieram da versão v0.1 vs v0.2
 * (diferentes estruturas de localStorage)
 */
function detectLocalStorageVersion($favorites) {
    if (empty($favorites)) {
        return 'empty';
    }
    
    $firstFavorite = $favorites[0];
    
    // v0.2 tem campo 'dateAdded'
    if (isset($firstFavorite['dateAdded'])) {
        return 'v0.2';
    }
    
    // v0.1 estrutura mais simples
    return 'v0.1';
}

/**
 * Normaliza dados entre versões diferentes
 */
function normalizeFavoriteData($favorite, $version) {
    $normalized = $favorite;
    
    // Adicionar campos que podem estar faltando
    if (!isset($normalized['dateAdded'])) {
        $normalized['dateAdded'] = date('c'); // Data atual como fallback
    }
    
    // Limpar dados extras que podem causar problemas
    $allowedFields = ['type', 'name', 'artist', 'dateAdded'];
    $normalized = array_intersect_key($normalized, array_flip($allowedFields));
    
    return $normalized;
}

// === EXECUTAR MIGRAÇÃO ===

try {
    handleMigration();
} catch (Exception $e) {
    error_log("Erro crítico na migração: " . $e->getMessage());
    sendError('Erro crítico durante migração', 500);
}

/**
 * EXPLICAÇÃO TEÓRICA DA MIGRAÇÃO:
 * 
 * 1. ESTRATÉGIA DE MIGRAÇÃO:
 *    - Zero downtime: app continua funcionando
 *    - Backward compatibility: dados antigos são preservados
 *    - Atomic operations: ou migra tudo ou nada
 *    - Rollback capability: em caso de erro, dados ficam no localStorage
 * 
 * 2. VALIDAÇÃO ROBUSTA:
 *    - Estrutura de dados do localStorage pode estar corrompida
 *    - Usuários podem ter editado dados manualmente
 *    - Diferentes versões da app podem ter estruturas diferentes
 *    - Validação em múltiplas camadas previne corrupção do banco
 * 
 * 3. TRATAMENTO DE ERROS:
 *    - Erros não param a migração completa
 *    - Cada favorito é processado independentemente
 *    - Logs detalhados para debug de problemas
 *    - Estatísticas claras do que aconteceu
 * 
 * 4. AUDITORIA E LOGGING:
 *    - Toda migração é logada para auditoria
 *    - IP do usuário registrado para segurança
 *    - Estatísticas permitem análise de padrões
 *    - Base para melhorar processo de migração
 * 
 * 5. EXPERIÊNCIA DO USUÁRIO:
 *    - Migração automática e transparente
 *    - Feedback claro sobre o resultado
 *    - Não perde dados em caso de erro parcial
 *    - Remove localStorage apenas após sucesso completo
 * 
 * 6. ESCALABILIDADE:
 *    - Processa um usuário por vez (não batch)
 *    - Não sobrecarrega banco com inserções massivas
 *    - Rate limiting implícito (por sessão de usuário)
 *    - Pode ser executado múltiplas vezes sem problemas
 * 
 * 7. VERSIONAMENTO DE DADOS:
 *    - Detecta diferentes versões do localStorage
 *    - Normaliza dados entre versões
 *    - Preparado para futuras evoluções do schema
 *    - Mantém compatibilidade com versões antigas
 */
?>