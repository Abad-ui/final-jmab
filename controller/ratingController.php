<?php
require_once '../model/rating.php';
require_once '../vendor/autoload.php';

class RatingController {
    private $ratingModel;
    private $productModel;

    public function __construct() {
        $this->ratingModel = new Rating();
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
        $this->authenticateAPI();
        $result = $this->ratingModel->getAll($page, $perPage);
        return [
            'status' => 200,
            'body' => $result
        ];
    }

    public function getByVariantId($variant_id, $page = null, $perPage = null) {
        $this->authenticateAPI();
        $result = $this->ratingModel->getByVariantId($variant_id, $page, $perPage);
        return [
            'status' => 200,
            'body' => $result
        ];
    }

    public function getById($rating_id) {
        $this->authenticateAPI();
        $ratingInfo = $this->ratingModel->getById($rating_id);
        return $ratingInfo 
            ? ['status' => 200, 'body' => ['success' => true, 'rating' => $ratingInfo]]
            : ['status' => 404, 'body' => ['success' => false, 'errors' => ['Rating not found.']]];
    }

    public function getAverageRating($variant_id) {
        $this->authenticateAPI();
        $variantExists = $this->productModel->getVariantById($variant_id);
    
        if (!$variantExists) {
            return [
                'status' => 404,
                'body' => [
                    'success' => false, 
                    'errors' => ['Variant not found.']
                ]
            ];
        }
        
        $result = $this->ratingModel->getAverageRating($variant_id);
        return [
            'status' => 200,
            'body' => $result,
        ];
    }

    public function getProductAverageRating($product_id) {
        $this->authenticateAPI();
        
        $product = $this->productModel->getProductById($product_id);
        
        if (!$product) {
            return [
                'status' => 404,
                'body' => [
                    'success' => false, 
                    'errors' => ['Product not found.']
                ]
            ];
        }
        
        $result = $this->ratingModel->getProductAverageRating($product_id);
        return [
            'status' => 200,
            'body' => $result
        ];
    }

    public function hasUserRated($variant_id) {
        $userData = $this->authenticateAPI();
        $hasRated = $this->ratingModel->hasUserRatedVariant($variant_id, $userData['sub']);
        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'hasRated' => $hasRated
            ]
        ];
    }

    public function create(array $data) {
        $userData = $this->authenticateAPI();
        
        $this->ratingModel->variant_id = $data['variant_id'] ?? null;
        $this->ratingModel->user_id = $userData['sub'];
        $this->ratingModel->rating = $data['rating'] ?? null;

        $existingRating = $this->ratingModel->getByVariantId($this->ratingModel->variant_id);
        foreach ($existingRating['ratings'] as $rating) {
            if ($rating['user_id'] == $this->ratingModel->user_id) {
                return [
                    'status' => 400,
                    'body' => ['success' => false, 'errors' => ['You have already rated this variant. Use update instead.']]
                ];
            }
        }

        $result = $this->ratingModel->createRating();
        return [
            'status' => $result['success'] ? 201 : 400,
            'body' => $result
        ];
    }

    public function update($rating_id, array $data) {
        $userData = $this->authenticateAPI();
        
        $ratingInfo = $this->ratingModel->getById($rating_id);
        if (!$ratingInfo) {
            return [
                'status' => 404,
                'body' => ['success' => false, 'errors' => ['Rating not found.']]
            ];
        }
        
        if ($ratingInfo['user_id'] != $userData['sub'] && !$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only update your own ratings.']]
            ];
        }

        $result = $this->ratingModel->updateRating($rating_id, $data);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }

    public function delete($rating_id) {
        $userData = $this->authenticateAPI();
        
        $ratingInfo = $this->ratingModel->getById($rating_id);
        if (!$ratingInfo) {
            return [
                'status' => 404,
                'body' => ['success' => false, 'errors' => ['Rating not found.']]
            ];
        }
        
        if ($ratingInfo['user_id'] != $userData['sub'] && !$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only delete your own ratings.']]
            ];
        }

        $result = $this->ratingModel->deleteRating($rating_id);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }
}
?>