## Documentation for Code Files

### global_variables.php

**Description**: Configuration file containing global variables, settings, and function definitions used across the PHP application.

**Functionality**:
- **Global Variable Definitions**: Defines global variables for:
    - Security keys (`$base64Key`).
    - SMTP email settings (`$email_server`, `$email_account`, `$email_passwd`, `$domain_emaddr`).
    - Classroom booking restrictions (`$restrictedRooms`, `$cleaningStartTime`, `$cleaningEndTime`).
    - Tencent Cloud COS settings (`$cos_bucket`, `$cos_region`, `$cos_secret_id`, `$cos_secret_key`).
    - URL configurations (`$base_url`, `$clue_url`, `$request_url`).
    - Cache expiration times (`$authItemsCacheTime`).
    - Pagination limits (`$maxPage`, `$maxLimit`).
    - Cache file paths (`$cacheFile`, `$privCacheFile`).
- **Function Definitions**: Includes definitions for reusable functions:
    - `initializeMailer()`: Initializes and configures PHPMailer for sending emails.
    - `opensslDecrypt()`: Decrypts data using OpenSSL with AES-256-CBC encryption.
    - `convertRoom()`: Converts classroom IDs to human-readable names.
    - `operatorLogger()`: Logs operator actions related to classroom bookings.
    - `lf_logger()`: Logs actions related to lost and found items.
    - `snakeToCamel()`: Converts snake_case strings to camelCase.
    - `convertKeysToCamelCase()`: Recursively converts array keys from snake_case to camelCase.
    - `convertCampus()`: Converts campus codes to human-readable campus names.
    - `handleRequest()`: Handles and validates HTTP requests, checking request methods and preventing execution for specific test strings.
- **Dependency Inclusion**: Includes necessary files:
    - `db.php`: For database connection.
    - `global_error_handler.php`: For global error handling.
    - PHPMailer library files (`src/Exception.php`, `src/PHPMailer.php`, `src/SMTP.php`).

**Requires**:
- `db.php`: For database connection.
- `global_error_handler.php`: For global error handling functions.
- PHPMailer library files.

**Variables**:
- Many global configuration variables as detailed in "Functionality".

**Functions**:
- Many global utility functions as detailed in "Functionality".

**Output**:
- Does not directly produce output, but defines global variables and functions to be used by other PHP scripts.

---

### inquiry_repair.php

**Description**: API endpoint for users to inquire about repair requests, either listing all requests (admin) or requests associated with a specific email.

**Functionality**:
- **Data Retrieval**: Fetches repair requests from the `repair_requests` table.
    - For general listing, retrieves `id`, `subject`, `detail`, `location`, `campus`, `filePath`, `addTime`, and `status` for all requests, ordered by `addTime` descending.
    - For email-specific inquiries, retrieves all columns for requests matching the provided email, ordered by `addTime` descending.
- **Filtering by Email**: Allows users to filter repair requests by providing an email address, retrieving only requests associated with that email.
- **Admin vs. User Access**: 
    - The initial part of the script (before the horizontal rule) seems intended to list all repair requests, possibly for admin use (though admin authentication is missing in this section of the code).
    - The latter part of the script (after the horizontal rule) focuses on retrieving requests for a specific email, likely for user inquiries.
- **Input Validation**: Validates email format if provided, ensuring it's a valid email address.
- **Error Handling**: Includes error handling for:
    - Method Not Allowed (only GET and POST requests are allowed).
    - Missing email address (for email-specific inquiries).
    - Invalid email format.
    - Database query errors (PDOException).
- **JSON Response**: Returns repair request data in JSON format, including:
    - `success`: Boolean indicating API call status (only for general listing, always true).
    - `data`: Array of repair request records if successful.
    - `error`: Error message if the request fails.
    - `message`: Message indicating no repair requests found for the provided email (for email-specific inquiries).

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations and potentially for global error handling functions (though not explicitly used in error responses).

**Input**:
- GET or POST requests.
    - For general listing (potentially admin-only): No parameters needed, or a `token` parameter (not implemented in the provided code).
    - For user inquiry by email:
        - `email` (required): Email address to filter repair requests. Can be provided as a GET parameter or in the JSON body of a POST request.

**Output**:
- JSON response containing repair requests data or an error message.

---

### inquiry.php

**Description**: API endpoint for general inquiry of classroom booking requests, allowing filtering by various parameters.

