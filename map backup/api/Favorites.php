<?php
/**
 * Favorites.php - Classe para gerenciar favoritos dos usuários
 * 
 * Esta classe substitui o localStorage do JavaScript original,
 * mantendo EXATAMENTE a mesma funcionalidade mas com persistência real
 */

require_once 'Database.php';

class Favorites {
    private $db;
    
    /**
     * Construtor - inicializa conexão com banco
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Adiciona um item aos favoritos
     * Mantém a mesma lógica do JavaScript original
     * 
     * @param int $userId ID do usuário logado
     * @param string $type Tipo: 'music', 'album', 'artist'
     * @param string $name Nome da música/álbum/artista
     * @param string|null $artist Nome do artista (null para tipo 'artist')
     * @return array Resultado da operação
     */
    public function addFavorite($userId, $type, $name, $artist = null) {
        try {
            // 1. VALIDAR DADOS DE ENTRADA
            $validation = $this->validateFavoriteData($type, $name, $artist);
            if (!$validation['valid']) {
                return $validation;
            }
            
            // 2. VERIFICAR SE JÁ EXISTE (mesma lógica do JS original)
            $existingFavorite = $this->db->fetchOne(
                "SELECT id FROM favorites 
                 WHERE user_id = ? AND type = ? AND name = ? AND 
                 (artist = ? OR (artist IS NULL AND ? IS NULL))",
                [$userId, $type, $name, $artist, $artist]
            );
            
            if ($existingFavorite) {
                return [
                    'success' => false,
                    'message' => 'Este item já está nos seus favoritos!'
                ];
            }
            
            // 3. INSERIR NOVO FAVORITO
            $favoriteId = $this->db->insert(
                "INSERT INTO favorites (user_id, type, name, artist) 
                 VALUES (?, ?, ?, ?)",
                [$userId, $type, $name, $artist]
            );
            
            // 4. BUSCAR O FAVORITO INSERIDO PARA RETORNAR
            $newFavorite = $this->getFavoriteById($favoriteId);
            
            return [
                'success' => true,
                'message' => $this->getCategoryLabel($type) . ' adicionado aos favoritos!',
                'favorite' => $newFavorite
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Erro interno. Tente novamente.'
            ];
        }
    }
    
    /**
     * Remove um favorito
     * 
     * @param int $userId ID do usuário
     * @param int $favoriteId ID do favorito
     * @return array Resultado da operação
     */
    public function removeFavorite($userId, $favoriteId) {
        try {
            // Verifica se o favorito pertence ao usuário
            $favorite = $this->db->fetchOne(
                "SELECT id FROM favorites WHERE id = ? AND user_id = ?",
                [$favoriteId, $userId]
            );
            
            if (!$favorite) {
                return [
                    'success' => false,
                    'message' => 'Favorito não encontrado'
                ];
            }
            
            // Remove o favorito
            $this->db->query(
                "DELETE FROM favorites WHERE id = ? AND user_id = ?",
                [$favoriteId, $userId]
            );
            
            return [
                'success' => true,
                'message' => 'Favorito removido com sucesso!'
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Erro interno. Tente novamente.'
            ];
        }
    }
    
