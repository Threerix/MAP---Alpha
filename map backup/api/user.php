<?php
// User.php

require_once 'Database.php';

class User {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Método para registrar um novo usuário
    public function register($username, $email, $password) {
        // Validação básica
        if (!$username || !$email || !$password) {
            return ['success' => false, 'message' => 'Todos os campos são obrigatórios.'];
        }

        // Hash da senha para segurança
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        try {
            // Verificar se o username ou email já existem
            $existingUser = $this->db->fetchOne("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
            if ($existingUser) {
                return ['success' => false, 'message' => 'Usuário ou email já existem.'];
            }

            // Inserir o novo usuário no banco
            $sql = "INSERT INTO users (username, email, password, created_at) VALUES (?, ?, ?, NOW())";
            $this->db->query($sql, [$username, $email, $hashedPassword]);
            
            return ['success' => true, 'message' => 'Usuário registrado com sucesso.'];
        } catch (PDOException $e) {
            error_log("Erro no registro: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao registrar usuário.'];
        }
    }

    // Método para fazer login do usuário
    public function login($usernameOrEmail, $password) {
        try {
            $user = $this->db->fetchOne("SELECT id, username, password FROM users WHERE username = ? OR email = ?", [$usernameOrEmail, $usernameOrEmail]);
            
            if ($user && password_verify($password, $user['password'])) {
                // Senha correta, gerar e salvar um token de sessão
                $token = bin2hex(random_bytes(16));
                $this->db->query("UPDATE users SET session_token = ? WHERE id = ?", [$token, $user['id']]);

                return ['success' => true, 'id' => $user['id'], 'username' => $user['username'], 'token' => $token];
            } else {
                return ['success' => false, 'message' => 'Nome de usuário ou senha incorretos.'];
            }
        } catch (PDOException $e) {
            error_log("Erro no login: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao fazer login.'];
        }
    }

    // Método para validar o token de sessão
    public function validateToken($token) {
        if (!$token) return false;
        return $this->db->fetchOne("SELECT id, username FROM users WHERE session_token = ?", [$token]);
    }
}