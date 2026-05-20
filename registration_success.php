<?php
// registration_success.php
require_once __DIR__ . '/includes/session.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="card mt-5 mx-auto" style="max-width: 520px;">
            <div class="card-body text-center">
                <h4 class="mb-3">Registration Successful</h4>
                <p class="mb-3">Your account has been created. Please check your email and verify your address before logging in.</p>
                <a href="user_login.php" class="btn btn-primary">Go to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