    /**
     * Busca todos os favoritos de um usuário
     * Retorna no mesmo formato que o JavaScript original usava
     * 
     * @param int $userId ID do usuário
     * @return array Lista de favoritos
     */
    public function getUserFavorites($userId) {
        try {
            $favorites = $this->db->fetchAll(
                "SELECT id, type, name, artist, added_at as dateAdded 
                 FROM favorites 
                 WHERE user_id = ? 
                 ORDER BY added_at DESC",
                [$userId]
            );
            
            // Formatar data no formato ISO (como era no JS original)
            foreach ($favorites as &$favorite) {
                $favorite['dateAdded'] = date('c', strtotime($favorite['dateAdded']));
            }
            
            return $favorites;
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Busca um favorito específico por ID
     * 
     * @param int $favoriteId ID do favorito
     * @return array|false Dados do favorito
     */
    private function getFavoriteById($favoriteId) {
        return $this->db->fetchOne(
            "SELECT id, type, name, artist, added_at as dateAdded 
             FROM favorites 
             WHERE id = ?",
            [$favoriteId]
        );
    }
    
    /**
     * Conta quantos favoritos um usuário tem por tipo
     * Útil para estatísticas
     * 
     * @param int $userId ID do usuário
     * @return array Contadores por tipo
     */
    public function getFavoritesStats($userId) {
        try {
            $stats = $this->db->fetchAll(
                "SELECT type, COUNT(*) as count 
                 FROM favorites 
                 WHERE user_id = ? 
                 GROUP BY type",
                [$userId]
            );
            
            // Converter para formato associativo
            $result = ['music' => 0, 'album' => 0, 'artist' => 0];
            foreach ($stats as $stat) {
                $result[$stat['type']] = (int)$stat['count'];
            }
            
            return $result;
            
        } catch (PDOException $e) {
            return ['music' => 0, 'album' => 0, 'artist' => 0];
        }
    }
    
    /**
     * Busca favoritos para gerar recomendações
     * Analisa padrões musicais do usuário
     * 
     * @param int $userId ID do usuário
     * @return array Dados para algoritmo de recomendação
     */
    public function getFavoritesForRecommendations($userId) {
        try {
            return $this->db->fetchAll(
                "SELECT type, name, artist 
                 FROM favorites 
                 WHERE user_id = ? 
                 ORDER BY added_at DESC 
                 LIMIT 20", // Últimos 20 para análise
                [$userId]
            );
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Valida dados do favorito antes de inserir
     * Mesmas validações que existiam no JavaScript
     * 
     * @param string $type Tipo do favorito
     * @param string $name Nome
     * @param string|null $artist Artista
     * @return array Resultado da validação
     */
    private function validateFavoriteData($type, $name, $artist) {
        $errors = [];
        
        // Validar tipo (deve ser um dos três permitidos)
        $allowedTypes = ['music', 'album', 'artist'];
        if (!in_array($type, $allowedTypes)) {
            $errors[] = "Tipo inválido";
        }
        
        // Validar nome
        if (empty(trim($name))) {
            $errors[] = "Nome é obrigatório";
        } elseif (strlen($name) > 255) {
            $errors[] = "Nome muito longo";
        }
        
        // Validar artista (obrigatório para music e album)
        if ($type !== 'artist') {
            if (empty(trim($artist))) {
                $errors[] = "Artista é obrigatório para " . $this->getCategoryLabel($type);
            } elseif (strlen($artist) > 255) {
                $errors[] = "Nome do artista muito longo";
            }
        }
        
        return [
            'valid' => empty($errors),
            'success' => empty($errors),
            'message' => empty($errors) ? 'Dados válidos' : implode(', ', $errors)
        ];
    }
    
    /**
     * Converte tipo em label (mesma função do JavaScript)
     * 
     * @param string $type Tipo do favorito
     * @return string Label em português
     */
    private function getCategoryLabel($type) {
        $labels = [
            'music' => 'Música',
            'album' => 'Álbum',
            'artist' => 'Artista'
        ];
        return $labels[$type] ?? $type;
    }
    
    /**
     * Migra favoritos do localStorage para o banco
     * Útil para usuários que já usavam a versão anterior
     * 
     * @param int $userId ID do usuário
     * @param array $localStorageFavorites Favoritos do localStorage
     * @return array Resultado da migração
     */
    public function migrateFromLocalStorage($userId, $localStorageFavorites) {
        try {
            $migrated = 0;
            $errors = 0;
            
            $this->db->beginTransaction();
            
            foreach ($localStorageFavorites as $favorite) {
                $result = $this->addFavorite(
                    $userId,
                    $favorite['type'],
                    $favorite['name'],
                    $favorite['artist'] ?? null
                );
                
                if ($result['success']) {
                    $migrated++;
                } else {
                    $errors++;
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Migração concluída: {$migrated} favoritos migrados",
                'migrated' => $migrated,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => 'Erro na migração. Tente novamente.'
            ];
        }
    }
}

/**
 * EXPLICAÇÃO TEÓRICA DETALHADA:
 * 
 * 1. COMPATIBILIDADE COM JAVASCRIPT ORIGINAL:
 *    - Mantém os mesmos tipos: 'music', 'album', 'artist'
 *    - Mesmas validações e regras de negócio
 *    - Retorna dados no mesmo formato para não quebrar o frontend
 *    - dateAdded formatado como ISO string (compatível com new Date())
 * 
 * 2. EVOLUÇÃO DA FUNCIONALIDADE:
 *    - Persistência real substituindo localStorage
 *    - Múltiplos usuários (cada um com seus favoritos)
 *    - Validação mais robusta no backend
 *    - Prevenção de SQL Injection com prepared statements
 * 
 * 3. NOVOS RECURSOS:
 *    - getFavoritesStats(): Estatísticas por tipo de favorito
 *    - getFavoritesForRecommendations(): Dados para IA futura
 *    - migrateFromLocalStorage(): Migração suave para usuários existentes
 * 
 * 4. ARQUITETURA:
 *    - Separação clara de responsabilidades
 *    - Métodos privados para validação e formatação
 *    - Tratamento consistente de erros
 *    - Transações para operações críticas (migração)
 * 
 * 5. SEGURANÇA:
 *    - Verificação de ownership (favorito pertence ao usuário)
 *    - Validação de entrada rigorosa
 *    - Prepared statements previnem SQL Injection
 *    - Limites de tamanho para prevenir ataques
 * 
 * 6. PERFORMANCE:
 *    - Índices no banco otimizam consultas por usuário
 *    - LIMIT nas consultas de recomendação
 *    - ORDER BY para resultados consistentes
 */
?>