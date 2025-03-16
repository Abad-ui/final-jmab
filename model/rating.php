<?php
require_once '../config/database.php';

class Rating {
    public $conn;
    private $table = 'productratings';
    public $rating_id, $product_id, $user_id, $rating;

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    private function validateInput() {
        $errors = [];
        if (empty($this->product_id)) $errors[] = 'Product ID is required.';
        if (empty($this->user_id)) $errors[] = 'User ID is required.';
        if (empty($this->rating) || !is_numeric($this->rating) || $this->rating < 1 || $this->rating > 5) {
            $errors[] = 'Rating must be a number between 1 and 5.';
        }
        return $errors;
    }

    public function getAll($page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM ' . $this->table);
        $countStmt->execute();
        $totalRatings = $countStmt->fetchColumn();

        // Get paginated ratings
        $query = 'SELECT * FROM ' . $this->table . ' ORDER BY rating_id DESC LIMIT :perPage OFFSET :offset';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'ratings' => $ratings,
            'page' => $page,
            'perPage' => $perPage,
            'totalRatings' => $totalRatings,
            'totalPages' => ceil($totalRatings / $perPage)
        ];
    }

    public function getByProductId($product_id, $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;

        // Get total count for this product
        $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM ' . $this->table . ' WHERE product_id = :product_id');
        $countStmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $countStmt->execute();
        $totalRatings = $countStmt->fetchColumn();

        // Get paginated ratings for this product
        $query = 'SELECT * FROM ' . $this->table . ' WHERE product_id = :product_id ORDER BY rating_id DESC LIMIT :perPage OFFSET :offset';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'ratings' => $ratings,
            'page' => $page,
            'perPage' => $perPage,
            'totalRatings' => $totalRatings,
            'totalPages' => ceil($totalRatings / $perPage)
        ];
    }

    public function getById($rating_id) {
        $query = 'SELECT * FROM ' . $this->table . ' WHERE rating_id = :rating_id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':rating_id', $rating_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createRating() {
        $errors = $this->validateInput();
        if (!empty($errors)) return ['success' => false, 'errors' => $errors];

        $query = 'INSERT INTO ' . $this->table . ' (product_id, user_id, rating) 
                  VALUES (:product_id, :user_id, :rating)';
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':product_id', $this->product_id);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':rating', $this->rating);

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Rating created successfully.', 'rating_id' => $this->conn->lastInsertId()]
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function updateRating($rating_id, $data) {
        if (!$this->getById($rating_id)) {
            return ['success' => false, 'errors' => ['Rating not found.']];
        }

        $updates = [];
        $params = [':rating_id' => $rating_id];

        if (isset($data['rating']) && is_numeric($data['rating']) && $data['rating'] >= 1 && $data['rating'] <= 5) {
            $updates[] = 'rating = :rating';
            $params[':rating'] = $data['rating'];
        }

        if (empty($updates)) {
            return ['success' => false, 'errors' => ['No valid fields to update.']];
        }

        $query = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $updates) . ' WHERE rating_id = :rating_id';
        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Rating updated successfully.']
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function deleteRating($rating_id) {
        $stmt = $this->conn->prepare('DELETE FROM ' . $this->table . ' WHERE rating_id = :rating_id');
        $stmt->bindParam(':rating_id', $rating_id, PDO::PARAM_INT);

        try {
            return $stmt->execute()
                ? ['success' => true, 'message' => 'Rating deleted successfully.']
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function getAverageRating($product_id) {
        $query = 'SELECT AVG(rating) as average_rating, COUNT(*) as rating_count 
                  FROM ' . $this->table . ' 
                  WHERE product_id = :product_id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'average_rating' => round($result['average_rating'], 1) ?: 0,
            'rating_count' => (int)$result['rating_count']
        ];
    }
}
?>