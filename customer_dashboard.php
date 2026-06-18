<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Ensure a PDO connection is available in this script
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/modules/payment_module.php';
require_once __DIR__ . '/classes/Mpesa.php';
require_once __DIR__ . '/includes/mpesa_db_helper.php';
require_once __DIR__ . '/includes/product_image_helper.php';

$user_role = $_SESSION['role'] ?? 'customer';
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'];
$user_id = $_SESSION['user_id'] ?? 0;

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$message = '';
$message_type = 'success';
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
    $message = 'Your session expired. Refresh the page and try again.';
    $message_type = 'error';
    $_POST = [];
}

if (isset($_POST['add_to_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity'] ?? 1);
    if ($quantity > 0) {
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id] += $quantity;
        } else {
            $_SESSION['cart'][$product_id] = $quantity;
        }
        $message = 'Product added to cart!';
    }
}

if (isset($_POST['remove_from_cart'])) {
    $product_id = intval($_POST['product_id']);
    unset($_SESSION['cart'][$product_id]);
    $message = 'Product removed from cart!';
}

if (isset($_POST['clear_cart'])) {
    $_SESSION['cart'] = [];
    $message = 'Cart cleared!';
}

if (isset($_POST['checkout'])) {
    if (empty($_SESSION['cart'])) {
        $message = 'Your cart is empty!';
        $message_type = 'error';
    } else {
        if ($pdo === null) {
            $message = 'Database connection unavailable. Please try again later.';
            $message_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $paymentModule = new PaymentModule($pdo);
                $stmt = $pdo->prepare("INSERT INTO orders (user_id, order_date, status, payment_status, total_amount) VALUES (?, NOW(), 'pending', 'pending', 0)");
                $stmt->execute([$user_id]);
                $order_id = $pdo->lastInsertId();
                $total = 0;
                $items_count = 0;
                foreach ($_SESSION['cart'] as $product_id => $quantity) {
                    $productStmt = $pdo->prepare("SELECT id, unit_price, quantity, name FROM products WHERE id = ? AND is_active = true");
                    $productStmt->execute([$product_id]);
                    $product = $productStmt->fetch();
                    if ($product && $product['quantity'] >= $quantity) {
                        $unit_price = floatval($product['unit_price'] ?? 0);
                        $subtotal = $unit_price * $quantity;
                        $total += $subtotal;
                        $items_count += $quantity;
                        $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                        $itemStmt->execute([$order_id, $product_id, $quantity, $unit_price, $subtotal]);
                        $updateStmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                        $updateStmt->execute([$quantity, $product_id]);
                    }
                }
                $updateOrder = $pdo->prepare("UPDATE orders SET total_amount = ? WHERE id = ?");
                $updateOrder->execute([$total, $order_id]);
                $paymentInit = $paymentModule->initializePaymentRecord(
                    $order_id,
                    'manual',
                    [
                        'source' => 'customer_dashboard',
                        'mode' => 'checkout',
                    ],
                    [
                        'manage_transaction' => false,
                        'status' => 'pending',
                        'amount' => $total,
                        'reference_number' => 'ORD-' . $order_id,
                    ]
                );
                if (!$paymentInit['success']) {
                    throw new Exception($paymentInit['message'] ?? 'Failed to initialize payment record.');
                }
                $pdo->commit();
                $_SESSION['cart'] = [];
                $message = "Order placed successfully! Order ID: #$order_id ($items_count items - $" . number_format($total, 2) . ")";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $message = 'Error processing order. Please try again.';
                $message_type = 'error';
                error_log("Checkout error: " . $e->getMessage());
            }
        }
    }
}

