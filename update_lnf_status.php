<?php
// update_lnf_status.php

// Enable detailed error reporting (use only in development)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once 'db.php'; // Database connection
require_once 'global_variables.php';

// Set response headers
header('Content-Type: application/json; charset=UTF-8');

// Allow cross-origin requests (adjust as needed)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // Check if the request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['message' => 'Method Not Allowed']);
        exit;
    }

    // Define required fields
    $requiredFields = ['id', 'password', 'action'];

    // Initialize an array to store sanitized data
    $cleanData = [];

    // Validate and sanitize input data
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            http_response_code(422); // Unprocessable Entity
            echo json_encode(['message' => "Missing required field: $field."]);
            exit;
        }
        // Sanitize data
        $cleanData[$field] = trim($_POST[$field]);
    }

    // Validate the 'action' field
    $action = $cleanData['action'];
    $validActions = ['found', 'not_found', 'hide'];

    if (!in_array($action, $validActions)) {
        http_response_code(422);
        echo json_encode(['message' => 'Invalid action.']);
        exit;
    }

    // Set the 'is_found' value based on the action
    switch ($action) {
        case 'found':
            $isFound = 1;
            $statusText = 'Found';
            break;
        case 'not_found':
            $isFound = 0;
            $statusText = 'Not Found';
            break;
        case 'hide':
            $isFound = 3;
            $statusText = 'Hidden';
            break;
        default:
            // This case should never occur due to earlier validation
            http_response_code(422);
            echo json_encode(['message' => 'Invalid action.']);
            exit;
    }

    // Retrieve the stored hashed password from the database
    $sql = "SELECT password, student_name, email, created_at FROM lost_and_found WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $cleanData['id'], PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && password_verify($cleanData['password'], $result['password'])) {
        // Password verification successful, update the status
        $updateSql = "UPDATE lost_and_found SET is_found = :is_found, last_updated = NOW() WHERE id = :id";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->bindParam(':is_found', $isFound, PDO::PARAM_INT);
        $updateStmt->bindParam(':id', $cleanData['id'], PDO::PARAM_INT);
        $updateStmt->execute();

        // Log the update action
        $updateId = $pdo->lastInsertId();
        lf_logger($pdo, $cleanData['id'], 'submitter updated to'.$isFound, $updateId);

        // Prepare email details
        $studentName = htmlspecialchars($result['student_name'], ENT_QUOTES, 'UTF-8');
        $recipientEmail = $result['email'];
        $createdAt = htmlspecialchars($result['created_at'], ENT_QUOTES, 'UTF-8');


        // Send status update email
        try {
            $mail = initializeMailer();
            $mail->addAddress($recipientEmail);
            $mail->Subject = 'Status Update for Your Lost and Found Request';
            $mail->isHTML(true);
            $mail->Body = '
                <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
                    <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
                        <h2 style="color: #2196F3; text-align: center;">Status Update Notification</h2>
                        <p style="font-size: 16px; line-height: 1.5;">
                            Dear <strong>' . $studentName . '</strong>,<br><br>
                            The status of your lost and found request submitted on <strong>' . $createdAt . '</strong> has been updated to <strong>' . $statusText . '</strong>.
                        </p>
                        <p style="font-size: 16px; line-height: 1.5;">
                            Below are the details of your request:
                        </p>
                        <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                            <tr>
                                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Request ID:</strong></td>
                                <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($cleanData['id'], ENT_QUOTES, 'UTF-8') . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Status:</strong></td>
                                <td style="padding: 10px; border: 1px solid #ddd;">' . $statusText . '</td>
                            </tr>
                        </table>
                        <p style="font-size: 14px; color: #888; margin-top: 20px;">
                            You can click <a href="' . $request_url . $cleanData['id'] . '">this link</a> to view more information about your request. If you have any questions, please contact us.
                        </p>
                        <div style="text-align: center; margin-top: 30px;">
                            <a href="mailto:' . htmlspecialchars($email_account, ENT_QUOTES, 'UTF-8') . '" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
                        </div>
                    </div>
                </div>';

            $mail->send();
        } catch (Exception $e) {
            // If email sending fails, log the error but do not interrupt the main process
            logException($e);
            // Optionally, inform the user that the email couldn't be sent
            echo json_encode(['success' => true, 'message' => 'Status updated successfully, but failed to send email notification.']);
            exit;
        }

        // Return success response
        http_response_code(200);
        echo json_encode(['success' => 'Status updated successfully.']);
    } else {
        // Password verification failed
        http_response_code(422);
        echo json_encode(['message' => 'Invalid password or ID.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Failed to update status.']);
    // Log the error for debugging purposes
    error_log("Database Error in update_lf_status.php: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Error: ' . $e->getMessage()]);
    // Log the general error
    error_log("General Error in update_lf_status.php: " . $e->getMessage());
}
?>