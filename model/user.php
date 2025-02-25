<?php
require_once '../config/database.php';
require '../vendor/autoload.php';
require_once '../config/config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class User {
    private $conn;
    private $table = 'users';

    public $id;
    public $first_name;
    public $last_name;
    public $email;
    public $password;
    public $roles = 'customer';
    public $phone_number;
    public $profile_picture;
    public $user_address;

    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->connect();
    }

    private static function getSecretKey() {
        
        return JWT_SECRET_KEY;
    }
    
    private function generateJWT($user) {
        $secretKey = self::getSecretKey(); 
        $issuedAt = time();
        $expirationTime = $issuedAt + 604800; 

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'sub' => $user['id'],
            'email' => $user['email'],
            'roles' => $user['roles']
        ];

        return JWT::encode($payload, $secretKey, 'HS256');
    }

    public static function validateJWT($token) {
        $secretKey = self::getSecretKey();
        try {
            $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
            
            // Check if the token is expired manually
            $current_time = time(); // current time in seconds
            if ($decoded->exp < $current_time) {
                // Token has expired
                return ['success' => false, 'errors' => ['Token has expired.']];
            }
            
            return ['success' => true, 'user' => (array) $decoded];
        } catch (Exception $e) {
            return ['success' => false, 'errors' => ['Invalid token.']];
        }
    }

    private function validateInput() {
        $errors = [];

        
        if (empty($this->first_name)) $errors[] = 'First name is required.';
        if (empty($this->last_name)) $errors[] = 'Last name is required.';
        if (empty($this->email)) $errors[] = 'Email is required.';
        if (empty($this->password)) $errors[] = 'Password is required.';

        
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }

        return $errors;
    }

    
    private function isEmailExists($email, $excludeUserId = null) {
    $query = 'SELECT id FROM ' . $this->table . ' WHERE email = :email';
    
    if ($excludeUserId !== null) {
        $query .= ' AND id != :excludeUserId';
    }

    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':email', $email);
    
    if ($excludeUserId !== null) {
        $stmt->bindParam(':excludeUserId', $excludeUserId, PDO::PARAM_INT);
    }

    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

    
    public function register() {
        $errors = $this->validateInput();

        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        
        if ($this->isEmailExists($this->email)) {
            return ['success' => false, 'errors' => ['Email is already registered.']];
        }

        $hashedPassword = password_hash($this->password, PASSWORD_BCRYPT);

        $query = 'INSERT INTO ' . $this->table . ' 
                  (first_name, last_name, email, password, roles, created_at) 
                  VALUES (:first_name, :last_name, :email, :password, :roles, NOW())';

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':first_name', $this->first_name);
        $stmt->bindParam(':last_name', $this->last_name);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':roles', $this->roles);

        try {
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'User registered successfully.'];
            }
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }

        return ['success' => false, 'errors' => ['Unknown error occurred.']];
    }

    
    public function login($email, $password) {
        $result = $this->authenticate($email, $password);
        
        if ($result['success']) {
            $user = $result['user'];
            $token = $this->generateJWT($user);
            return [
                'success' => true,
                'message' => 'Login successful.',
                'token' => $token,
                'user' => $user
            ];
        }

        return [
            'success' => false,
            'message' => 'Invalid email or password.', 
            'errors' => $result['errors']
        ];
    }

    public function authenticate($email, $password) {
        $query = 'SELECT * FROM ' . $this->table . ' WHERE email = :email';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            return [
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'email' => $user['email'],
                    'roles' => $user['roles']
                ],
            ];
        }

        return ['success' => false, 'errors' => ['Invalid email or password.']];
    }

    
    public function getUsers() {
        $query = 'SELECT id, first_name, last_name, email, roles FROM ' . $this->table;
        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    
    public function getUserById($id) {
        $query = 'SELECT id, first_name, last_name, email, roles, password, user_address, phone_number, profile_picture FROM ' . $this->table . ' WHERE id = :id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function update($id, $data) {
        $userExists = $this->getUserById($id);
        if (!$userExists) {
            return ['success' => false, 'errors' => ['User not found.']];
        }
    
        $errors = [];
    
        // Validate email format
        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }
    
        // Check if email is already in use
        if (isset($data['email']) && $this->isEmailExists($data['email'], $id)) {
            $errors[] = 'Email is already registered.';
        }
    
        // Validate first_name and last_name (Only letters allowed)
        if (isset($data['first_name']) && !preg_match('/^[a-zA-Z ]+$/', $data['first_name'])) {
            $errors[] = 'First name must contain only letters.';
        }
        if (isset($data['last_name']) && !preg_match('/^[a-zA-Z ]+$/', $data['last_name'])) {
            $errors[] = 'Last name must contain only letters.';
        }
        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
    
        $updates = [];
        $params = [':id' => $id];
    
        foreach ($data as $key => $value) {
            if ($key !== 'id' && $key !== 'roles') {
                // Ignore empty values to prevent updating with blank fields
                if ($value === '') {  // Allow updating NULL values but ignore empty strings
                    continue;
                }
        
                if ($key === 'password') {
                    // Check if the new password is actually different before hashing
                    if (!password_verify($value, $userExists['password'])) {
                        $hashedPassword = password_hash($value, PASSWORD_BCRYPT);
                        $updates[] = "{$key} = :{$key}";
                        $params[":{$key}"] = $hashedPassword;
                    }
                } else {
                    // Ensure NULL values in the database are updated properly
                    if (!array_key_exists($key, $userExists) || $userExists[$key] !== $value) {
                        $updates[] = "{$key} = :{$key}";
                        $params[":{$key}"] = $value;
                    }
                }
            }
        }
        
    
        if (empty($updates)) {
            return ['success' => false, 'errors' => ['No changes detected.']];
        }
    
        $query = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $stmt = $this->conn->prepare($query);
    
        foreach ($params as $param_key => $value) {
            $stmt->bindValue($param_key, $value);
        }
    
        try {
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'User updated successfully.'];
            }
        } catch (PDOException $e) {
            error_log('Database error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['An error occurred. Please try again.']];
        }
    
        return ['success' => false, 'errors' => ['Unknown error occurred.']];
    }

    
    public function delete($id) {
        $query = 'DELETE FROM ' . $this->table . ' WHERE id = :id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
    
        try {
            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    return ['success' => true, 'message' => 'User deleted successfully.'];
                } else {
                    return ['success' => false, 'errors' => ['User not found.']];
                }
            }
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    
        return ['success' => false, 'errors' => ['Unknown error occurred.']];
    }
}