**Functionality**:
- **Data Retrieval**: Fetches booking requests from the `requests` table, allowing for flexible filtering.
- **Filtering**: Supports filtering of booking requests based on:
    - `email`: User's email address.
    - `room`: Classroom number.
    - `sid`: Student ID.
    - `query`: Free text search across multiple fields (ID, room, email, auth, time, name, reason).
    - `time`: Timestamp to find bookings within a 3-hour window around the provided time.
- **Combined Search**: Allows combining multiple filters to narrow down search results.
- **No Authentication**: Does not require any authentication, making it publicly accessible for inquiry purposes.
- **Response Formatting**: Returns search results in JSON format, including:
    - `success`: Boolean indicating API call status.
    - `data`: Array of booking request records matching the criteria.
    - `message`: Error message if no parameters are provided or in case of internal server error.
- **Error Handling**: Includes error handling for:
    - No parameters provided (returns 400 Bad Request).
    - Internal server errors during database query (returns 500 Internal Server Error).

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations.

**Input**:
- POST or GET requests with optional parameters:
    - `email`: Email address for filtering.
    - `room`: Room number for filtering.
    - `sid`: Student ID for filtering.
    - `query`: Free text query string for searching across multiple fields.
    - `time`: Timestamp for time-based filtering (bookings within +/- 3 hours).

**Output**:
- JSON response containing booking requests data or an error message.

---

### keygen.php

**Description**: API endpoint for generating temporary keys and credentials for Tencent Cloud Object Storage (COS) uploads.

**Functionality**:
- **Temporary Key Generation**: Generates temporary security credentials for COS using Tencent Cloud STS (Security Token Service) SDK.
- **Pre-signed URL Creation**: Creates pre-signed URLs that allow clients to upload files directly to COS without requiring permanent API keys.
- **Policy Configuration**: Configures policies for the temporary keys, including:
    - File extension whitelist (`extWhiteList`).
    - Content type restrictions (`limitContentType`).
    - Content length limits (`limitContentLength`).
    - Allowed COS bucket and region, defined in `global_variables.php`.
- **Input Validation**: Validates request method (must be POST) and presence of `file-name` parameter.
- **Response Formatting**: Returns temporary credentials and COS configuration in JSON format, including:
    - `TmpSecretId`, `TmpSecretKey`, `SessionToken`: Temporary security credentials.
    - `StartTime`, `ExpiredTime`: Validity period of the credentials.
    - `Bucket`, `Region`, `Key`: COS bucket, region, and object key for upload.
- **Error Handling**: Returns JSON error response with 400 status code for invalid requests (non-POST or missing `file-name`).

**Requires**:
- `src/Sts.php`, `src/Scope.php`: Custom classes from Tencent COS STS SDK.
- `global_variables.php`: For global configurations, including COS credentials (`$cos_secret_id`, `$cos_secret_key`, `$cos_bucket`, `$cos_region`) and security key (`$base64Key`).
- Tencent COS SDK (implicitly required via `require_once 'src/cos-sdk-v5-7/tencent-php/vendor/autoload.php';` in `cos_preview_url_gen.php`, though not directly included in this file, suggesting SDK is expected to be available).

**Input**:
- POST request with parameter:
    - `file-name` (required): Filename of the file to be uploaded, used to determine file extension.
    - `cosKey` (required): The desired object key (path) for the file in COS.

**Output**:
- JSON response containing temporary COS credentials and configuration for file upload, or an error message for invalid requests.

---

### login.php

**Description**: API endpoint for user login, handling authentication and session management.

**Functionality**:
- **User Authentication**: Verifies user credentials (username/email and password) against the `users` database table.
- **Cloudflare Turnstile Verification**: Integrates with Cloudflare Turnstile to protect against bot attacks during login.
- **Session Management**: On successful login, starts a PHP session and sets a cookie(`token`) to maintain user login state for 7 days.
- **Login Logging**: Logs successful login attempts, recording user ID, login time, IP address, and user agent in the `login_logs` table.
- **Error Handling**: Implements error handling for:
    - Incomplete request parameters (missing `cf_token`, `username`, or `password`).
    - Empty username or password.
    - Cloudflare Turnstile verification failure.
    - Incorrect username or password.
    - Database errors during login process.
- **Response Formatting**: Returns JSON responses to indicate login status:
    - Success response (HTTP 200 OK): Includes `success: true`, `message: "登录成功！"`, and `token` for client-side session management.
    - Error responses (HTTP 400 Bad Request, HTTP 401 Unauthorized, HTTP 500 Internal Server Error): Includes `success: false` and a descriptive `message` indicating the reason for failure.

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations, including `$base64Key` for token encryption and Cloudflare Turnstile secret key.
- `global_error_handler.php`: For global error handling functions like `logException`.

