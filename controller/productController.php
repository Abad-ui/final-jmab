<?php
require_once '../model/product.php';
require_once '../model/user.php';

class ProductController {
    private $productModel;

    // Dependency injection via constructor
    public function __construct(Product $productModel) {
        $this->productModel = $productModel;
    }

    // Authentication middleware
    private function authenticateAPI() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            throw new Exception('Authorization token is required.', 401);
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $result = User::validateJWT($token);
        if (!$result['success']) {
            throw new Exception(implode(', ', $result['errors']), 401);
        }
        return $result['user'];
    }

    // Check if the authenticated user is an admin
    private function isAdmin($userData) {
        if (isset($userData['roles'])) {
            $roles = is_array($userData['roles']) ? $userData['roles'] : [$userData['roles']];
            return in_array('admin', $roles);
        }
        return false;
    }

    // Get all products
    public function getAll() {
        $products = $this->productModel->getProducts();
        return [
            'status' => 200,
            'body' => ['success' => true, 'data' => $products]
        ];
    }

    // Get a product by ID
    public function getById($id) {
        $productInfo = $this->productModel->getProductById($id);
        if ($productInfo) {
            return [
                'status' => 200,
                'body' => ['success' => true, 'data' => $productInfo]
            ];
        }
        return [
            'status' => 404,
            'body' => ['success' => false, 'errors' => ['Product not found.']]
        ];
    }

    // Create a new product (admin only)
    public function create(array $data) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to create a product.']]
            ];
        }

        $this->productModel->name = $data['name'] ?? '';
        $this->productModel->description = $data['description'] ?? '';
        $this->productModel->category = $data['category'] ?? '';
        $this->productModel->subcategory = $data['subcategory'] ?? null;
        $this->productModel->price = $data['price'] ?? 0;
        $this->productModel->stock = $data['stock'] ?? 0;
        $this->productModel->image_url = $data['image_url'] ?? '';
        $this->productModel->brand = $data['brand'] ?? '';
        $this->productModel->size = $data['size'] ?? null;
        $this->productModel->voltage = $data['voltage'] ?? null;
        $this->productModel->tags = $data['tags'] ?? [];

        $result = $this->productModel->createProduct();
        return [
            'status' => $result['success'] ? 201 : 400,
            'body' => $result
        ];
    }

    // Update a product by ID (admin only)
    public function update($id, array $data) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to update a product.']]
            ];
        }

        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Product ID is required.']]
            ];
        }

        $result = $this->productModel->updateProduct($id, $data);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }

    // Delete a product by ID (admin only)
    public function delete($id) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to delete a product.']]
            ];
        }

        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Product ID is required.']]
            ];
        }

        $result = $this->productModel->deleteProduct($id);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }

    // Search products with filters
    public function search(array $filters) {
        $filters = [
            'brand' => $filters['brand'] ?? null,
            'category' => $filters['category'] ?? null,
            'subcategory' => $filters['subcategory'] ?? null,
            'name' => $filters['name'] ?? null,
            'tags' => isset($filters['tags']) ? (is_array($filters['tags']) ? $filters['tags'] : explode(',', $filters['tags'])) : null,
        ];

        $results = $this->productModel->searchProducts($filters);
        if (!empty($results)) {
            return [
                'status' => 200,
                'body' => ['success' => true, 'data' => $results]
            ];
        }
        return [
            'status' => 404,
            'body' => ['success' => false, 'errors' => ['No products found.']]
        ];
    }
}
?>