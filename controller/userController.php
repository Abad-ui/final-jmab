<?php
require_once '../model/user.php';

class UserController {
    private $userModel;

    public function __construct(User $userModel) {
        $this->userModel = $userModel;
    }

    private function authenticateAPI() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            throw new Exception('Authorization token is required.', 401);
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $result = $this->userModel->validateJWT($token);
        if (!$result['success']) {
            throw new Exception(implode(', ', $result['errors']), 401);
        }
        return $result['user'];
    }

    public function register(array $data) {
        $this->userModel->first_name = $data['first_name'] ?? '';
        $this->userModel->last_name = $data['last_name'] ?? '';
        $this->userModel->email = $data['email'] ?? '';
        $this->userModel->password = $data['password'] ?? '';
        $this->userModel->roles = $data['roles'] ?? 'customer';

        $result = $this->userModel->register();
        
        if ($result['success']) {
            // If addresses are provided, add them after registration
            if (isset($data['addresses']) && is_array($data['addresses'])) {
                $userId = $this->userModel->conn->lastInsertId();
                $updateResult = $this->userModel->update($userId, ['addresses' => $data['addresses']]);
                
                if (!$updateResult['success']) {
                    return [
                        'status' => 201,
                        'body' => [
                            'success' => true,
                            'message' => 'User registered successfully, but failed to add addresses',
                            'warnings' => $updateResult['warnings'] ?? $updateResult['errors']
                        ]
                    ];
                }
            }
            
            return [
                'status' => 201,
                'body' => ['success' => true, 'message' => $result['message']]
            ];
        }
        return [
            'status' => 400,
            'body' => ['success' => false, 'errors' => $result['errors']]
        ];
    }

    public function login(array $data) {
        $result = $this->userModel->login($data['email'] ?? '', $data['password'] ?? '');
        if ($result['success']) {
            return [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => $result['message'],
                    'token' => $result['token'],
                    'user' => $result['user']
                ]
            ];
        }
        return [
            'status' => 400,
            'body' => ['success' => false, 'errors' => $result['errors']]
        ];
    }

    public function getAll() {
        $this->authenticateAPI();
        $users = $this->userModel->getUsers();
        return [
            'status' => 200,
            'body' => ['success' => true, 'users' => $users]
        ];
    }

    public function getById($id) {
        $this->authenticateAPI();
        $userInfo = $this->userModel->getUserById($id);
        if ($userInfo) {
            return [
                'status' => 200,
                'body' => ['success' => true, 'user' => $userInfo]
            ];
        }
        return [
            'status' => 404,
            'body' => ['success' => false, 'errors' => ['User not found.']]
        ];
    }

    public function update($id, array $data) {
        $this->authenticateAPI();
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['User ID is required.']]
            ];
        }
        
        $result = $this->userModel->update($id, $data);
        if ($result['success']) {
            $response = [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => $result['message']
                ]
            ];
            
            // Include warnings if any address updates failed
            if (isset($result['warnings'])) {
                $response['body']['warnings'] = $result['warnings'];
            }
            
            return $response;
        }
        return [
            'status' => 400,
            'body' => ['success' => false, 'errors' => $result['errors']]
        ];
    }

    public function delete($id) {
        $this->authenticateAPI();
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['User ID is required.']]
            ];
        }
        
        $userToDelete = $this->userModel->getUserById($id);
        if (!$userToDelete) {
            return [
                'status' => 404,
                'body' => ['success' => false, 'errors' => ['User not found.']]
            ];
        }
        
        if ($userToDelete['roles'] === 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['Admin users cannot be deleted.']]
            ];
        }
        
        $result = $this->userModel->delete($id);
        if ($result['success']) {
            return [
                'status' => 200,
                'body' => ['success' => true, 'message' => $result['message']]
            ];
        }
        return [
            'status' => 400,
            'body' => ['success' => false, 'errors' => $result['errors']]
        ];
    }

    public function deleteAddresses($userId, array $addressIds) {
        $this->authenticateAPI();
        
        if (empty($userId)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['User ID is required.']]
            ];
        }
    
        $result = $this->userModel->deleteAddresses($userId, $addressIds);
        
        if ($result['success']) {
            $response = [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => $result['message']
                ]
            ];
            
            if (isset($result['warnings'])) {
                $response['body']['warnings'] = $result['warnings'];
            }
            
            return $response;
        }
        
        return [
            'status' => 400,
            'body' => ['success' => false, 'errors' => $result['errors']]
        ];
    }
}
?>