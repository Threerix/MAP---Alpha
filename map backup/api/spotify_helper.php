<?php
declare(strict_types=1);

// api/spotify_helper.php
function get_spotify_access_token(PDO $pdo, int $userId): ?string {
    try {
        $stmt = $pdo->prepare("SELECT spotify_access_token, spotify_refresh_token, spotify_token_expires FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !$user['spotify_refresh_token']) {
            return null;
        }
        
        // Verifica se o token ainda é válido (com margem de 5 minutos)
        if ($user['spotify_access_token'] && 
            $user['spotify_token_expires'] && 
            time() < (strtotime($user['spotify_token_expires']) - 300)) {
            return $user['spotify_access_token'];
        }
        
        // Precisa renovar o token
        return refresh_spotify_token($pdo, $userId, $user['spotify_refresh_token']);
    } catch (Exception $e) {
        error_log("Erro ao obter token Spotify: " . $e->getMessage());
        return null;
    }
}

function refresh_spotify_token(PDO $pdo, int $userId, string $refreshToken): ?string {
    $clientId = defined('SPOTIFY_CLIENT_ID') ? SPOTIFY_CLIENT_ID : '';
    $clientSecret = defined('SPOTIFY_CLIENT_SECRET') ? SPOTIFY_CLIENT_SECRET : '';
    
    if (!$clientId || !$clientSecret) {
        return null;
    }
    
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://accounts.spotify.com/api/token',
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $accessToken = $data['access_token'];
                $expiresIn = $data['expires_in'] ?? 3600;
                $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
                
                // Atualiza o token no banco
                $stmt = $pdo->prepare("UPDATE users SET spotify_access_token = ?, spotify_token_expires = ? WHERE id = ?");
                $stmt->execute([$accessToken, $expiresAt, $userId]);
                
                return $accessToken;
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao renovar token Spotify: " . $e->getMessage());
    }
    
    return null;
}

function search_spotify(string $accessToken, string $type, string $name, ?string $artist = null): ?array {
    try {
        $query = urlencode($name);
        if ($artist) {
            $query .= '%20artist:' . urlencode($artist);
        }
        
        $url = "https://api.spotify.com/v1/search?q={$query}&type={$type}&limit=1";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$accessToken}"
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $items = null;
            
            switch ($type) {
                case 'track':
                    $items = $data['tracks']['items'] ?? [];
                    break;
                case 'album':
                    $items = $data['albums']['items'] ?? [];
                    break;
                case 'artist':
                    $items = $data['artists']['items'] ?? [];
                    break;
            }
            
            if ($items && count($items) > 0) {
                $item = $items[0];
                $imageUrl = null;
                
                if (isset($item['images']) && count($item['images']) > 0) {
                    $imageUrl = $item['images'][0]['url'];
                } elseif (isset($item['album']['images']) && count($item['album']['images']) > 0) {
                    $imageUrl = $item['album']['images'][0]['url'];
                }
                
                return [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'imageUrl' => $imageUrl
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Erro na busca Spotify: " . $e->getMessage());
    }
    
    return null;
}

function get_spotify_recommendations(string $accessToken, array $seeds): array {
    try {
        $params = [];
        
        if (!empty($seeds['seed_artists'])) {
            $params['seed_artists'] = implode(',', array_slice($seeds['seed_artists'], 0, 2));
        }
        
        if (!empty($seeds['seed_tracks'])) {
            $params['seed_tracks'] = implode(',', array_slice($seeds['seed_tracks'], 0, 3));
        }
        
        $params['limit'] = $seeds['limit'] ?? 20;
        
        $url = 'https://api.spotify.com/v1/recommendations?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$accessToken}"
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            return $data['tracks'] ?? [];
        }
    } catch (Exception $e) {
        error_log("Erro ao obter recomendações Spotify: " . $e->getMessage());
    }
    
    return [];
}