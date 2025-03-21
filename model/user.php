<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once '../config/JWTHandler.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class User {
    public $conn;
    private $table = 'users';
    private $pendingTable = 'pending_users';
    private $resetTable = 'password_resets';

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
        $query = 'SELECT id FROM ' . $this->table . ' WHERE email = :email' . ($excludeUserId !== null ? ' AND id != :excludeUserId' : '') .
                 ' UNION SELECT id FROM ' . $this->pendingTable . ' WHERE email = :email';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        if ($excludeUserId !== null) $stmt->bindParam(':excludeUserId', $excludeUserId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function sendEmail($toEmail, $toName, $subject, $message, $altMessage = null) {
        $mail = new PHPMailer(true);
    
        try {
            $mail->isSMTP();
            $mail->SMTPAuth = true;
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
    
            $mail->Username = "profakerblah@gmail.com";
            $mail->Password = "vypjkatteatpnjbd";
    
            $mail->setFrom("profakerblah@gmail.com", "JMAB");
            $mail->addReplyTo("profakerblah@gmail.com", "JMAB Support");
            $mail->addAddress($toEmail, $toName);
    
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = $altMessage ?: strip_tags($message); 
    
            $mail->send();
            return ['success' => true, 'message' => 'Email sent successfully.'];
        } catch (Exception $e) {
            error_log('PHPMailer Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Failed to send email: ' . $mail->ErrorInfo]];
        }
    }

    public function register() {
        $errors = $this->validateInput();
        if (!empty($errors)) return ['success' => false, 'errors' => $errors];
    
        if ($this->isEmailExists($this->email)) return ['success' => false, 'errors' => ['Email is already registered or pending verification.']];
    
        $hashedPassword = password_hash($this->password, PASSWORD_BCRYPT);
        $this->roles = $this->roles ?: 'customer';
        $verificationCode = sprintf("%06d", rand(0, 999999)); 
    
        $query = 'INSERT INTO ' . $this->pendingTable . ' (first_name, last_name, email, password, roles, verification_code) 
                  VALUES (:first_name, :last_name, :email, :password, :roles, :verification_code)';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':first_name', $this->first_name);
        $stmt->bindParam(':last_name', $this->last_name);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':roles', $this->roles);
        $stmt->bindParam(':verification_code', $verificationCode);
    
        try {
            if ($stmt->execute()) {
                $fullName = $this->first_name . ' ' . $this->last_name;
                $emailSubject = "Welcome to JMAB - Verify Your Email";
                $emailBody = "<p>Hello $fullName,</p>
                              <p>Welcome to JMAB! We’re excited to have you join us. To get started, please use the 6-digit code below to verify your email address:</p>
                              <p style='font-size: 24px; font-weight: bold; color: #2a2a2a;'>$verificationCode</p>
                              <p>Enter this code on our verification page within 15 minutes to activate your account. If you didn’t sign up, you can safely ignore this email.</p>
                              <p>Looking forward to serving you!<br>The JMAB Team</p>
                              <p style='font-size: 12px; color: #666;'>P.S. To ensure our emails reach your inbox, please add profakerblah@gmail.com to your contacts.</p>";
                $emailAltBody = "Hello $fullName,\n\nWelcome to JMAB! We’re excited to have you join us. To get started, use this 6-digit code to verify your email address:\n\n$verificationCode\n\nEnter it on our verification page within 15 minutes to activate your account. If you didn’t sign up, ignore this email.\n\nLooking forward to serving you!\nThe JMAB Team\n\nP.S. Add profakerblah@gmail.com to your contacts to ensure delivery.";
    
                $emailResult = $this->sendEmail($this->email, $fullName, $emailSubject, $emailBody, $emailAltBody);
    
                $response = ['success' => true, 'message' => 'Registration successful! Please check your email to verify your account.'];
                if (!$emailResult['success']) {
                    $response['warnings'] = [$emailResult['errors'][0]];
                }
                return $response;
            }
            return ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function verifyEmail($email, $code) {
        $query = 'SELECT * FROM ' . $this->pendingTable . ' WHERE email = :email AND verification_code = :code AND expires_at > NOW()';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':code', $code);
        $stmt->execute();
        $pendingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pendingUser) {
            return ['success' => false, 'errors' => ['Invalid email or code, or code has expired.']];
        }

        $insertQuery = 'INSERT INTO ' . $this->table . ' (first_name, last_name, email, password, roles, created_at) 
                        VALUES (:first_name, :last_name, :email, :password, :roles, NOW())';
        $insertStmt = $this->conn->prepare($insertQuery);
        $insertStmt->bindParam(':first_name', $pendingUser['first_name']);
        $insertStmt->bindParam(':last_name', $pendingUser['last_name']);
        $insertStmt->bindParam(':email', $pendingUser['email']);
        $insertStmt->bindParam(':password', $pendingUser['password']);
        $insertStmt->bindParam(':roles', $pendingUser['roles']);

        try {
            $this->conn->beginTransaction();

            if ($insertStmt->execute()) {
                $deleteQuery = 'DELETE FROM ' . $this->pendingTable . ' WHERE email = :email';
                $deleteStmt = $this->conn->prepare($deleteQuery);
                $deleteStmt->bindParam(':email', $email);
                $deleteStmt->execute();

                $this->conn->commit();
                return ['success' => true, 'message' => 'Email verified successfully. You can now log in.'];
            } else {
                $this->conn->rollBack();
                return ['success' => false, 'errors' => ['Failed to complete registration.']];
            }
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong during verification.']];
        }
    }

    public function resendVerificationCode($email) {
        $query = 'SELECT first_name, last_name FROM ' . $this->pendingTable . ' WHERE email = :email AND expires_at > NOW()';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $pendingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pendingUser) {
            return ['success' => false, 'errors' => ['No pending registration found for this email, or it has expired.']];
        }

        $newCode = sprintf("%06d", rand(0, 999999));
        $updateQuery = 'UPDATE ' . $this->pendingTable . ' SET verification_code = :code, expires_at = NOW() + INTERVAL 1 HOUR WHERE email = :email';
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->bindParam(':code', $newCode);
        $updateStmt->bindParam(':email', $email);

        try {
            if ($updateStmt->execute()) {
                $fullName = $pendingUser['first_name'] . ' ' . $pendingUser['last_name'];
                $emailSubject = "New Verification Code";
                $emailBody = "<h2>Hello, $fullName!</h2>
                              <p>We’ve generated a new 6-digit code for you to verify your email address:</p>
                              <h3>$newCode</h3>
                              <p>Enter this code in the verification form to complete your registration. This code expires in 15 Minutes.</p>
                              <p>If you didn’t request this, please ignore this email.</p>
                              <p>Best regards,<br>JMAB</p>";

                $emailResult = $this->sendEmail($email, $fullName, $emailSubject, $emailBody);

                $response = ['success' => true, 'message' => 'A new verification code has been sent to your email.'];
                if (!$emailResult['success']) {
                    $response['warnings'] = [$emailResult['errors'][0]];
                }
                return $response;
            }
            return ['success' => false, 'errors' => ['Failed to generate new code.']];
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

    public function getAdmins() {
        $query = 'SELECT id, first_name, last_name, roles FROM ' . $this->table . ' WHERE roles = :role';
        $stmt = $this->conn->prepare($query);

        $role = 'admin';
        $stmt->bindParam(':role', $role, PDO::PARAM_STR);
        $stmt->execute();

        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $admins;
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

    public function forgotPassword($email) {
        $query = 'SELECT id, first_name, last_name FROM ' . $this->table . ' WHERE email = :email';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'errors' => ['Email not found.']];
        }

        
        $resetCode = sprintf("%06d", rand(0, 999999));
        
        $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $now->add(new DateInterval('PT15M')); 
        $expiresAt = $now->format('Y-m-d H:i:s');

        $insertQuery = 'INSERT INTO ' . $this->resetTable . ' (email, reset_code, expires_at) 
                       VALUES (:email, :reset_code, :expires_at)';
        $insertStmt = $this->conn->prepare($insertQuery);
        $insertStmt->bindParam(':email', $email);
        $insertStmt->bindParam(':reset_code', $resetCode);
        $insertStmt->bindParam(':expires_at', $expiresAt);

        try {
            if ($insertStmt->execute()) {
                $fullName = $user['first_name'] . ' ' . $user['last_name'];
                $emailSubject = "JMAB - Password Reset Request";
                $emailBody = "<p>Hello $fullName,</p>
                              <p>We received a request to reset your password. Please use the 6-digit code below to proceed:</p>
                              <p style='font-size: 24px; font-weight: bold; color: #2a2a2a;'>$resetCode</p>
                              <p>Enter this code on our password reset page within 15 minutes. If you didn’t request this, you can safely ignore this email.</p>
                              <p>Best regards,<br>The JMAB Team</p>";
                $emailAltBody = "Hello $fullName,\n\nWe received a request to reset your password. Use this 6-digit code to proceed:\n\n$resetCode\n\nEnter it on our password reset page within 15 minutes. If you didn’t request this, ignore this email.\n\nBest regards,\nThe JMAB Team";

                $emailResult = $this->sendEmail($email, $fullName, $emailSubject, $emailBody, $emailAltBody);

                $response = ['success' => true, 'message' => 'A password reset code has been sent to your email.'];
                if (!$emailResult['success']) {
                    $response['warnings'] = [$emailResult['errors'][0]];
                }
                return $response;
            }
            return ['success' => false, 'errors' => ['Failed to initiate password reset.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function resetPassword($email, $resetCode, $newPassword) {
        if (empty($newPassword)) {
            return ['success' => false, 'errors' => ['New password is required.']];
        }

        $query = 'SELECT email FROM ' . $this->resetTable . ' WHERE email = :email AND reset_code = :reset_code AND expires_at > NOW()';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':reset_code', $resetCode);
        $stmt->execute();
        $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resetRecord) {
            return ['success' => false, 'errors' => ['Invalid or expired reset code.']];
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateQuery = 'UPDATE ' . $this->table . ' SET password = :password WHERE email = :email';
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->bindParam(':password', $hashedPassword);
        $updateStmt->bindParam(':email', $email);

        try {
            $this->conn->beginTransaction();

            if ($updateStmt->execute()) {
                $deleteQuery = 'DELETE FROM ' . $this->resetTable . ' WHERE email = :email';
                $deleteStmt = $this->conn->prepare($deleteQuery);
                $deleteStmt->bindParam(':email', $email);
                $deleteStmt->execute();

                $this->conn->commit();
                return ['success' => true, 'message' => 'Password reset successfully. You can now log in with your new password.'];
            } else {
                $this->conn->rollBack();
                return ['success' => false, 'errors' => ['Failed to reset password.']];
            }
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong during password reset.']];
        }
    }
}
?>