**Input**:
- POST request with parameters:
    - `cf_token` (required): Cloudflare Turnstile token for bot verification.
    - `username` (required): Username or email address for login.
    - `password` (required): User's password.

**Output**:
- JSON response indicating login success or failure. On success, includes a user authentication token in a cookie and in the JSON response body.

---

### pauseDisableClassroom.php

**Description**: API endpoint to re-enable a classroom by setting its `unavailable` status to 0 in the database. It is misnamed and should be called `resumeClassroom.php`.

**Functionality**:
- **Classroom Re-enabling**: Updates the `unavailable` column in the `classrooms` table to 0 for a specific classroom ID, effectively re-enabling the classroom for bookings.
- **Admin Authentication**: Authenticates admin users using a token sent via POST to ensure only administrators can perform this action.
- **Input Sanitization**: Sanitizes the classroom ID input to prevent XSS and SQL injection vulnerabilities.
- **Error Handling**: Includes error handling for:
    - Missing authentication token (returns 401 Unauthorized).
    - Invalid token (returns 401 Unauthorized).
    - Unauthorized access (non-admin user, returns 401 Unauthorized).
    - Missing classroom ID (returns 400 Bad Request).
    - Database errors during update operation (returns 500 Internal Server Error).
- **JSON Response**: Returns JSON response indicating success or failure of the classroom re-enabling operation.

**Requires**:
- `global_variables.php`: For global configurations, including encryption key.
- `db.php`: For database connection.
- `admin_emails.php`: For admin authentication.

**Input**:
- POST request with parameters:
    - `token` (required): Admin authentication token.
    - `id` (required): ID of the classroom to re-enable.

**Output**:
- JSON response indicating success or failure of the classroom re-enabling.

---

### priv_emails.php

**Description**: Manages and retrieves email addresses of privileged users, with caching to optimize performance.

**Functionality**:
- **Privileged User Check**: Provides a function `isPriv($email)` to check if an email address belongs to a privileged user.
- **Caching**: Implements caching for privileged user emails to minimize database queries.
    - Loads privileged emails from a cache file (`cache/privileged_emails_cache_Dm26sw.php`) if available and not expired.
    - Fetches privileged emails from the database if cache is invalid or empty.
    - Saves privileged emails to cache after database fetch.
- **Cache Expiration**: Cache duration is set by `$authItemsCacheTime` variable, configured in `global_variables.php`.
- **Database Interaction**: Queries the `privilegers` table to fetch the list of privileged user emails.

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations, including cache file path and expiration time.

**Functions**:
- `isPriv($email)`: Checks if an email is in the privileged users list.
- `loadPrivilegedEmailsFromCache()`: Loads privileged user emails from the cache file.
- `savePrivilegedEmailsToCache($emails)`: Saves privileged user emails to the cache file.
- `loadPrivilegedEmailsFromDatabase()`: Loads privileged user emails from the database.

**Cache**:
- File-based caching for privileged user emails.
- Cache file path is `$privCacheFile`, defined in `global_variables.php`.
- Cache expiration is `$authItemsCacheTime`, defined in `global_variables.php`.

---

### process_repair.php

**Description**: API endpoint for processing repair requests, allowing administrators to update the status and notify users via email.

**Functionality**:
- **Repair Request Status Update**: Updates the `status` of a repair request in the `repair_requests` table.
- **Status Options**: Supports three actions (status codes):
    - 1: Repair Completed
    - 2: Request Returned (Rejected)
    - 3: Duplicate Request
- **Admin Authentication**: Authenticates admin users using a token sent via POST to ensure only administrators can process repair requests.
- **Email Notifications**: Sends email notifications to users about the status update of their repair requests.
    - Uses PHPMailer library to send emails.
    - Sends different email content based on the action taken (completed, rejected, duplicate).
- **Error Handling**: Comprehensive error handling for various scenarios:
    - Invalid request method (only POST allowed).
    - Invalid or missing request parameters (`id`, `token`, `action`).
    - Unauthorized access (invalid token or non-admin user).
    - Repair request not found.
    - Database operation failures.
    - Email sending failures.
- **Transaction Management**: Uses database transactions to ensure data consistency when updating request status and logging operations.
- **Input Validation**: Validates and sanitizes request parameters to prevent invalid data and potential security issues.

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations, including email settings and encryption key.
- `admin_emails.php`: For admin authentication.
- `global_error_handler.php`: For global error handling functions like `logException`.
- PHPMailer library for email sending.

