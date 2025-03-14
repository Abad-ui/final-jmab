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

    public function getAll($page = 3, $perPage = 4) {
        $result = $this->productModel->getProducts($page, $perPage);
        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'products' => $result['products'],
                'page' => $result['page'],
                'perPage' => $result['perPage'],
                'totalProducts' => $result['totalProducts'],
                'totalPages' => $result['totalPages']
            ]
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
        $this->productModel->price = $data['price'] ?? 0;
        $this->productModel->stock = $data['stock'] ?? 0;
        $this->productModel->image_url = $data['image_url'] ?? '';
        $this->productModel->brand = $data['brand'] ?? '';
        $this->productModel->size = $data['size'] ?? null;
        $this->productModel->voltage = $data['voltage'] ?? null;
        

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
        
        // You could optionally customize the status code based on the error type
        $status = $result['success'] ? 200 : 400;
        if (isset($result['errors']) && in_array('Cannot delete product with ongoing orders.', $result['errors'])) {
            $status = 409; // Conflict status code could be more appropriate here
        }
    
        return [
            'status' => $status,
            'body' => $result
        ];
    }

    public function search(array $filters, $page = 1, $perPage = 20) {
        $filters = [
            'brand' => $filters['brand'] ?? null,
            'category' => $filters['category'] ?? null,
            'subcategory' => $filters['subcategory'] ?? null,
            'name' => $filters['name'] ?? null
        ];

        $result = $this->productModel->searchProducts($filters, $page, $perPage);
        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'products' => $result['products'],
                'page' => $result['page'],
                'perPage' => $result['perPage'],
                'totalProducts' => $result['totalProducts'],
                'totalPages' => $result['totalPages']
            ]
        ];
    }
}
?>