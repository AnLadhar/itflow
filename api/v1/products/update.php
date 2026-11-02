<?php

require_once '../validate_api_key.php';
require_once '../require_post_method.php';

// Products aren't client-scoped; access is gated by module_sales in enforce_api_rbac.php

$product_id = intval($_POST['product_id'] ?? 0);

if (!$product_id) {
    header("HTTP/1.1 400 Bad Request");
    $return_arr['success'] = "False";
    $return_arr['message'] = "product_id is required.";
    echo json_encode($return_arr);
    exit();
}

// functions/products.php - same validation, stock rules and price history as the UI
if (!productSave($_POST, $product_id, $error, 'api')) {
    header("HTTP/1.1 422 Unprocessable Entity");
    $return_arr['success'] = "False";
    $return_arr['message'] = $error;
    echo json_encode($return_arr);
    exit();
}

logAudit("Product", "Edit", "Product #$product_id via API ($api_key_name)");

$return_arr['success'] = "True";
$return_arr['count'] = 1;

// Optional: reprice - udpate price on existing invoices
if (!empty($_POST['reprice'])) {
    $changes = [];
    $own_price = 0;
    foreach (productRepriceLines(['product_id' => $product_id])[$product_id]['lines'] ?? [] as $line) {
        if ($line['old']) {
            $changes[$line['kind'] . ':' . intval($line['item_id'])] = (string) $line['suggested'];
        } else {
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
            logAudit("Product", "Edit", "$api_key_name updated the price of {$done['lines']} line(s) on {$done['documents']} recurring invoice(s), draft(s) and quote(s) from the catalog");
        }
    }
}

echo json_encode($return_arr);
exit();