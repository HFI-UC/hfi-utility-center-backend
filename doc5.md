## Documentation for Code Files

### submit_lnf.php

**Description**: API endpoint for submitting lost and found item requests.

**Functionality**:
- **Item Submission**: Handles the submission of lost and found item requests.
- **Input Validation**: Validates the submitted data.
- **Error Handling**: Includes error handling for:
    - Missing or invalid request parameters.
    - Database query errors.

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations.

**Input**:
- POST request with lost and found item data:
    - `token` (required): Admin authentication token.
    - `item` (required): Lost and found item description.
    - `location` (required): Item location.
    - `campus` (required): Item campus.
    - `contact` (required): Contact information for the item owner.

**Output**:
- JSON response indicating success or failure of the item submission.

---

### submit_classroom.php

**Description**: API endpoint for administrators to add new classrooms to the `classrooms` table, making them available for booking.
[... Previous content remains the same until validateAdvertData method]

---

### submit_clue.html

**Description**: HTML form for testing the clue submission API endpoint (`submit_clue.php`).
[... Previous content remains the same until validateAdvertData method]

---

### submit_repair.php

**Description**: API endpoint for submitting repair requests to the database.

**Functionality**:
- **Repair Request Submission**: Handles the submission of repair requests to the database.
- **Input Validation**: Validates the submitted data.
- **Error Handling**: Includes error handling for:
    - Missing or invalid request parameters.
    - Database query errors.

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations.

**Input**:
- POST request with repair request data:
    - `token` (required): Admin authentication token.
    - `subject` (required): Repair request subject.
    - `detail` (required): Repair request details.
    - `location` (required): Repair request location.
    - `campus` (required): Repair request campus.
    - `filePath` (required): Repair request file path.

**Output**:
- JSON response indicating success or failure of the repair request submission.

---

### update_lnf_status.php

**Description**: API endpoint for administrators to update the status of a Lost and Found item.

**Functionality**:
- **Status Update**: Allows administrators to update the status of a lost and found item, such as marking it as claimed or closed.
- **Admin Authentication**: Requires admin authentication via a token to ensure only authorized users can modify item statuses.
- **Input Validation**: Validates required parameters (`token`, `id`, `status`) to ensure all necessary information is provided for the update.
- **Database Update**: Updates the `status` field of a specific lost and found item in the `lost_and_found` table.
- **Logging**: Logs the status update action, recording who performed the action and when.
- **JSON Response**: Returns a JSON response to indicate the success or failure of the status update.

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations, including encryption key.
- `admin_emails.php`: For admin authentication.

**Input**:
- POST request with parameters:
    - `token` (required): Admin authentication token (encrypted admin email).
    - `id` (required): ID of the lost and found item to update.
    - `status` (required): New status code for the lost and found item.

**Output**:
- JSON response indicating success or failure of the status update.
    - `success: true`: Status updated successfully.
    - `success: false`: Status update failed, with an error message.

---

### report_error.php

**Description**: API endpoint for reporting client-side errors to the server, logging error details into a database and sending email notifications.

**Functionality**:
- **Error Reporting**: Allows users to report errors to the server, including details about the error and their contact information.
- **Data Validation**: Validates and sanitizes user inputs to prevent common web vulnerabilities and ensure data integrity.
- **Database Insertion**: Inserts the error report data into the `error_reports` table, including error details, timestamp, and user contact information.
- **Email Notification**: Sends an email notification to the administrator with the error report details.
- **Error Handling**: Implements error handling for:
    - Invalid request methods (only POST allowed).
    - Missing error details (returns 400 Bad Request).
    - Database operation errors (PDOException).
    - Email sending issues.

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations.
- `global_error_handler.php`: For global error handling functions.
- PHPMailer library for sending emails.

**Input**:
- POST request with form data containing:
    - `error_details` (required): Detailed description of the error.
    - `contact_email` (required): Email address for contacting the user.

**Output**:
- JSON response indicating success or failure of the error reporting process. On success, includes a success message. On failure, includes an error message detailing the reason for failure.

---

### resumeDisabledClassroom.php

**Description**: API endpoint to disable a classroom by setting its `unavailable` status to 1 in the database. It is misnamed and should be called `pauseClassroom.php`.

**Functionality**:
- **Classroom Pausing**: Updates the `unavailable` column in the `classrooms` table to 1 for a specific classroom ID, effectively disabling the classroom for bookings.
- **Admin Authentication**: Authenticates admin users using a token sent via POST to ensure only administrators can perform this action.
- **Input Sanitization**: Sanitizes the classroom ID input to prevent XSS and SQL injection vulnerabilities.
- **Error Handling**: Includes error handling for:
    - Missing authentication token (returns 401 Unauthorized).
    - Invalid token (returns 401 Unauthorized).
    - Unauthorized access (non-admin user, returns 401 Unauthorized).
    - Missing classroom ID (returns 400 Bad Request).
    - Database errors during update operation (returns 500 Internal Server Error).
- **JSON Response**: Returns JSON response indicating success or failure of the classroom pausing operation.

**Requires**:
- `global_variables.php`: For global configurations, including encryption key.
- `db.php`: For database connection.
- `admin_emails.php`: For admin authentication.

**Input**:
- POST request with parameters:
    - `token` (required): Admin authentication token.
    - `id` (required): ID of the classroom to pause.

**Output**:
- JSON response indicating success or failure of the classroom pausing.

IMPORTANT: For any future changes to this file, use the final_file_content shown above as your reference. This content reflects the current state of the file, including any auto-formatting (e.g., if you used single quotes but the formatter converted them to double quotes). Always base your SEARCH/REPLACE operations on this final version to ensure accuracy.

<environment_details>
# VSCode Visible Files
doc5.md

# VSCode Open Tabs
draft.md
CustomPDO.php
CustomPDOStatement.php
db.php
login.php
reg.php
submit_repair.php
get_repair.php
process_repair.php
fetch_lnf.php
submit_lnf.php
update_lnf_status.php
submit_clue.php
doc2.md
doc3.md
doc4.md
doc5.md
doc.md

# Current Time
2025/2/27 下午9:27:31 (Asia/Shanghai, UTC+8:00)

# Current Mode
ACT MODE
</environment_details>
