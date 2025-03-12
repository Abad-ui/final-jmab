<?php
require_once '../config/database.php';

class Product {
    private $conn;
    private $table = 'products';

    public $product_id, $name, $description, $category, $subcategory, $price, $stock, $image_url, $brand, $size, $voltage, $tags;

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

    public function getProducts() {
        $stmt = $this->conn->prepare('SELECT * FROM ' . $this->table);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $query = 'INSERT INTO ' . $this->table . ' (name, description, category, subcategory, price, stock, image_url, brand, size, voltage, tags) 
                  VALUES (:name, :description, :category, :subcategory, :price, :stock, :image_url, :brand, :size, :voltage, :tags)';
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
        $tagsJson = json_encode($this->tags);
        $stmt->bindParam(':tags', $tagsJson);

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
    
            if (isset($data[$field]) && $data[$field] !== '') { // Skip empty strings
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
    
        if (isset($data['tags']) && $data['tags'] !== '') { // Skip empty tags
            $updates[] = 'tags = :tags';
            $params[':tags'] = json_encode($data['tags']);
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

    public function searchProducts($filters = []) {
        $query = 'SELECT * FROM ' . $this->table . ' WHERE 1=1';
        $params = [];

        if (!empty($filters['brand'])) {
            $query .= ' AND brand LIKE :brand';
            $params[':brand'] = '%' . $filters['brand'] . '%';
        }
        if (!empty($filters['name'])) {
            $query .= ' AND name LIKE :name';
            $params[':name'] = '%' . $filters['name'] . '%';
        }
        if (!empty($filters['category'])) {
            $query .= ' AND category = :category';
            $params[':category'] = $filters['category'];
        }
        if (!empty($filters['subcategory'])) {
            $query .= ' AND subcategory = :subcategory';
            $params[':subcategory'] = $filters['subcategory'];
        }
        if (!empty($filters['tags'])) {
            $query .= ' AND JSON_CONTAINS(tags, :tags)';
            $params[':tags'] = json_encode($filters['tags']);
        }

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>