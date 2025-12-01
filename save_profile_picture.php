<?php
// Start session and set headers for JSON response
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging but don't display errors
error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off display_errors to prevent HTML output

// Check if user is logged in
if (!isset($_SESSION['employee_no'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "employee_managements";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get employee number
$employee_no = $_SESSION['employee_no'];

// Check if file was uploaded
if (!isset($_FILES['profile_picture'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit();
}

$uploadedFile = $_FILES['profile_picture'];

// Check for upload errors
if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
        UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
    ];
    
    $errorMessage = $uploadErrors[$uploadedFile['error']] ?? 'Unknown upload error';
    echo json_encode(['success' => false, 'message' => 'Upload error: ' . $errorMessage]);
    exit();
}

// Validate file type using extension
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
$fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

if (!in_array($fileExtension, $allowedExtensions)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed.']);
    exit();
}

// Validate file size (max 5MB - reduced from 50MB for safety)
$maxFileSize = 5 * 1024 * 1024;
if ($uploadedFile['size'] > $maxFileSize) {
    echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB.']);
    exit();
}

// Check if file is actually an image
$imageInfo = @getimagesize($uploadedFile['tmp_name']);
if (!$imageInfo) {
    echo json_encode(['success' => false, 'message' => 'Uploaded file is not a valid image']);
    exit();
}

// Create uploads directory if it doesn't exist
$uploadDir = 'uploads/profile_pictures/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
        exit();
    }
}

// Generate unique filename
$filename = 'profile_' . $employee_no . '_' . time() . '.' . $fileExtension;
$filePath = $uploadDir . $filename;

// Move uploaded file
if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    exit();
}

// Try to resize image if possible (but don't fail if it doesn't work)
$resizeSuccess = resizeImage($filePath, 500, 500);
if (!$resizeSuccess) {
    // If resize fails, we'll still use the original but log it
    error_log("Image resize failed for: " . $filePath);
}

// Update database with file path
$webPath = $filePath;

// Get old profile picture path
$oldPicture = null;
$oldPictureQuery = $conn->prepare("SELECT profile_picture FROM employees WHERE employee_no = ?");
if ($oldPictureQuery) {
    $oldPictureQuery->bind_param("s", $employee_no);
    $oldPictureQuery->execute();
    $oldPictureResult = $oldPictureQuery->get_result();
    if ($oldPictureResult && $oldPictureResult->num_rows > 0) {
        $oldPicture = $oldPictureResult->fetch_assoc()['profile_picture'];
    }
    $oldPictureQuery->close();
}

// Update the database
$stmt = $conn->prepare("UPDATE employees SET profile_picture = ? WHERE employee_no = ?");
if ($stmt) {
    $stmt->bind_param("ss", $webPath, $employee_no);
    
    if ($stmt->execute()) {
        // Delete old profile picture if it exists and is not the default
        if (!empty($oldPicture) && file_exists($oldPicture) && strpos($oldPicture, 'uploads/profile_pictures/') !== false) {
            @unlink($oldPicture); // Use @ to suppress errors
        }
        
        echo json_encode(['success' => true, 'message' => 'Profile picture updated successfully', 'path' => $webPath]);
    } else {
        // Delete the new file if database update fails
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
    }
    $stmt->close();
} else {
    // Delete the new file if prepare fails
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

$conn->close();

// Function to resize images - made more robust
function resizeImage($filePath, $maxWidth, $maxHeight) {
    // Check if GD extension is available
    if (!extension_loaded('gd') || !function_exists('gd_info')) {
        return false;
    }
    
    $imageInfo = @getimagesize($filePath);
    if (!$imageInfo) {
        return false;
    }
    
    list($width, $height, $type) = $imageInfo;
    
    // If image is smaller than max dimensions, no need to resize
    if ($width <= $maxWidth && $height <= $maxHeight) {
        return true;
    }
    
    // Calculate new dimensions maintaining aspect ratio
    $ratio = $width / $height;
    if ($maxWidth / $maxHeight > $ratio) {
        $newWidth = $maxHeight * $ratio;
        $newHeight = $maxHeight;
    } else {
        $newWidth = $maxWidth;
        $newHeight = $maxWidth / $ratio;
    }
    
    // Create source image based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = @imagecreatefromjpeg($filePath);
            break;
        case IMAGETYPE_PNG:
            $source = @imagecreatefrompng($filePath);
            break;
        case IMAGETYPE_GIF:
            $source = @imagecreatefromgif($filePath);
            break;
        default:
            return false; // Unsupported image type
    }
    
    if (!$source) {
        return false;
    }
    
    // Create destination image
    $destination = @imagecreatetruecolor($newWidth, $newHeight);
    if (!$destination) {
        imagedestroy($source);
        return false;
    }
    
    // Preserve transparency for PNG and GIF
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        @imagecolortransparent($destination, imagecolorallocatealpha($destination, 0, 0, 0, 127));
        @imagealphablending($destination, false);
        @imagesavealpha($destination, true);
    }
    
    // Resize the image
    if (!@imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
        imagedestroy($source);
        imagedestroy($destination);
        return false;
    }
    
    // Save the resized image
    $success = false;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $success = @imagejpeg($destination, $filePath, 85);
            break;
        case IMAGETYPE_PNG:
            $success = @imagepng($destination, $filePath, 8);
            break;
        case IMAGETYPE_GIF:
            $success = @imagegif($destination, $filePath);
            break;
    }
    
    imagedestroy($source);
    imagedestroy($destination);
    
    return $success;
}
?>