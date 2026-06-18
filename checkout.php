<?php
// checkout.php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/modules/cart_module.php';
require_once __DIR__ . '/modules/order_module.php';
require_once __DIR__ . '/modules/payment_module.php';
require_once __DIR__ . '/includes/mail_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$cartModule = new CartModule($pdo);
$orderModule = new OrderModule($pdo);
$paymentModule = new PaymentModule($pdo);

$cart = $cartModule->getCart($_SESSION['user_id']);
$message = '';
$error = '';
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (empty($cart['items'])) {
        $error = 'Your cart is empty.';
    } else {
        $shippingAddress = [
            'name' => trim($_POST['name'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'zip' => trim($_POST['zip'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
        ];

        $paymentMethod = $_POST['payment_method'] ?? 'card';
        $paymentDetails = [
            'card_number' => trim($_POST['card_number'] ?? ''),
            'expiry' => trim($_POST['expiry'] ?? ''),
            'cvv' => trim($_POST['cvv'] ?? ''),
        ];

        $orderResult = $orderModule->createOrder($_SESSION['user_id'], $cart['items'], $shippingAddress);
        if ($orderResult['success']) {
            $paymentModule->initializePaymentRecord(
                $orderResult['order_id'],
                $paymentMethod,
                [
                    'source' => 'checkout',
                    'details' => $paymentDetails,
                ],
                [
                    'status' => 'pending',
                    'reference_number' => $orderResult['order_number'],
                ]
            );

            $paymentResult = $paymentModule->processPayment($orderResult['order_id'], $paymentMethod, $paymentDetails);
            if ($paymentResult['success']) {
                $cartModule->clearCart($_SESSION['user_id']);

                $mailHelper->sendOrderConfirmation(
                    $_SESSION['email'],
                    $_SESSION['username'],
                    [
                        'order_number' => $orderResult['order_number'],
                        'date' => date('Y-m-d H:i'),
                        'total' => number_format($orderResult['total'], 2),
                        'status' => ucfirst($paymentResult['payment_status'] ?? 'paid'),
                    ]
                );

                $message = 'Payment successful. Your order has been placed.';
            } else {
                $error = $paymentResult['message'] ?? 'Payment failed.';
            }
        } else {
            $error = $orderResult['message'] ?? 'Failed to create order.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Checkout</h2>
            <a href="cart.php" class="btn btn-outline-secondary">Back to Cart</a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($cart['items'])): ?>
        <div class="card mb-3">
            <div class="card-body">
                <h5>Order Summary</h5>
                <ul class="list-group">
                    <?php foreach ($cart['items'] as $item): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= htmlspecialchars($item['name']) ?> x <?= $item['quantity'] ?></span>
                            <span>$<?= number_format($item['subtotal'], 2) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <strong>Total</strong>
                        <strong>$<?= number_format($cart['total'], 2) ?></strong>
                    </li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <h5 class="mb-3">Shipping Details</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">State</label>
                            <input type="text" name="state" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Zip</label>
                            <input type="text" name="zip" class="form-control" required>
                        </div>
                    </div>

                    <h5 class="mb-3">Payment</h5>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="card">Card</option>
                            <option value="paypal">PayPal</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Card Number</label>
                            <input type="text" name="card_number" class="form-control" placeholder="4111 1111 1111 1111">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Expiry</label>
                            <input type="text" name="expiry" class="form-control" placeholder="MM/YY">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">CVV</label>
                            <input type="text" name="cvv" class="form-control" placeholder="123">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Place Order</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
