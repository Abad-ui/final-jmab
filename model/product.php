<?php
require_once '../config/database.php';

class Product {
    private $conn;
    private $table = 'products';
    private $variantTable = 'product_variants';

    public $product_id, $name, $description, $category, $subcategory, $image_url, $brand, $model;
    public $variants = []; // Array to hold variant data (price, stock, size)

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    private function validateInput() {
        $errors = [];
        if (empty($this->name)) $errors[] = 'Product name is required.';
        if (empty($this->category)) $errors[] = 'Product category is required.';
        if (empty($this->image_url)) $errors[] = 'Product image URL is required.';
        if (empty($this->brand)) $errors[] = 'Product brand is required.';
        if (empty($this->variants)) $errors[] = 'At least one variant is required.';
        // Model is optional, so no validation required unless you decide otherwise
        return $errors;
    }

    public function getProducts($page = null, $perPage = null) {
        $query = 'SELECT * FROM ' . $this->table . ' ORDER BY product_id DESC';
        $countStmt = null;

        // Only prepare count and pagination if parameters are provided
        if ($page !== null && $perPage !== null) {
            $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM ' . $this->table);
            $countStmt->execute();
            $totalProducts = $countStmt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $query .= ' LIMIT :perPage OFFSET :offset';
        }

        $stmt = $this->conn->prepare($query);
        
        if ($page !== null && $perPage !== null) {
            $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$product) {
            $product['variants'] = $this->getVariantsByProductId($product['product_id']);
        }

        $result = [
            'success' => true,
            'products' => $products
        ];

        if ($page !== null && $perPage !== null) {
            $result['page'] = $page;
            $result['perPage'] = $perPage;
            $result['totalProducts'] = $totalProducts;
            $result['totalPages'] = ceil($totalProducts / $perPage);
        }

        return $result;
    }

    public function getProductById($product_id) {
        $stmt = $this->conn->prepare('SELECT * FROM ' . $this->table . ' WHERE product_id = :product_id');
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $product['variants'] = $this->getVariantsByProductId($product_id);
        }

