<?php
require_once '../config/database.php';

class Product {
    private $conn;
    private $table = 'products';

    public $product_id, $name, $description, $category, $subcategory, $price, $stock, $image_url, $brand, $size, $voltage;

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    private function validateInput() {
        $errors = [];
        if (empty($this->name)) $errors[] = 'Product name is required.';
        if (empty($this->category)) $errors[] = 'Product category is required.';
        if (empty($this->price)) $errors[] = 'Product price is required.';
        if (empty($this->stock)) $errors[] = 'Product stock is required.';
        if (empty($this->image_url)) $errors[] = 'Product image URL is required.';
        if (empty($this->brand)) $errors[] = 'Product brand is required.';
        if ($this->category === 'Tires' && empty($this->size)) $errors[] = 'Size is required for tires.';
        if ($this->category === 'Batteries' && empty($this->voltage)) $errors[] = 'Voltage is required for batteries.';
        return $errors;
    }

    public function getProducts($page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM ' . $this->table);
        $countStmt->execute();
        $totalProducts = $countStmt->fetchColumn();

        // Get paginated products
        $query = 'SELECT * FROM ' . $this->table . ' ORDER BY product_id DESC LIMIT :perPage OFFSET :offset';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'products' => $products,
            'page' => $page,
            'perPage' => $perPage,
            'totalProducts' => $totalProducts,
            'totalPages' => ceil($totalProducts / $perPage)
        ];
    }

    public function getProductById($product_id) {
        $stmt = $this->conn->prepare('SELECT * FROM ' . $this->table . ' WHERE product_id = :product_id');
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createProduct() {
        $errors = $this->validateInput();
        if (!empty($errors)) return ['success' => false, 'errors' => $errors];

        $query = 'INSERT INTO ' . $this->table . ' (name, description, category, subcategory, price, stock, image_url, brand, size, voltage) 
                  VALUES (:name, :description, :category, :subcategory, :price, :stock, :image_url, :brand, :size, :voltage)';
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category', $this->category);
        $stmt->bindParam(':subcategory', $this->subcategory);
        $stmt->bindParam(':price', $this->price);
        $stmt->bindParam(':stock', $this->stock);
        $stmt->bindParam(':image_url', $this->image_url);
        $stmt->bindParam(':brand', $this->brand);
        $stmt->bindParam(':size', $this->size);
        $stmt->bindParam(':voltage', $this->voltage);

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Product created successfully.'] 
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function updateProduct($product_id, $data) {
        if (!$this->getProductById($product_id)) {
            return ['success' => false, 'errors' => ['Product not found.']];
        }
    
        $updates = [];
        $params = [':product_id' => $product_id];
    
        foreach (['name', 'description', 'category', 'subcategory', 'price', 'stock', 'image_url', 'brand', 'size', 'voltage'] as $field) {
            if ($field === 'price' && isset($data['price']) && $data['price'] !== '' && !is_numeric($data['price'])) {
                return ['success' => false, 'errors' => ['Price must be numeric.']];
            }
    
            if (isset($data[$field]) && $data[$field] !== '') {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
    
        
    
        if (empty($updates)) {
            return ['success' => false, 'errors' => ['No valid fields to update.']];
        }
    
        $query = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $updates) . ' WHERE product_id = :product_id';
        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
    
        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Product updated successfully.'] 
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function deleteProduct($product_id) {
        $stmt = $this->conn->prepare('DELETE FROM ' . $this->table . ' WHERE product_id = :product_id');
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Product deleted successfully.'] 
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function searchProducts($filters = [], $page = 1, $perPage = 20) {
        $query = 'SELECT * FROM ' . $this->table . ' WHERE 1=1';
        $countQuery = 'SELECT COUNT(*) FROM ' . $this->table . ' WHERE 1=1';
        $params = [];

        if (!empty($filters['brand'])) {
            $query .= ' AND brand LIKE :brand';
            $countQuery .= ' AND brand LIKE :brand';
            $params[':brand'] = '%' . $filters['brand'] . '%';
        }
        if (!empty($filters['name'])) {
            $query .= ' AND name LIKE :name';
            $countQuery .= ' AND name LIKE :name';
            $params[':name'] = '%' . $filters['name'] . '%';
        }
        if (!empty($filters['category'])) {
            $query .= ' AND category = :category';
            $countQuery .= ' AND category = :category';
            $params[':category'] = $filters['category'];
        }
        if (!empty($filters['subcategory'])) {
            $query .= ' AND subcategory = :subcategory';
            $countQuery .= ' AND subcategory = :subcategory';
            $params[':subcategory'] = $filters['subcategory'];
        }
        
        // Get total count
        $countStmt = $this->conn->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalProducts = $countStmt->fetchColumn();

        // Add pagination to main query
        $offset = ($page - 1) * $perPage;
        $query .= ' ORDER BY product_id DESC LIMIT :perPage OFFSET :offset';
        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'products' => $products,
            'page' => $page,
            'perPage' => $perPage,
            'totalProducts' => $totalProducts,
            'totalPages' => ceil($totalProducts / $perPage)
        ];
    }
}
?>