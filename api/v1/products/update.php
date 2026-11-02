<?php

require_once '../validate_api_key.php';
require_once '../require_post_method.php';

// Products aren't client-scoped; access is gated by module_sales in enforce_api_rbac.php

// $_POST is null when the body isn't valid JSON, and productSave() needs an array
$input = (array) $_POST;

$product_id = intval($input['product_id'] ?? 0);

if (!$product_id) {
    header("HTTP/1.1 400 Bad Request");
    $return_arr['success'] = "False";
    $return_arr['message'] = "product_id is required.";
    echo json_encode($return_arr);
    exit();
}

// functions/products.php - same validation, stock rules and price history as the UI
if (!productSave($input, $product_id, $error, 'api')) {
    header("HTTP/1.1 422 Unprocessable Entity");
    $return_arr['success'] = "False";
    $return_arr['message'] = $error;
    echo json_encode($return_arr);
    exit();
}

$product_name = escapeSql(getFieldById('products', $product_id, 'product_name'));
$api_key_name_sql = escapeSql($api_key_name);

// Logging
logAudit("Product", "Edit", "$product_name via API ($api_key_name_sql)", 0, $product_id);
logAudit("API", "Success", "Edited product $product_name via API ($api_key_name_sql)", 0, $product_id);

$return_arr['success'] = "True";
$return_arr['count'] = 1;

// Optional: reprice - move the lines at an old price on recurring invoices, draft invoices and draft quotes to the new price
if (!empty($input['reprice'])) {
    $changes = [];
    $own_price = 0;
    foreach (productRepriceLines(['product_id' => $product_id])[$product_id]['lines'] ?? [] as $line) {
        if ($line['old']) {
            $changes[$line['kind'] . ':' . intval($line['item_id'])] = (string) $line['suggested'];
        } else {
            // A price of its own, agreed with the client - left alone
            $own_price++;
        }
    }

    $done = $changes ? productRepriceApply($changes, $error) : ['lines' => 0, 'documents' => 0, 'monthly' => 0];

    if ($done === null) {
        $return_arr['success'] = "False";
        $return_arr['message'] = "Product updated, but no invoice lines were changed: $error";
    } else {
        $return_arr['reprice'] = $done + ['own_price_skipped' => $own_price];
        if ($done['lines']) {
            logAudit("Product", "Edit", "$api_key_name_sql updated the price of {$done['lines']} line(s) on {$done['documents']} recurring invoice(s), draft(s) and quote(s) from the catalog", 0, $product_id);
        }
    }
}

echo json_encode($return_arr);
exit();
