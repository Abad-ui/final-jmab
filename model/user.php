<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once '../config/JWTHandler.php';

class User {
    public $conn;
    private $table = 'users';

    public $id, $first_name, $last_name, $email, $password, $roles, $phone_number, $profile_picture, $gender, $birthday;

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    private function validateInput() {
        $errors = [];
        if (empty($this->first_name)) $errors[] = 'First name is required.';
        if (empty($this->last_name)) $errors[] = 'Last name is required.';
        if (empty($this->email)) $errors[] = 'Email is required.';
        if (empty($this->password)) $errors[] = 'Password is required.';
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
        return $errors;
    }

    private function isEmailExists($email, $excludeUserId = null) {
        $query = 'SELECT id FROM ' . $this->table . ' WHERE email = :email' . ($excludeUserId !== null ? ' AND id != :excludeUserId' : '');
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        if ($excludeUserId !== null) $stmt->bindParam(':excludeUserId', $excludeUserId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function register() {
        $errors = $this->validateInput();
        if (!empty($errors)) return ['success' => false, 'errors' => $errors];

        if ($this->isEmailExists($this->email)) return ['success' => false, 'errors' => ['Email is already registered.']];

        $hashedPassword = password_hash($this->password, PASSWORD_BCRYPT);
        $this->roles = $this->roles ?: 'customer';

        $query = 'INSERT INTO ' . $this->table . ' (first_name, last_name, email, password, roles, created_at) VALUES (:first_name, :last_name, :email, :password, :roles, NOW())';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':first_name', $this->first_name);
        $stmt->bindParam(':last_name', $this->last_name);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':roles', $this->roles);

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'User registered successfully.'] 
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function login($email, $password) {
        $result = $this->authenticate($email, $password);
        if ($result['success']) {
            $user = $result['user'];
            $token = JWTHandler::generateJWT($user);
            return ['success' => true, 'message' => 'Login successful.', 'token' => $token, 'user' => $user];
        }
        return ['success' => false, 'message' => 'Invalid email or password.', 'errors' => $result['errors']];
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
                ]
            ];
        }
        return ['success' => false, 'errors' => ['Invalid email or password.']];
    }

    public function getUsers() {
        $stmt = $this->conn->prepare('SELECT id, first_name, last_name, email, roles FROM ' . $this->table);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserById($id) {
        $query = 'SELECT u.id, u.first_name, u.last_name, u.email, u.roles, u.phone_number, u.profile_picture, u.gender, u.birthday, 
                         ua.id AS address_id, ua.home_address, ua.barangay, ua.city, ua.is_default 
                  FROM ' . $this->table . ' u 
                  LEFT JOIN user_addresses ua ON u.id = ua.user_id 
                  WHERE u.id = :id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$results) return false;

        $user = [
            'id' => $results[0]['id'],
            'first_name' => $results[0]['first_name'],
            'last_name' => $results[0]['last_name'],
            'email' => $results[0]['email'],
            'roles' => $results[0]['roles'],
            'phone_number' => $results[0]['phone_number'],
            'profile_picture' => $results[0]['profile_picture'],
            'gender' => $results[0]['gender'],
            'birthday' => $results[0]['birthday'],
            'addresses' => []
        ];

        foreach ($results as $row) {
            if ($row['address_id']) {
                $user['addresses'][] = [
                    'id' => $row['address_id'],
                    'home_address' => $row['home_address'],
                    'barangay' => $row['barangay'],
                    'city' => $row['city'],
                    'is_default' => (bool)$row['is_default']
                ];
            }
        }
        return $user;
    }

    public function update($id, $data) {
        $userExists = $this->getUserById($id);
        if (!$userExists) return ['success' => false, 'errors' => ['User not found.']];

        $errors = [];
        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
        if (isset($data['email']) && $this->isEmailExists($data['email'], $id)) $errors[] = 'Email is already registered.';
        if (isset($data['first_name']) && !preg_match('/^[a-zA-Z ]+$/', $data['first_name'])) $errors[] = 'First name must contain only letters.';
        if (isset($data['last_name']) && !preg_match('/^[a-zA-Z ]+$/', $data['last_name'])) $errors[] = 'Last name must contain only letters.';
        if (!empty($errors)) return ['success' => false, 'errors' => $errors];

        $addressData = isset($data['addresses']) && is_array($data['addresses']) ? $data['addresses'] : [];
        unset($data['addresses']);

        $updates = [];
        $params = [':id' => $id];
        $hasChanges = false;

        foreach ($data as $key => $value) {
            if ($key === 'id' || $key === 'roles' || $value === '') continue;
            if ($key === 'password') {
                if (!is_string($value) || empty($value)) {
                    $errors[] = 'Password must be a non-empty string.';
                } else {
                    $currentPassword = $this->getPasswordById($id);
                    if ($currentPassword && !password_verify($value, $currentPassword)) {
                        $updates[] = 'password = :password';
                        $params[':password'] = password_hash($value, PASSWORD_BCRYPT);
                        $hasChanges = true;
                    }
                }
            } elseif (!array_key_exists($key, $userExists) || $userExists[$key] !== $value) {
                $updates[] = "$key = :$key";
                $params[":$key"] = $value;
                $hasChanges = true;
            }
        }

        $response = ['success' => true, 'message' => 'User updated successfully.'];
        if (!empty($updates)) {
            $query = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) $stmt->bindValue($key, $value);
            try {
                if (!$stmt->execute()) return ['success' => false, 'errors' => ['Failed to update user data.']];
            } catch (PDOException $e) {
                error_log('Database error: ' . $e->getMessage());
                return ['success' => false, 'errors' => ['An error occurred. Please try again.']];
            }
        }

        $addressChanged = false;
        if (!empty($addressData)) {
            foreach ($addressData as $address) {
                if (isset($address['is_default']) && $address['is_default']) {
                    $this->conn->prepare('UPDATE user_addresses SET is_default = FALSE WHERE user_id = :user_id')
                               ->execute([':user_id' => $id]);
                    break;
                }
            }

            foreach ($addressData as $address) {
                $addressParams = [':user_id' => $id];
                if (isset($address['id']) && !empty($address['id'])) {
                    $stmt = $this->conn->prepare('SELECT id FROM user_addresses WHERE id = :id AND user_id = :user_id');
                    $stmt->execute([':id' => $address['id'], ':user_id' => $id]);
                    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                        $addressUpdates = [];
                        $addressParams[':id'] = $address['id'];
                        foreach ($address as $key => $value) {
                            if ($key !== 'id' && in_array($key, ['home_address', 'barangay', 'city', 'is_default'])) {
                                $addressUpdates[] = "$key = :$key";
                                $addressParams[":$key"] = $key === 'is_default' ? (bool)$value : $value;
                            }
                        }
                        if (!empty($addressUpdates)) {
                            $query = 'UPDATE user_addresses SET ' . implode(', ', $addressUpdates) . ' WHERE id = :id AND user_id = :user_id';
                            $stmt = $this->conn->prepare($query);
                            $stmt->execute($addressParams);
                            $addressChanged = true;
                        }
                    } else {
                        $response['warnings'][] = "Address ID {$address['id']} not found for this user.";
                        continue;
                    }
                } else {
                    $query = 'INSERT INTO user_addresses (user_id, home_address, barangay, city, is_default) 
                              VALUES (:user_id, :home_address, :barangay, :city, :is_default)';
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute(array_merge($addressParams, [
                        ':home_address' => $address['home_address'] ?? null,
                        ':barangay' => $address['barangay'] ?? null,
                        ':city' => $address['city'] ?? null,
                        ':is_default' => $address['is_default'] ?? false
                    ]));
                    $addressChanged = true;
                }
            }
        }

        return !$hasChanges && !$addressChanged && empty($response['warnings']) 
            ? ['success' => false, 'errors' => ['No changes detected.']] 
            : $response;
    }

    private function getPasswordById($id) {
        $stmt = $this->conn->prepare('SELECT password FROM ' . $this->table . ' WHERE id = :id');
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['password'] : null;
    }

    public function delete($id) {
        $user = $this->getUserById($id);
        if ($user && $user['roles'] === 'admin') return ['success' => false, 'errors' => ['Admin users cannot be deleted.']];

        $stmt = $this->conn->prepare('DELETE FROM ' . $this->table . ' WHERE id = :id');
        $stmt->bindParam(':id', $id);

        try {
            $stmt->execute();
            return $stmt->rowCount() > 0 
                ? ['success' => true, 'message' => 'User deleted successfully.'] 
                : ['success' => false, 'errors' => ['User not found.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function deleteAddresses($userId, array $addressIds) {
        if (empty($addressIds)) return ['success' => false, 'errors' => ['No address IDs provided.']];
        if (!array_reduce($addressIds, fn($carry, $id) => $carry && is_numeric($id), true)) return ['success' => false, 'errors' => ['All address IDs must be numeric.']];

        $addressIds = array_map('intval', $addressIds);
        $placeholders = implode(',', array_fill(0, count($addressIds), '?'));
        $stmt = $this->conn->prepare("SELECT id FROM user_addresses WHERE user_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$userId], $addressIds));
        $validAddressIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($validAddressIds)) return ['success' => false, 'errors' => ['No valid addresses found for this user.']];

        $addressesToDelete = array_intersect($addressIds, $validAddressIds);
        if (empty($addressesToDelete)) return ['success' => false, 'errors' => ['No addresses to delete after validation.']];

        $deleteStmt = $this->conn->prepare("DELETE FROM user_addresses WHERE user_id = ? AND id IN (" . implode(',', array_fill(0, count($addressesToDelete), '?')) . ")");
        try {
            $deleteStmt->execute(array_merge([$userId], $addressesToDelete));
            $deletedCount = $deleteStmt->rowCount();
            if ($deletedCount > 0) {
                $response = ['success' => true, 'message' => "Successfully deleted $deletedCount address" . ($deletedCount > 1 ? 'es' : '') . "."];
                if (count($addressesToDelete) < count($addressIds)) {
                    $response['warnings'] = ['Some addresses could not be deleted: ' . implode(', ', array_diff($addressIds, $addressesToDelete))];
                }

                $checkDefault = $this->conn->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = :user_id AND is_default = TRUE');
                $checkDefault->execute([':user_id' => $userId]);
                $remainingCheck = $this->conn->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = :user_id');
                $remainingCheck->execute([':user_id' => $userId]);
                if ($checkDefault->fetchColumn() == 0 && $remainingCheck->fetchColumn() > 0) {
                    $this->conn->prepare('UPDATE user_addresses SET is_default = TRUE WHERE user_id = :user_id LIMIT 1')
                               ->execute([':user_id' => $userId]);
                    $response['warnings'][] = 'Default address was deleted; a new default has been set.';
                }
                return $response;
            }
            return ['success' => false, 'errors' => ['No addresses were deleted.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Database error: ' . $e->getMessage()]];
        }
    }
}
?>