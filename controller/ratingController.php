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

    public function getAll($page = 1, $perPage = 20) {
        $this->authenticateAPI(); // Require authentication for all ratings
        $result = $this->ratingModel->getAll($page, $perPage);
        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'ratings' => $result['ratings'],
                'page' => $result['page'],
                'perPage' => $result['perPage'],
                'totalRatings' => $result['totalRatings'],
                'totalPages' => $result['totalPages']
            ]
        ];
    }

    public function getByProductId($product_id, $page = 1, $perPage = 20) {
        $this->authenticateAPI(); // Require authentication to view product ratings
        $result = $this->ratingModel->getByProductId($product_id, $page, $perPage);
        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'ratings' => $result['ratings'],
                'page' => $result['page'],
                'perPage' => $result['perPage'],
                'totalRatings' => $result['totalRatings'],
                'totalPages' => $result['totalPages']
            ]
        ];
    }

    public function getById($rating_id) {
        $this->authenticateAPI();
        $ratingInfo = $this->ratingModel->getById($rating_id);
        return $ratingInfo 
            ? ['status' => 200, 'body' => ['success' => true, 'rating' => $ratingInfo]]
            : ['status' => 404, 'body' => ['success' => false, 'errors' => ['Rating not found.']]];
    }

    public function getAverageRating($product_id) {
        $this->authenticateAPI();
        
        // First check if product exists
        $productModel = new Product();
        $product = $productModel->getProductById($product_id);
        
        if (!$product) {
            return [
                'status' => 404,
                'body' => [
                    'success' => false, 
                    'errors' => ['Product not found.']
                ]
            ];
        }
        
        // If product exists, get the average rating
        $result = $this->ratingModel->getAverageRating($product_id);
        return [
            'status' => 200,
            'body' => $result
        ];
    }

    public function create(array $data) {
        $userData = $this->authenticateAPI();
        
        // Set model properties
        $this->ratingModel->product_id = $data['product_id'] ?? null;
        $this->ratingModel->user_id = $userData['sub']; // Use authenticated user's ID
        $this->ratingModel->rating = $data['rating'] ?? null;

        // Check if user has already rated this product
        $existingRating = $this->ratingModel->getByProductId($this->ratingModel->product_id);
        foreach ($existingRating['ratings'] as $rating) {
            if ($rating['user_id'] == $this->ratingModel->user_id) {
                return [
                    'status' => 400,
                    'body' => ['success' => false, 'errors' => ['You have already rated this product. Use update instead.']]
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
        
        // Only allow users to update their own ratings, or admins
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
        
        // Only allow users to delete their own ratings, or admins
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