        return $product;
    }

    private function getVariantsByProductId($product_id) {
        $stmt = $this->conn->prepare('SELECT * FROM ' . $this->variantTable . ' WHERE product_id = :product_id');
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createProduct() {
        $errors = $this->validateInput();
        if (!empty($errors)) return ['success' => false, 'errors' => $errors];

        // Insert product
        $query = 'INSERT INTO ' . $this->table . ' (name, description, category, subcategory, image_url, brand, model) 
                  VALUES (:name, :description, :category, :subcategory, :image_url, :brand, :model)';
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category', $this->category);
        $stmt->bindParam(':subcategory', $this->subcategory);
        $stmt->bindParam(':image_url', $this->image_url);
        $stmt->bindParam(':brand', $this->brand);
        $stmt->bindParam(':model', $this->model);

        try {
            $this->conn->beginTransaction();

            if ($stmt->execute()) {
                $product_id = $this->conn->lastInsertId();

                // Insert variants
                foreach ($this->variants as $variant) {
                    $variantQuery = 'INSERT INTO ' . $this->variantTable . ' (product_id, price, stock, size) 
                                     VALUES (:product_id, :price, :stock, :size)';
                    $variantStmt = $this->conn->prepare($variantQuery);
                    $variantStmt->bindParam(':product_id', $product_id);
                    $variantStmt->bindParam(':price', $variant['price']);
                    $variantStmt->bindParam(':stock', $variant['stock']);
                    $variantStmt->bindParam(':size', $variant['size']);
                    $variantStmt->execute();
                }

                $this->conn->commit();
                return ['success' => true, 'message' => 'Product created successfully.', 'product_id' => $product_id];
            } else {
                $this->conn->rollBack();
                return ['success' => false, 'errors' => ['Unknown error occurred.']];
            }
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function createVariant($product_id, $variantData) {
        if (!$this->getProductById($product_id)) {
            return ['success' => false, 'errors' => ['Product not found.']];
        }

        $query = 'INSERT INTO ' . $this->variantTable . ' (product_id, price, stock, size) 
                  VALUES (:product_id, :price, :stock, :size)';
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':product_id', $product_id);
        $stmt->bindParam(':price', $variantData['price']);
        $stmt->bindParam(':stock', $variantData['stock']);
        $stmt->bindParam(':size', $variantData['size']);

        try {
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'variant_id' => $this->conn->lastInsertId(),
                    'message' => 'Variant created successfully.'
                ];
            }
            return ['success' => false, 'errors' => ['Failed to create variant.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function updateVariant($variant_id, $variantData) {
        $checkStmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM ' . $this->variantTable . ' WHERE variant_id = :variant_id'
        );
        $checkStmt->bindParam(':variant_id', $variant_id);
        $checkStmt->execute();

        if ($checkStmt->fetchColumn() == 0) {
            return ['success' => false, 'errors' => ['Variant not found.']];
        }

        $updates = [];
        $params = [':variant_id' => $variant_id];

        foreach (['price', 'stock', 'size'] as $field) {
            if (isset($variantData[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $variantData[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => true, 'message' => 'No changes provided.'];
        }

        $query = 'UPDATE ' . $this->variantTable . ' SET ' . implode(', ', $updates) . 
                ' WHERE variant_id = :variant_id';
        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        try {
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Variant updated successfully.'];
            }
            return ['success' => false, 'errors' => ['Failed to update variant.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function deleteVariant($variant_id) {
        $checkStmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM ' . $this->variantTable . ' WHERE variant_id = :variant_id'
        );
        $checkStmt->bindParam(':variant_id', $variant_id);
        $checkStmt->execute();

        if ($checkStmt->fetchColumn() == 0) {
            return ['success' => false, 'errors' => ['Variant not found.']];
        }

        // Prevent deletion if this is the last variant
        $countStmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM ' . $this->variantTable . ' WHERE product_id = 
            (SELECT product_id FROM ' . $this->variantTable . ' WHERE variant_id = :variant_id)'
        );
        $countStmt->bindParam(':variant_id', $variant_id);
        $countStmt->execute();

        if ($countStmt->fetchColumn() <= 1) {
            return ['success' => false, 'errors' => ['Cannot delete the last variant of a product.']];
        }

        $query = 'DELETE FROM ' . $this->variantTable . ' WHERE variant_id = :variant_id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':variant_id', $variant_id);

        try {
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Variant deleted successfully.'];
            }
            return ['success' => false, 'errors' => ['Failed to delete variant.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function updateProduct($product_id, $data) {
        if (!$this->getProductById($product_id)) {
            return ['success' => false, 'errors' => ['Product not found.']];
        }

        // Update product
        $updates = [];
        $params = [':product_id' => $product_id];

        foreach (['name', 'description', 'category', 'subcategory', 'image_url', 'brand', 'model'] as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (!empty($updates)) {
            $query = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $updates) . ' WHERE product_id = :product_id';
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
        }

        // Update variants (if provided)
        if (isset($data['variants'])) {
            // Delete existing variants
            $deleteStmt = $this->conn->prepare('DELETE FROM ' . $this->variantTable . ' WHERE product_id = :product_id');
            $deleteStmt->bindParam(':product_id', $product_id);
            $deleteStmt->execute();

            // Insert new variants
            foreach ($data['variants'] as $variant) {
                $variantQuery = 'INSERT INTO ' . $this->variantTable . ' (product_id, price, stock, size) 
                                 VALUES (:product_id, :price, :stock, :size)';
                $variantStmt = $this->conn->prepare($variantQuery);
                $variantStmt->bindParam(':product_id', $product_id);
                $variantStmt->bindParam(':price', $variant['price']);
                $variantStmt->bindParam(':stock', $variant['stock']);
                $variantStmt->bindParam(':size', $variant['size']);
                $variantStmt->execute();
            }
        }

        return ['success' => true, 'message' => 'Product updated successfully.'];
    }

    public function deleteProduct($product_id) {
        // Check for ongoing orders related to this product
        $checkStmt = $this->conn->prepare(
            'SELECT COUNT(*) 
             FROM order_items oi
             INNER JOIN orders o ON oi.order_id = o.order_id
             WHERE oi.product_id = :product_id 
             AND o.status IN ("pending", "processing", "out for delivery", "failed delivery")'
        );
        $checkStmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        
        try {
            $checkStmt->execute();
            $transactionCount = $checkStmt->fetchColumn();
            
            if ($transactionCount > 0) {
                return [
                    'success' => false,
                    'errors' => ['Cannot delete product with ongoing orders.']
                ];
            }
            
            // If no ongoing orders, proceed with deletion
            $this->conn->beginTransaction();

            // Delete variants first
            $deleteVariantsStmt = $this->conn->prepare('DELETE FROM ' . $this->variantTable . ' WHERE product_id = :product_id');
            $deleteVariantsStmt->bindParam(':product_id', $product_id);
            $deleteVariantsStmt->execute();

            // Delete product
            $deleteProductStmt = $this->conn->prepare('DELETE FROM ' . $this->table . ' WHERE product_id = :product_id');
            $deleteProductStmt->bindParam(':product_id', $product_id);
            $deleteProductStmt->execute();

            $this->conn->commit();
            return ['success' => true, 'message' => 'Product deleted successfully.'];
                
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log('Database Error: ' . $e->getMessage());
            return [
                'success' => false,
                'errors' => ['Something went wrong. Please try again.']
            ];
        }
    }

    public function searchProducts($filters = [], $page = null, $perPage = null) {
        $query = 'SELECT * FROM ' . $this->table . ' WHERE 1=1';
        $countQuery = 'SELECT COUNT(*) FROM ' . $this->table . ' WHERE 1=1';
        $params = [];

        if (!empty($filters['brand'])) {
            $query .= ' AND brand LIKE :brand';
            $countQuery .= ' AND brand LIKE :brand';
            $params[':brand'] = '%' . $filters['brand'] . '%';
        }
        if (!empty($filters['model'])) {
            $query .= ' AND model LIKE :model';
            $countQuery .= ' AND model LIKE :model';
            $params[':model'] = '%' . $filters['model'] . '%';
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

        $query .= ' ORDER BY product_id DESC';
        $countStmt = null;

        if ($page !== null && $perPage !== null) {
            $countStmt = $this->conn->prepare($countQuery);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $totalProducts = $countStmt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $query .= ' LIMIT :perPage OFFSET :offset';
        }

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($page !== null && $perPage !== null) {
            $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$product) {
            $product['variants'] = $this->getVariantsByProductId($product['product_id']);
        }

        $result = [
            'success' => true,
            'products' => $products
        ];

        if ($page !== null && $perPage !== null) {
            $result['page'] = $page;
            $result['perPage'] = $perPage;
            $result['totalProducts'] = $totalProducts;
            $result['totalPages'] = ceil($totalProducts / $perPage);
        }

        return $result;
    }
}
?>