if (isset($_POST['pay_now'])) {
    if (empty($_SESSION['cart'])) {
        $message = 'Your cart is empty!';
        $message_type = 'error';
    } else {
        $phone = trim($_POST['phone'] ?? '');
        if ($pdo === null) {
            $message = 'Database connection unavailable. Please try again later.';
            $message_type = 'error';
        } elseif (!mpesaTransactionsTableExists($pdo)) {
            $message = 'M-Pesa payments are not ready yet. Please ask an administrator to complete the payment database migration.';
            $message_type = 'error';
        } else {
            $mpesa = new Mpesa();
            $paymentModule = new PaymentModule($pdo);
            if (!$mpesa->validatePhoneNumber($phone)) {
                $message = 'Invalid phone number. Use format 07XXXXXXXX or 254XXXXXXXXX.';
                $message_type = 'error';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("INSERT INTO orders (user_id, order_date, status, payment_status, total_amount) VALUES (?, NOW(), 'pending', 'pending', 0)");
                    $stmt->execute([$user_id]);
                    $order_id = (int)$pdo->lastInsertId();
                    $total = 0;
                    $items_count = 0;
                    foreach ($_SESSION['cart'] as $product_id => $quantity) {
                        $productStmt = $pdo->prepare("SELECT id, unit_price, quantity, name FROM products WHERE id = ? AND is_active = true");
                        $productStmt->execute([$product_id]);
                        $product = $productStmt->fetch();
                        if (!$product) continue;
                        if ((int)$product['quantity'] < (int)$quantity) {
                            throw new Exception("Insufficient stock for " . ($product['name'] ?? 'selected product') . ".");
                        }
                        $unit_price = floatval($product['unit_price'] ?? 0);
                        $subtotal = $unit_price * (int)$quantity;
                        $total += $subtotal;
                        $items_count += (int)$quantity;
                        $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                        $itemStmt->execute([$order_id, $product_id, $quantity, $unit_price, $subtotal]);
                        $updateStmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                        $updateStmt->execute([$quantity, $product_id]);
                    }
                    if ($items_count <= 0 || $total <= 0) throw new Exception('Unable to process payment for an empty order.');
                    $updateOrder = $pdo->prepare("UPDATE orders SET total_amount = ? WHERE id = ?");
                    $updateOrder->execute([$total, $order_id]);
                    $paymentInit = $paymentModule->initializePaymentRecord(
                        $order_id,
                        'mpesa',
                        [
                            'phone' => $phone,
                            'source' => 'customer_dashboard',
                            'mode' => 'pay_now',
                        ],
                        [
                            'manage_transaction' => false,
                            'status' => 'pending',
                            'amount' => $total,
                            'reference_number' => 'ORD-' . $order_id,
                        ]
                    );
                    if (!$paymentInit['success']) {
                        throw new Exception($paymentInit['message'] ?? 'Failed to initialize payment record.');
                    }
                    $accountReference = 'ORD-' . $order_id;
                    $stkResult = $mpesa->stkPush($phone, $total, $accountReference, "Payment for order #$order_id");
                    if (!$stkResult['success']) {
                        throw new Exception('Failed to initiate M-Pesa payment: ' . ($stkResult['message'] ?? 'Unknown error'));
                    }

                    $paymentSync = $paymentModule->syncPaymentStatus($order_id, 'pending', [
                        'transaction_id' => $stkResult['checkout_request_id'] ?? null,
                        'payment_gateway' => 'mpesa',
                        'payment_method' => 'mpesa',
                        'amount' => $total,
                        'checkout_request_id' => $stkResult['checkout_request_id'] ?? null,
                        'reference_number' => $accountReference,
                        'gateway_response' => [
                            'phone' => $phone,
                            'stk_result' => $stkResult,
                        ],
                    ], [
                        'manage_transaction' => false,
                    ]);

                    if (!$paymentSync['success']) {
                        throw new Exception($paymentSync['message'] ?? 'Failed to synchronize payment status.');
                    }

                    $mpesaStmt = $pdo->prepare("
                        INSERT INTO mpesa_transactions (
                            order_id,
                            checkout_request_id,
                            merchant_request_id,
                            phone_number,
                            amount,
                            result_desc,
                            status
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $mpesaStmt->execute([
                        $order_id,
                        $stkResult['checkout_request_id'] ?? null,
                        $stkResult['merchant_request_id'] ?? null,
                        $mpesa->formatPhoneNumber($phone),
                        $total,
                        $stkResult['customer_message'] ?? $stkResult['message'] ?? 'STK Push sent',
                    ]);

                    $pdo->commit();
                    $_SESSION['cart'] = [];
                    $_SESSION['payment_feedback'] = "M-Pesa prompt sent to {$phone} for Order #{$order_id}.";
                    header('Location: payment_status.php?order_id=' . urlencode((string) $order_id));
                    exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $message = 'Payment failed: ' . $e->getMessage();
                    $message_type = 'error';
                    error_log("Pay now error: " . $e->getMessage());
                }
            }
        }
    }
}

$products = [];
require_once 'db_connect.php';
if ($pdo !== null) {
    try {
        $stmt = $pdo->query("SELECT * FROM products WHERE is_active = true AND quantity > 0 ORDER BY name LIMIT 50");
        $products = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching products: " . $e->getMessage());
    }
}

$user_orders = [];
if ($pdo !== null) {
    try {
        $stmt = $pdo->prepare("SELECT o.*, (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count FROM orders o WHERE o.user_id = ? ORDER BY o.order_date DESC LIMIT 20");
        $stmt->execute([$user_id]);
        $user_orders = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching orders: " . $e->getMessage());
    }
}

$cart_items = [];
$cart_total = 0;
if (!empty($_SESSION['cart']) && $pdo !== null) {
    foreach ($_SESSION['cart'] as $product_id => $quantity) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = true");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            if ($product) {
                $product['cart_quantity'] = $quantity;
                $unit_price = floatval($product['unit_price'] ?? 0);
                $product['subtotal'] = $unit_price * $quantity;
                $cart_items[] = $product;
                $cart_total += $product['subtotal'];
            }
        } catch (PDOException $e) {
            error_log("Error fetching cart product: " . $e->getMessage());
        }
    }
}

