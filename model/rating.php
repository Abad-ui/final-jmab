<?php
require_once '../config/database.php';

class Rating {
    public $conn;
    private $table = 'productratings';
    public $rating_id, $variant_id, $user_id, $rating;

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    private function validateInput() {
        $errors = [];
        if (empty($this->variant_id)) $errors[] = 'Variant ID is required.';
        if (empty($this->user_id)) $errors[] = 'User ID is required.';
        if (empty($this->rating) || !is_numeric($this->rating) || $this->rating < 1 || $this->rating > 5) {
            $errors[] = 'Rating must be a number between 1 and 5.';
        }
        return $errors;
    }

    public function getAll($page = null, $perPage = null) {
        $query = 'SELECT r.*, pv.product_id 
                  FROM ' . $this->table . ' r 
                  JOIN product_variants pv ON r.variant_id = pv.variant_id 
                  ORDER BY r.rating_id DESC';
        
        $countStmt = null;
        $totalRatings = null;
        if ($page !== null && $perPage !== null) {
            $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM ' . $this->table);
            $countStmt->execute();
            $totalRatings = $countStmt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $query .= ' LIMIT :perPage OFFSET :offset';
        }

        $stmt = $this->conn->prepare($query);
        if ($page !== null && $perPage !== null) {
            $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();

        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'success' => true,
            'ratings' => $ratings
        ];
        
        if ($page !== null && $perPage !== null) {
            $result['page'] = $page;
            $result['perPage'] = $perPage;
            $result['totalRatings'] = $totalRatings;
            $result['totalPages'] = ceil($totalRatings / $perPage);
        }
        
        return $result;
    }

    public function getByVariantId($variant_id, $page = null, $perPage = null) {
        $query = 'SELECT r.*, pv.product_id 
                  FROM ' . $this->table . ' r 
                  JOIN product_variants pv ON r.variant_id = pv.variant_id 
                  WHERE r.variant_id = :variant_id 
                  ORDER BY r.rating_id DESC';
        
        $countStmt = null;
        $totalRatings = null;
        if ($page !== null && $perPage !== null) {
            $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM ' . $this->table . ' WHERE variant_id = :variant_id');
            $countStmt->bindParam(':variant_id', $variant_id, PDO::PARAM_INT);
            $countStmt->execute();
            $totalRatings = $countStmt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $query .= ' LIMIT :perPage OFFSET :offset';
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':variant_id', $variant_id, PDO::PARAM_INT);
        if ($page !== null && $perPage !== null) {
            $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();

        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'success' => true,
            'ratings' => $ratings
        ];
        
        if ($page !== null && $perPage !== null) {
            $result['page'] = $page;
            $result['perPage'] = $perPage;
            $result['totalRatings'] = $totalRatings;
            $result['totalPages'] = ceil($totalRatings / $perPage);
        }
        
        return $result;
    }

    public function getById($rating_id) {
        $query = 'SELECT r.*, pv.product_id 
                  FROM ' . $this->table . ' r 
                  JOIN product_variants pv ON r.variant_id = pv.variant_id 
                  WHERE r.rating_id = :rating_id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':rating_id', $rating_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function hasUserRatedVariant($variant_id, $user_id) {
        $query = 'SELECT COUNT(*) FROM ' . $this->table . ' WHERE variant_id = :variant_id AND user_id = :user_id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':variant_id', $variant_id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function createRating() {
        $errors = $this->validateInput();
        if (!empty($errors)) return ['success' => false, 'errors' => $errors];

        $query = 'INSERT INTO ' . $this->table . ' (variant_id, user_id, rating) 
                  VALUES (:variant_id, :user_id, :rating)';
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':variant_id', $this->variant_id);
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

    public function getAverageRating($variant_id) {
        $query = 'SELECT AVG(rating) as average_rating, COUNT(*) as rating_count 
                  FROM ' . $this->table . ' 
                  WHERE variant_id = :variant_id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':variant_id', $variant_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'average_rating' => round($result['average_rating'], 1) ?: 0,
            'rating_count' => (int)$result['rating_count']
        ];
    }

    // Optional: Add method to get average rating for a product (all variants)
    public function getProductAverageRating($product_id) {
        $query = 'SELECT AVG(r.rating) as average_rating, COUNT(*) as rating_count 
                  FROM ' . $this->table . ' r 
                  JOIN product_variants pv ON r.variant_id = pv.variant_id 
                  WHERE pv.product_id = :product_id';
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