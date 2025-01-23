<?php
require 'global_variables.php';
require 'db.php'; // Include the database connection
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset(/*$_POST['email'],*/ $_POST['regusername'], $_POST['regpassword']/*, $_POST['verificationCode']*/)) {
    exit;//临时停用接口
    
    //$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $username = filter_input(INPUT_POST, 'regusername', FILTER_SANITIZE_STRING);
    $email=$username;
    $password = $_POST['regpassword']; // Password will be hashed, no need to sanitize
    //$verificationCode = filter_input(INPUT_POST, 'verificationCode', FILTER_SANITIZE_NUMBER_INT);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '无效的邮箱地址。']);
        exit;
    }
/*
    if ($verificationCode != $_SESSION['verification_code']) {
        echo json_encode(['success' => false, 'message' => '验证码错误。']);
        exit;
    }*/

    // Check if username exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->bindParam(':username', $username);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '用户名已被注册。']);
        exit;
    }
/*
    // Check if email exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->bindParam(':email', $email);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '邮箱已被注册。']);
        exit;
    }*/

    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Insert new user
    $stmt = $pdo->prepare("INSERT INTO users (username, password, email) VALUES (:username, :password, :email)");
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':password', $hashedPassword);
    $stmt->bindParam(':email', $email);

    try {
        $stmt->execute();
        http_response_code(200);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}
?>