$cart_count = array_sum($_SESSION['cart']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LuxeStore â€” My Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,300;0,400;0,600;1,300;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       DESIGN TOKENS
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    :root {
        --ink:        #0e0e12;
        --ink-2:      #16161c;
        --ink-3:      #1e1e28;
        --ink-4:      #28283a;
        --surface:    #222230;
        --surface-2:  #2a2a3c;

        --gold:       #d4a853;
        --gold-l:     #e8c580;
        --gold-pale:  rgba(212,168,83,.12);
        --gold-glow:  rgba(212,168,83,.22);

        --emerald:    #34d399;
        --rose:       #fb7185;
        --sky:        #38bdf8;
        --amber:      #fbbf24;

        --text:       #f0eee8;
        --text-2:     #a09a8e;
        --text-3:     #5e5a52;
        --border:     rgba(255,255,255,.07);
        --border-2:   rgba(255,255,255,.13);

        --success-bg: rgba(52,211,153,.12);
        --danger-bg:  rgba(251,113,133,.12);
        --warning-bg: rgba(251,191,36,.12);
        --info-bg:    rgba(56,189,248,.12);

        /* Compat */
        --bg-primary:   var(--ink);
        --bg-secondary: var(--ink-2);
        --bg-card:      var(--surface);
        --accent:       var(--gold);
        --accent-hover: var(--gold-l);
        --accent-glow:  var(--gold-glow);
        --text-primary: var(--text);
        --text-secondary: var(--text-2);
        --text-muted:   var(--text-3);
        --border-color: var(--border);
        --success:      var(--emerald);
        --danger:       var(--rose);
        --warning:      var(--amber);
        --info:         var(--sky);

        --font-heading: 'Fraunces', serif;
        --font-body:    'Jost', sans-serif;
        --r:  10px;
        --rl: 18px;
        --header-h: 72px;
    }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       RESET & BASE
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
        font-family: var(--font-body);
        background: var(--ink);
        color: var(--text);
        min-height: 100vh;
        line-height: 1.6;
        -webkit-font-smoothing: antialiased;
    }

    /* Ambient gradient background */
    body::before {
        content: '';
        position: fixed; inset: 0;
        background:
            radial-gradient(ellipse 80% 50% at 10% -10%, rgba(212,168,83,.08) 0%, transparent 60%),
            radial-gradient(ellipse 60% 40% at 90% 100%, rgba(52,211,153,.05) 0%, transparent 60%);
        pointer-events: none; z-index: 0;
    }

    /* Subtle noise texture */
    body::after {
        content: '';
        position: fixed; inset: 0;
        background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
        opacity: .025; pointer-events: none; z-index: 0;
    }

    ::-webkit-scrollbar { width: 4px; height: 4px; }
    ::-webkit-scrollbar-track { background: var(--ink-2); }
    ::-webkit-scrollbar-thumb { background: var(--ink-4); border-radius: 99px; }

    a { color: inherit; text-decoration: none; }
    button { font-family: var(--font-body); cursor: pointer; }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       HEADER
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .header {
        position: fixed; top: 0; left: 0; right: 0; z-index: 200;
        height: var(--header-h);
        background: rgba(14,14,18,.82);
        backdrop-filter: blur(24px) saturate(1.5);
        -webkit-backdrop-filter: blur(24px) saturate(1.5);
        border-bottom: 1px solid var(--border);
        transition: box-shadow .3s;
    }
    .header.scrolled { box-shadow: 0 8px 32px rgba(0,0,0,.4); }

    .header-inner {
        max-width: 1440px; margin: 0 auto; height: 100%;
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 32px; gap: 24px;
    }

    /* Logo */
    .logo { display: flex; align-items: center; gap: 10px; }
    .logo-mark {
        width: 40px; height: 40px;
        background: linear-gradient(135deg, var(--gold) 0%, var(--gold-l) 100%);
        border-radius: 10px; display: grid; place-items: center;
        font-size: 18px; color: var(--ink);
        box-shadow: 0 0 24px var(--gold-glow);
        transition: box-shadow .3s;
    }
    .logo:hover .logo-mark { box-shadow: 0 0 36px var(--gold-glow); }
    .logo-name {
        font-family: var(--font-heading); font-size: 1.5rem;
        font-weight: 600; letter-spacing: -.02em; color: var(--text);
    }
    .logo-name em { font-style: normal; color: var(--gold); }

    /* Header actions */
    .header-right { display: flex; align-items: center; gap: 12px; }

    /* Cart button */
    .cart-btn {
        position: relative; display: flex; align-items: center; gap: 8px;
        padding: 0 16px; height: 40px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--r); color: var(--text-2); font-size: .85rem; font-weight: 500;
        transition: all .2s;
    }
    .cart-btn:hover { background: var(--surface-2); border-color: var(--gold); color: var(--gold); }
    .cart-btn i { font-size: 15px; }
    .cart-badge {
        position: absolute; top: -7px; right: -7px;
        min-width: 20px; height: 20px; padding: 0 5px;
        background: var(--gold); color: var(--ink);
        font-size: .65rem; font-weight: 700; border-radius: 99px;
        display: grid; place-items: center;
        border: 2px solid var(--ink);
        animation: popIn .25s cubic-bezier(.34,1.56,.64,1);
    }
    @keyframes popIn { from { transform: scale(0); } to { transform: scale(1); } }

    /* User chip */
    .user-chip {
        display: flex; align-items: center; gap: 10px;
        padding: 5px 14px 5px 5px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 99px; transition: border-color .2s;
    }
    .user-chip:hover { border-color: var(--border-2); }
    .user-avatar {
        width: 32px; height: 32px; border-radius: 50%;
        background: linear-gradient(135deg, var(--gold), var(--gold-l));
        display: grid; place-items: center;
        font-size: .75rem; font-weight: 700; color: var(--ink);
        letter-spacing: .05em;
    }
    .user-info .user-name { font-size: .82rem; font-weight: 600; color: var(--text); line-height: 1.2; }
    .user-info .user-role { font-size: .7rem; color: var(--text-3); }

    /* Logout */
    .logout-btn {
        display: flex; align-items: center; gap: 7px;
        padding: 0 16px; height: 40px;
        background: transparent; border: 1px solid var(--border);
        border-radius: var(--r); color: var(--text-2); font-size: .82rem; font-weight: 500;
        transition: all .2s;
    }
    .logout-btn:hover { background: var(--danger-bg); border-color: var(--rose); color: var(--rose); }
    .logout-btn i { font-size: 13px; }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       MAIN LAYOUT
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .main-content {
        max-width: 1440px; margin: 0 auto;
        padding: calc(var(--header-h) + 32px) 32px 60px;
        position: relative; z-index: 1;
    }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       MESSAGE TOAST
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .message {
        display: flex; align-items: center; gap: 12px;
        padding: 14px 20px; border-radius: var(--r);
        margin-bottom: 24px; font-size: .9rem; font-weight: 500;
        animation: slideDown .3s ease;
        border: 1px solid transparent;
    }
    .message::before { font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 14px; }
    .message.success { background: var(--success-bg); color: var(--emerald); border-color: rgba(52,211,153,.2); }
    .message.success::before { content: '\f058'; }
    .message.error { background: var(--danger-bg); color: var(--rose); border-color: rgba(251,113,133,.2); }
    .message.error::before { content: '\f071'; }
    @keyframes slideDown { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       TAB NAV
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .tabs {
        display: flex; gap: 4px; margin-bottom: 32px;
        border-bottom: 1px solid var(--border); padding-bottom: 0;
        overflow-x: auto;
    }
    .tab-btn {
        display: flex; align-items: center; gap: 8px;
        padding: 12px 20px; background: none; border: none; border-bottom: 2px solid transparent;
        color: var(--text-3); font-size: .85rem; font-weight: 500; font-family: var(--font-body);
        white-space: nowrap; transition: all .2s; margin-bottom: -1px;
        border-radius: var(--r) var(--r) 0 0;
    }
    .tab-btn:hover { color: var(--text-2); background: var(--ink-3); }
    .tab-btn.active { color: var(--gold); border-bottom-color: var(--gold); background: var(--gold-pale); }
    .tab-btn .tab-count {
        min-width: 20px; height: 20px; padding: 0 6px;
        background: var(--ink-4); color: var(--text-2);
        border-radius: 99px; font-size: .68rem; font-weight: 700;
        display: grid; place-items: center; transition: background .2s;
    }
    .tab-btn.active .tab-count { background: var(--gold); color: var(--ink); }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       TAB CONTENT
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .tab-content { display: none; animation: fadeUp .3s ease; }
    .tab-content.active { display: block; }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       SECTION HEADER
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .section-head { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 16px; }
    .section-title {
        font-family: var(--font-heading); font-size: 2rem;
        font-weight: 400; color: var(--text); letter-spacing: -.02em; line-height: 1.1;
    }
    .section-title em { font-style: italic; color: var(--gold); }
    .section-sub { font-size: .82rem; color: var(--text-3); margin-top: 4px; }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       SEARCH
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .search-wrap {
        display: flex; align-items: center; gap: 10px;
        background: var(--ink-3); border: 1px solid var(--border);
        border-radius: var(--r); padding: 0 16px;
        max-width: 420px; height: 44px;
        transition: border-color .2s, box-shadow .2s;
    }
    .search-wrap:focus-within { border-color: var(--gold); box-shadow: 0 0 0 3px var(--gold-pale); }
    .search-wrap i { color: var(--text-3); font-size: 14px; }
    .search-input {
        flex: 1; background: none; border: none; outline: none;
        color: var(--text); font-size: .88rem; font-family: var(--font-body);
    }
    .search-input::placeholder { color: var(--text-3); }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       PRODUCTS GRID
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
        gap: 20px;
    }

    .product-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--rl); overflow: hidden;
        display: flex; flex-direction: column;
        transition: border-color .25s, transform .25s, box-shadow .25s;
        position: relative;
    }
    .product-card:hover {
        border-color: rgba(212,168,83,.4);
        transform: translateY(-5px);
        box-shadow: 0 20px 48px rgba(0,0,0,.4), 0 0 0 1px rgba(212,168,83,.15);
    }

    /* Shimmer on hover */
    .product-card::before {
        content: '';
        position: absolute; top: 0; left: -100%; width: 60%; height: 100%;
        background: linear-gradient(90deg, transparent, rgba(212,168,83,.06), transparent);
        transform: skewX(-20deg);
        transition: left .5s ease;
        pointer-events: none;
    }
    .product-card:hover::before { left: 150%; }

    .product-image {
        height: 180px;
        background: linear-gradient(135deg, var(--ink-3) 0%, var(--ink-4) 100%);
        display: flex; align-items: center; justify-content: center;
        position: relative; overflow: hidden;
    }
    .product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform .3s ease;
    }
    .product-card:hover .product-image img { transform: scale(1.04); }

    /* Category chip on image */
    .product-cat {
        position: absolute; top: 12px; left: 12px;
        padding: 3px 10px; background: rgba(14,14,18,.8); backdrop-filter: blur(8px);
        border: 1px solid var(--border-2); border-radius: 99px;
        font-size: .65rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
        color: var(--text-2);
    }

    .product-info { padding: 20px; flex: 1; display: flex; flex-direction: column; gap: 8px; }

    .product-name { font-weight: 600; font-size: .98rem; color: var(--text); line-height: 1.3; }
    .product-sku { font-size: .75rem; color: var(--text-3); font-family: 'Courier New', monospace; letter-spacing: .04em; }
    .product-description {
        font-size: .82rem;
        line-height: 1.55;
        color: var(--text-2);
        min-height: 2.5em;
    }

    .product-price {
        font-family: var(--font-heading); font-size: 1.55rem;
        font-weight: 300; color: var(--gold); letter-spacing: -.02em; margin: 4px 0;
    }

    .product-stock {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: .78rem; font-weight: 500;
    }
    .product-stock.in { color: var(--emerald); }
    .product-stock.low { color: var(--amber); }
    .product-stock.out { color: var(--rose); }
    .stock-dot {
        width: 6px; height: 6px; border-radius: 50%; background: currentColor;
        animation: pulse 2s infinite;
    }
    .product-stock.in .stock-dot { animation: none; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }

    .add-to-cart-form {
        display: flex; gap: 8px; margin-top: auto; padding-top: 12px;
        border-top: 1px solid var(--border);
    }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       INPUTS
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .qty-input {
        width: 72px; padding: 9px 10px; text-align: center;
        background: var(--ink-3); border: 1px solid var(--border); border-radius: var(--r);
        color: var(--text); font-size: .9rem; font-family: var(--font-body);
        transition: border-color .2s;
    }
    .qty-input:focus { outline: none; border-color: var(--gold); }

    .payment-input {
        width: 100%; padding: 11px 14px;
        background: var(--ink-3); border: 1px solid var(--border); border-radius: var(--r);
        color: var(--text); font-size: .88rem; font-family: var(--font-body);
        transition: border-color .2s, box-shadow .2s;
    }
    .payment-input:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 3px var(--gold-pale); }
    .payment-help { font-size: .76rem; color: var(--text-3); display: flex; align-items: center; gap: 5px; }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       BUTTONS
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .btn {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 9px 18px; border-radius: var(--r); border: none;
        font-family: var(--font-body); font-size: .84rem; font-weight: 600;
        transition: all .2s; white-space: nowrap;
    }
    .btn i { font-size: 13px; }

    .btn-primary {
        background: linear-gradient(135deg, var(--gold), var(--gold-l));
        color: var(--ink); flex: 1;
        box-shadow: 0 4px 16px var(--gold-glow);
    }
    .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 24px var(--gold-glow); filter: brightness(1.08); }

    .btn-success { background: var(--success-bg); color: var(--emerald); border: 1px solid rgba(52,211,153,.2); }
    .btn-success:hover { background: var(--emerald); color: var(--ink); }

    .btn-warning { background: var(--warning-bg); color: var(--amber); border: 1px solid rgba(251,191,36,.2); }
    .btn-warning:hover { background: var(--amber); color: var(--ink); }

    .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text-2); }
    .btn-outline:hover { border-color: var(--rose); color: var(--rose); background: var(--danger-bg); }

    .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-2); }
    .btn-ghost:hover { background: var(--ink-3); color: var(--text); }

    .btn-full { width: 100%; justify-content: center; padding: 13px; font-size: .9rem; }
    .btn-icon { padding: 8px; width: 34px; height: 34px; border-radius: 8px; justify-content: center; }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       CART
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .cart-layout { display: grid; grid-template-columns: 1fr 380px; gap: 24px; align-items: start; }
    @media (max-width: 1000px) { .cart-layout { grid-template-columns: 1fr; } }

    .cart-panel, .checkout-panel {
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--rl); overflow: hidden;
    }

    .panel-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 18px 24px; border-bottom: 1px solid var(--border);
        background: var(--ink-3);
    }
    .panel-title { font-size: .9rem; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 8px; }
    .panel-title i { color: var(--gold); }

    .cart-items { }
    .cart-item {
        display: flex; align-items: center; gap: 16px;
        padding: 18px 24px; border-bottom: 1px solid var(--border);
        transition: background .2s;
    }
    .cart-item:last-child { border-bottom: none; }
    .cart-item:hover { background: var(--ink-3); }

    .cart-item-img {
        width: 64px; height: 64px; border-radius: 10px;
        background: var(--ink-3); border: 1px solid var(--border);
        display: grid; place-items: center; font-size: 1.8rem;
        color: var(--text-3); flex-shrink: 0;
        overflow: hidden;
    }
    .cart-item-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .cart-item-info { flex: 1; min-width: 0; }
    .cart-item-name { font-weight: 600; font-size: .9rem; color: var(--text); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cart-item-unit { font-size: .78rem; color: var(--text-3); }

    .cart-item-qty {
        display: flex; align-items: center; justify-content: center;
        min-width: 48px; height: 28px; background: var(--ink-4);
        border-radius: 99px; font-size: .78rem; font-weight: 600; color: var(--text-2);
    }

    .cart-item-sub {
        font-family: var(--font-heading); font-size: 1.05rem;
        font-weight: 400; color: var(--gold); min-width: 80px; text-align: right;
    }

    .cart-empty-state {
        padding: 60px 32px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 14px;
    }
    .cart-empty-icon { font-size: 3rem; color: var(--ink-4); }
    .cart-empty-state p { color: var(--text-3); font-size: .9rem; }

    /* Checkout panel */
    .checkout-panel { position: sticky; top: calc(var(--header-h) + 20px); }

    .order-summary { padding: 20px 24px; }
    .summary-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: .86rem; color: var(--text-2); }
    .summary-row:last-of-type { border-bottom: none; }
    .summary-total {
        display: flex; justify-content: space-between; align-items: center;
        padding: 16px 0 20px; border-top: 1px solid var(--border); margin-top: 4px;
    }
    .summary-total-label { font-size: .82rem; font-weight: 600; text-transform: uppercase; letter-spacing: .1em; color: var(--text-3); }
    .summary-total-value { font-family: var(--font-heading); font-size: 1.8rem; font-weight: 300; color: var(--gold); }

    .checkout-actions { padding: 0 24px 20px; display: flex; flex-direction: column; gap: 10px; }

    .divider { display: flex; align-items: center; gap: 12px; margin: 4px 0; }
    .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
    .divider span { font-size: .7rem; color: var(--text-3); text-transform: uppercase; letter-spacing: .12em; white-space: nowrap; }

    .mpesa-form { display: flex; flex-direction: column; gap: 8px; }
    .mpesa-label {
        display: flex; align-items: center; gap: 8px;
        font-size: .78rem; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .08em;
    }
    .mpesa-label i { color: #00c853; font-size: 14px; }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       ORDERS
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .orders-list { display: flex; flex-direction: column; gap: 14px; }

    .order-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--rl); overflow: hidden;
        transition: border-color .2s, transform .2s;
    }
    .order-card:hover { border-color: var(--border-2); transform: translateY(-2px); }

    .order-header {
        display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
        padding: 16px 24px; background: var(--ink-3); border-bottom: 1px solid var(--border);
    }

    .order-id {
        font-size: .88rem; font-weight: 700; color: var(--text);
        display: flex; align-items: center; gap: 6px;
    }
    .order-id i { color: var(--gold); font-size: 13px; }
    .order-date { font-size: .75rem; color: var(--text-3); margin-top: 2px; }

    .order-status {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 12px; border-radius: 99px;
        font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    }
    .order-status::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
    .order-status.pending    { background: var(--warning-bg); color: var(--amber); }
    .order-status.processing { background: var(--info-bg); color: var(--sky); }
    .order-status.shipped    { background: var(--success-bg); color: var(--emerald); }
    .order-status.delivered  { background: var(--success-bg); color: var(--emerald); }
    .order-status.cancelled  { background: var(--danger-bg); color: var(--rose); }

    .order-body {
        display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
        padding: 14px 24px;
    }
    .order-meta { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
    .order-meta-item { display: flex; align-items: center; gap: 6px; font-size: .8rem; color: var(--text-3); }
    .order-meta-item i { color: var(--text-3); font-size: 12px; }

    .order-amount {
        font-family: var(--font-heading); font-size: 1.35rem;
        font-weight: 300; color: var(--gold); letter-spacing: -.01em;
    }

    /* Payment status chip */
    .pay-status {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 2px 10px; border-radius: 99px;
        font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: .07em;
    }
    .pay-status.paid     { background: var(--success-bg); color: var(--emerald); }
    .pay-status.pending  { background: var(--warning-bg); color: var(--amber); }
    .pay-status.failed   { background: var(--danger-bg); color: var(--rose); }

    /* Empty state */
    .empty-state {
        grid-column: 1 / -1; padding: 80px 32px; text-align: center;
        display: flex; flex-direction: column; align-items: center; gap: 14px;
    }
    .empty-icon { font-size: 3.5rem; color: var(--ink-4); }
    .empty-state p { color: var(--text-3); font-size: .9rem; max-width: 320px; line-height: 1.6; }
    .empty-state h3 { font-family: var(--font-heading); font-size: 1.4rem; font-weight: 300; color: var(--text-2); }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       RESPONSIVE
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    @media (max-width: 768px) {
        .header-inner { padding: 0 16px; }
        .main-content { padding: calc(var(--header-h) + 20px) 16px 40px; }
        .cart-item { flex-wrap: wrap; gap: 10px; }
        .cart-item-sub { min-width: unset; }
        .user-info { display: none; }
        .logo-name { font-size: 1.3rem; }
    }
    @media (max-width: 480px) {
        .products-grid { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>

    <!-- HEADER -->
    <header class="header" id="hdr">
        <div class="header-inner">
            <a href="customer_dashboard.php" class="logo">
                <div class="logo-mark"><i class="fas fa-boxes-stacked"></i></div>
                <span class="logo-name">Luxe<em>Store</em></span>
            </a>

            <div class="header-right">
                <button class="cart-btn" onclick="showTab('cart', event)">
                    <i class="fas fa-shopping-bag"></i>
                    <span>Cart</span>
                    <?php if ($cart_count > 0): ?>
                        <span class="cart-badge"><?= $cart_count ?></span>
                    <?php endif; ?>
                </button>

                <div class="user-chip">
                    <div class="user-avatar"><?= strtoupper(substr($user_name, 0, 2)) ?></div>
                    <div class="user-info">
                        <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
                        <div class="user-role">Customer</div>
                    </div>
                </div>

                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-arrow-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <main class="main-content">

        <?php if ($message): ?>
        <div class="message <?= $message_type ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- TAB NAV -->
        <div class="tabs">
            <button class="tab-btn active" onclick="showTab('products', event)">
                <i class="fas fa-sparkles"></i>
                Browse Products
            </button>
            <button class="tab-btn" onclick="showTab('cart', event)">
                <i class="fas fa-shopping-bag"></i>
                Cart
                <span class="tab-count"><?= $cart_count ?></span>
            </button>
            <button class="tab-btn" onclick="showTab('orders', event)">
                <i class="fas fa-receipt"></i>
                My Orders
                <span class="tab-count"><?= count($user_orders) ?></span>
            </button>
        </div>

        <!-- â”€â”€â”€ PRODUCTS TAB â”€â”€â”€ -->
        <div id="products" class="tab-content active">
            <div class="section-head">
                <div>
                    <h2 class="section-title">Browse <em>Products</em></h2>
                    <p class="section-sub"><?= count($products) ?> products available</p>
                </div>
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" class="search-input" placeholder="Search productsâ€¦" id="productSearch" oninput="filterProducts()">
                </div>
            </div>

            <div class="products-grid">
                <?php foreach ($products as $product): ?>
                    <?php $unit_price = floatval($product['unit_price'] ?? 0);
                    $productImage = resolveProductImagePath($product['image_path'] ?? null);
                    $productDescription = trim((string)($product['description'] ?? ''));
                    $productDescription = $productDescription !== '' ? (strlen($productDescription) > 117 ? substr($productDescription, 0, 117) . '...' : $productDescription) : 'No description available for this product yet.';
                    $stock_class = 'in'; $stock_text = $product['quantity'] . ' in stock';
                    if ($product['quantity'] <= 5) { $stock_class = 'low'; $stock_text = 'Only ' . $product['quantity'] . ' left'; }
                    if ($product['quantity'] == 0) { $stock_class = 'out'; $stock_text = 'Out of stock'; }
                    ?>
                    <div class="product-card" data-name="<?= strtolower(htmlspecialchars($product['name'])) ?>">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($productImage) ?>" alt="<?= htmlspecialchars($product['name']) ?>" loading="lazy">
                            <span class="product-cat">Product</span>
                        </div>
                        <div class="product-info">
                            <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                            <div class="product-sku">SKU: <?= htmlspecialchars($product['sku'] ?? 'N/A') ?></div>
                            <div class="product-description"><?= htmlspecialchars($productDescription) ?></div>
                            <div class="product-price">$<?= number_format($unit_price, 2) ?></div>
                            <div class="product-stock <?= $stock_class ?>">
                                <span class="stock-dot"></span>
                                <?= $stock_text ?>
                            </div>
                            <?php if ($product['quantity'] > 0): ?>
                            <form method="POST" class="add-to-cart-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                <input type="number" name="quantity" value="1" min="1" max="<?= $product['quantity'] ?>" class="qty-input">
                                <button type="submit" name="add_to_cart" class="btn btn-primary">
                                    <i class="fas fa-cart-plus"></i> Add to Cart
                                </button>
                            </form>
                            <?php else: ?>
                            <div class="add-to-cart-form">
                                <button class="btn btn-outline btn-full" disabled style="opacity:.5;cursor:not-allowed;">
                                    <i class="fas fa-ban"></i> Out of Stock
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($products)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-box-open"></i></div>
                        <h3>Nothing here yet</h3>
                        <p>No products are available at the moment. Check back soon.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- â”€â”€â”€ CART TAB â”€â”€â”€ -->
        <div id="cart" class="tab-content">
            <div class="section-head">
                <div>
                    <h2 class="section-title">Shopping <em>Cart</em></h2>
                    <p class="section-sub"><?= count($cart_items) ?> item<?= count($cart_items) != 1 ? 's' : '' ?> in your cart</p>
                </div>
            </div>

            <?php if (!empty($cart_items)): ?>
            <div class="cart-layout">
                <!-- Items -->
                <div class="cart-panel">
                    <div class="panel-header">
                        <span class="panel-title"><i class="fas fa-shopping-bag"></i> Cart Items</span>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" name="clear_cart" class="btn btn-outline btn-icon" title="Clear cart">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </form>
                    </div>
                    <div class="cart-items">
                        <?php foreach ($cart_items as $item): ?>
                            <?php $item_price = floatval($item['unit_price'] ?? 0);
                            $cartItemImage = resolveProductImagePath($item['image_path'] ?? null); ?>
                            <div class="cart-item">
                                <div class="cart-item-img">
                                    <img src="<?= htmlspecialchars($cartItemImage) ?>" alt="<?= htmlspecialchars($item['name']) ?>" loading="lazy">
                                </div>
                                <div class="cart-item-info">
                                    <div class="cart-item-name"><?= htmlspecialchars($item['name']) ?></div>
                                    <div class="cart-item-unit">$<?= number_format($item_price, 2) ?> each</div>
                                </div>
                                <div class="cart-item-qty">Ã—<?= $item['cart_quantity'] ?></div>
                                <div class="cart-item-sub">$<?= number_format($item['subtotal'] ?? 0, 2) ?></div>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                                    <button type="submit" name="remove_from_cart" class="btn btn-icon btn-outline" title="Remove">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Checkout panel -->
                <div class="checkout-panel">
                    <div class="panel-header">
                        <span class="panel-title"><i class="fas fa-receipt"></i> Order Summary</span>
                    </div>
                    <div class="order-summary">
                        <?php foreach ($cart_items as $item): ?>
                        <div class="summary-row">
                            <span><?= htmlspecialchars($item['name']) ?> Ã—<?= $item['cart_quantity'] ?></span>
                            <span>$<?= number_format($item['subtotal'] ?? 0, 2) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <div class="summary-total">
                            <span class="summary-total-label">Total</span>
                            <span class="summary-total-value">$<?= number_format($cart_total, 2) ?></span>
                        </div>
                    </div>

                    <div class="checkout-actions">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" name="checkout" class="btn btn-success btn-full">
                                <i class="fas fa-check-circle"></i> Place Order
                            </button>
                        </form>

                        <div class="divider"><span>or pay instantly</span></div>

                        <form method="POST" class="mpesa-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="mpesa-label">
                                <i class="fas fa-mobile-screen-button"></i>
                                M-Pesa Payment
                            </div>
                            <input type="tel" name="phone" class="payment-input"
                                placeholder="07XXXXXXXX or 254XXXXXXXXX"
                                required pattern="^(07|01)[0-9]{8}$|^254[0-9]{9}$">
                            <p class="payment-help">
                                <i class="fas fa-shield-check" style="color:var(--emerald)"></i>
                                Enter your M-Pesa registered number
                            </p>
                            <button type="submit" name="pay_now" class="btn btn-warning btn-full">
                                <i class="fas fa-bolt"></i> Pay $<?= number_format($cart_total, 2) ?> Now
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="cart-panel">
                <div class="cart-empty-state">
                    <div class="cart-empty-icon"><i class="fas fa-shopping-bag"></i></div>
                    <h3 style="font-family:var(--font-heading);font-weight:300;color:var(--text-2)">Your cart is empty</h3>
                    <p style="color:var(--text-3);font-size:.88rem">Add some products to get started.</p>
                    <button class="btn btn-primary" onclick="showTab('products', event)" style="margin-top:8px">
                        <i class="fas fa-sparkles"></i> Browse Products
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- â”€â”€â”€ ORDERS TAB â”€â”€â”€ -->
        <div id="orders" class="tab-content">
            <div class="section-head">
                <div>
                    <h2 class="section-title">My <em>Orders</em></h2>
                    <p class="section-sub"><?= count($user_orders) ?> order<?= count($user_orders) != 1 ? 's' : '' ?> placed</p>
                </div>
            </div>

            <?php if (!empty($user_orders)): ?>
            <div class="orders-list">
                <?php foreach ($user_orders as $order): ?>
                <div class="order-card">
                    <div class="order-header">
                        <div class="order-id-block">
                            <div class="order-id">
                                <i class="fas fa-hashtag"></i>
                                Order <?= $order['id'] ?>
                            </div>
                            <div class="order-date"><?= date('d M Y, g:i A', strtotime($order['order_date'])) ?></div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span class="pay-status <?= strtolower($order['payment_status'] ?? 'pending') ?>">
                                <?= ucfirst($order['payment_status'] ?? 'Pending') ?>
                            </span>
                            <span class="order-status <?= strtolower($order['status']) ?>">
                                <?= ucfirst($order['status']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="order-body">
                        <div class="order-meta">
                            <div class="order-meta-item">
                                <i class="fas fa-box"></i>
                                <?= $order['item_count'] ?> item<?= $order['item_count'] != 1 ? 's' : '' ?>
                            </div>
                        </div>
                        <div class="order-amount">$<?= number_format($order['total_amount'] ?? 0, 2) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-receipt"></i></div>
                <h3>No orders yet</h3>
                <p>You haven't placed any orders. Start browsing our products and make your first purchase!</p>
                <button class="btn btn-primary" onclick="showTab('products', event)" style="margin-top:8px">
                    <i class="fas fa-sparkles"></i> Start Shopping
                </button>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <script>
        // â”€â”€ Tab switching
        function showTab(tabId, event) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');

            // Find matching tab button
            const btns = document.querySelectorAll('.tab-btn');
            btns.forEach(btn => {
                if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(`'${tabId}'`)) {
                    btn.classList.add('active');
                }
            });
        }

        // â”€â”€ Product search
        function filterProducts() {
            const q = document.getElementById('productSearch').value.toLowerCase();
            document.querySelectorAll('.product-card').forEach(card => {
                card.style.display = card.getAttribute('data-name').includes(q) ? '' : 'none';
            });
        }

        // â”€â”€ Header shadow on scroll
        window.addEventListener('scroll', () => {
            document.getElementById('hdr').classList.toggle('scrolled', scrollY > 10);
        }, { passive: true });
    </script>
</body>
</html>

