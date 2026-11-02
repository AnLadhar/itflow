<?php

require_once '../validate_api_key.php';
require_once '../require_post_method.php';

// Products aren't client-scoped; access is gated by module_sales in enforce_api_rbac.php

$input = (array) $_POST;

if (trim((string) ($input['name'] ?? '')) === '' || !isset($input['price'])) {
    header("HTTP/1.1 400 Bad Request");
    $return_arr['success'] = "False";
    $return_arr['message'] = "name and price are required.";
    echo json_encode($return_arr);
    exit();
}

// productSave() puts a new product in the company currency, which the API doesn't load
if (empty($session_company_currency)) {
    $session_company_currency = mysqli_fetch_row(mysqli_query($mysqli, "SELECT company_currency FROM companies WHERE company_id = 1"))[0] ?? '';
}

// functions/products.php - same validation and rules as adding a product in the UI
$product_id = productSave($input, 0, $error, 'api');

if (!$product_id) {
    header("HTTP/1.1 422 Unprocessable Entity");
    $return_arr['success'] = "False";
    $return_arr['message'] = $error ?: "The product couldn't be created.";
    echo json_encode($return_arr);
    exit();
}

logAudit("Product", "Create", "Product #$product_id via API ($api_key_name)");

$return_arr['success'] = "True";
$return_arr['count'] = 1;
$return_arr['data'][] = productRow($product_id);

echo json_encode($return_arr);
exit();