**Input**:
- POST request with JSON body or form data containing:
    - `token` (required): Admin authentication token.
    - `id` (required): ID of the repair request to process.
    - `action` (required): Action code indicating the new status (1, 2, or 3).

**Output**:
- JSON response indicating success or failure of the repair request processing. On success, includes a success message. On failure, includes an error message detailing the reason for failure.

---

### reg.php

**Description**: API endpoint for user registration. **Currently disabled and returns an error.**

**Functionality**:
- **User Registration (Disabled)**: Intended to handle user registration but is currently disabled, always returning an error message.
- **Input Validation**: Includes validation for username, password, and email format, although the endpoint is not functional.
- **Password Hashing**: Includes password hashing using `password_hash` for secure storage, though registration is disabled.
- **Email Verification (Commented Out)**: Contains commented-out code for sending email verification links, suggesting email verification was intended but not implemented.
- **Database Interaction**: Intended to insert new user data into the `users` table, but this functionality is disabled.
- **Error Handling**: Includes basic error handling, but the endpoint primarily returns a pre-set error response due to being disabled.

**Requires**:
- `db.php`: For database connection (though not actually used in the current disabled state).
- `global_variables.php`: For global configurations (though not actually used in the current disabled state).

**Input**:
- POST request with user registration details (currently not processed due to endpoint being disabled):
    - `username` (required)
    - `password` (required)
    - `email` (required)
    - `cf_token` (required): Cloudflare Turnstile token for bot verification.

**Output**:
- JSON response indicating that user registration is disabled, regardless of input. Returns `success: false` and `message: "Endpoint temporarily disabled"`.

---

### reject.php

**Description**: This file handles the rejection of classroom booking requests by administrators.

**Functionality**:
- Updates the request status to 'rejected' in the database.
- Sends an email to the user who made the request to notify them of the rejection, including a reason for rejection.
- Includes error handling for database operations and email sending.
- Provides different rejection reasons that can be selected by the administrator.

**Requires**:
- `global_variables.php`: For global configurations.
- `db.php`: For database connection.
- `admin_emails.php`: For admin email functionalities.
- PHPMailer library for sending emails.

**Input**:
- POST request with `token`, `Id`, and `Reason`.
    - `token`: Encrypted admin email for authentication.
    - `Id`: ID of the booking request to reject.
    - `Reason`: Code for the rejection reason, determining the specific rejection message sent to the user.

**Output**:
- JSON response indicating success or failure of the operation.

---

### report_error.php

**Description**: API endpoint for reporting client-side errors to the server, logging error details into a database and sending email notifications.

**Functionality**:
- **Error Data Capture**: Receives error details from client-side applications via POST requests, including URL, timestamp, user agent, referrer, screen resolution, language, cookie and platform information, and user feedback.
- **Data Validation and Sanitization**: Validates and sanitizes all input data to prevent malicious data injection.
- **Database Logging**: Stores error reports in the `error_reports` table in the database, including timestamp conversion to MySQL DATETIME format. Uses transactions to ensure data integrity.
- **Email Notification**: Sends email notifications to a designated feedback email address (`feedback@hfiuc.org`) upon receiving a new error report.
    - Uses PHPMailer to send emails.
    - Includes detailed error information in the email body for administrator review.
- **Error Handling**: Implements comprehensive error handling for:
    - Invalid data format (non-JSON or not an array).
    - Database operation failures (PDOExceptions).
    - Email sending failures (PHPMailer Exceptions).
- **JSON Response**: Returns JSON responses to indicate the status of error reporting:
    - Success response (HTTP 201 Created): Includes `success: true` and `message: 'Error report stored and email sent.'`.
    - Error responses (HTTP 400 Bad Request, HTTP 500 Internal Server Error): Includes `success: false` and a descriptive `message` indicating the reason for failure.

**Requires**:
- `db.php`: For database connection.
- `global_variables.php`: For global configurations, including email settings.
- PHPMailer library for sending emails.

**Input**:
- POST request with JSON body containing error details:
    - `url` (required): URL where the error occurred.
    - `timestamp` (required): Timestamp of the error.
    - `userAgent` (optional): User agent string.
    - `referrer` (optional): Referrer URL.
    - `screenWidth` (optional): Screen width.
    - `screenHeight` (optional): Screen height.
    - `language` (optional): Browser language.
    - `cookiesEnabled` (optional): Boolean indicating if cookies are enabled.
    - `platform` (optional): User platform.
    - `userFeedback` (optional): User-provided feedback string.

**Output**:
- JSON response indicating success or failure of the error reporting process. On success, confirms that the error report was stored and an email was sent. On failure, provides an error message.
