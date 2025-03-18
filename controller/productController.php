<?php
require_once '../model/product.php';
require_once '../config/JWTHandler.php';

class ProductController {
    private $productModel;

    public function __construct() {
        $this->productModel = new Product();
    }

    private function authenticateAPI() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            throw new Exception('Authorization token is required.', 401);
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $result = JWTHandler::validateJWT($token);
        if (!$result['success']) {
            throw new Exception(implode(', ', $result['errors']), 401);
        }
        return $result['user'];
    }

    private function isAdmin($userData) {
        $roles = isset($userData['roles']) ? (is_array($userData['roles']) ? $userData['roles'] : [$userData['roles']]) : [];
        return in_array('admin', $roles);
    }

    public function getAll($page = null, $perPage = null) {
        $result = $this->productModel->getProducts($page, $perPage);
        return [
            'status' => 200,
            'body' => $result
        ];
    }

    public function getById($id) {
        $productInfo = $this->productModel->getProductById($id);
        return $productInfo 
            ? ['status' => 200, 'body' => ['success' => true, 'products' => $productInfo]] 
            : ['status' => 404, 'body' => ['success' => false, 'errors' => ['Product not found.']]];
    }

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
        $this->productModel->image_url = $data['image_url'] ?? '';
        $this->productModel->brand = $data['brand'] ?? '';
        $this->productModel->model = $data['model'] ?? null; // Added model
        $this->productModel->variants = $data['variants'] ?? []; // Array of variants
    
        $result = $this->productModel->createProduct();
        return [
            'status' => $result['success'] ? 201 : 400,
            'body' => $result
        ];
    }

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
        
        $status = $result['success'] ? 200 : 400;
        if (isset($result['errors']) && in_array('Cannot delete product with ongoing orders.', $result['errors'])) {
            $status = 409; // Conflict status code
        }
    
        return [
            'status' => $status,
            'body' => $result
        ];
    }

    public function search(array $filters, $page = null, $perPage = null) {
        $filters = [
            'brand' => $filters['brand'] ?? null,
            'model' => $filters['model'] ?? null,
            'category' => $filters['category'] ?? null,
            'subcategory' => $filters['subcategory'] ?? null,
            'name' => $filters['name'] ?? null
        ];

        $result = $this->productModel->searchProducts($filters, $page, $perPage);
        return [
            'status' => 200,
            'body' => $result
        ];
    }

    public function createVariant($product_id, array $data) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to create a variant.']]
            ];
        }
        
        if (empty($product_id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Product ID is required.']]
            ];
        }

        $variantData = [
            'price' => $data['price'] ?? null,
            'stock' => $data['stock'] ?? null,
            'size' => $data['size'] ?? null
        ];
        $result = $this->productModel->createVariant((int)$product_id, $variantData);
        return [
            'status' => $result['success'] ? 201 : 400,
            'body' => $result
        ];
    }

    public function updateVariant($variant_id, array $data) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to update a variant.']]
            ];
        }
        if (empty($variant_id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Variant ID is required.']]
            ];
        }

        $variantData = [
            'price' => $data['price'] ?? null,
            'stock' => $data['stock'] ?? null,
            'size' => $data['size'] ?? null
        ];

        $result = $this->productModel->updateVariant($variant_id, $variantData);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }

    public function deleteVariant($variant_id) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to delete a variant.']]
            ];
        }
        if (empty($variant_id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Variant ID is required.']]
            ];
        }

        $result = $this->productModel->deleteVariant($variant_id);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }
}
?>