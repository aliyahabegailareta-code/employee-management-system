<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['employee_no'])) {
    header("Location: login.php");
    exit();
}

// Database configuration
$servername = "localhost";
$username = "root"; // Change to your database username
$password = "";     // Change to your database password
$dbname = "employee_managements";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get employee data
$employee_no = $_SESSION['employee_no'];
$stmt = $conn->prepare("SELECT profile_picture FROM employees WHERE employee_no = ?");
$stmt->bind_param("s", $employee_no);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

// Close connection
$conn->close();

// Get current profile picture URL
$current_profile_picture = isset($employee['profile_picture']) ? htmlspecialchars($employee['profile_picture']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Change Profile Picture</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .profile-container {
            width: 375px;
            max-width: 100%;
            background: #f8fafc;
            border-radius: 18px;
            padding: 2rem 1.5rem;
            position: relative;
            box-shadow: 0 0 20px rgba(0,0,0,0.12);
        }

        .close-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 1.5rem;
            color: #64748b;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .close-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .profile-title {
            font-size: 1.25rem;
            color: #1e293b;
            text-align: center;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .profile-pic-large {
            width: 200px;
            height: 200px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            background: #e2e8f0;
            overflow: hidden;
            position: relative;
            border: 3px solid #3b82f6;
        }

        #image-preview {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .camera-icon {
            font-size: 3rem;
            color: #94a3b8;
        }

        .preview-text {
            text-align: center;
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }

        .upload-options {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .option-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem;
            border: none;
            border-radius: 12px;
            background: white;
            color: #1e293b;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .option-btn:hover {
            background: #f1f5f9;
            transform: translateY(-1px);
        }

        .option-btn:active {
            transform: translateY(0);
        }

        .option-btn i {
            font-size: 1.125rem;
            color: #3b82f6;
        }

        .save-btn {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .save-btn:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
        }

        .save-btn:active {
            transform: translateY(0);
        }

        .save-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        #file-input {
            display: none;
        }

        /* Camera modal styles */
        .camera-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 1000;
        }

        .camera-container {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        #camera-preview {
            width: 100%;
            max-width: 500px;
            height: auto;
            background: #000;
        }

        .camera-controls {
            position: absolute;
            bottom: 2rem;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .camera-btn {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            border: none;
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .camera-btn:hover {
            transform: scale(1.1);
        }

        .camera-btn.capture {
            background: #ef4444;
            width: 72px;
            height: 72px;
        }

        .camera-btn.capture:hover {
            background: #dc2626;
        }

        .camera-btn.close {
            background: #64748b;
            color: white;
        }

        .camera-btn.close:hover {
            background: #475569;
        }

        /* Loading indicator */
        .loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .loading-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Mobile optimizations */
        @media (max-width: 480px) {
            .profile-container {
                width: 100%;
                min-height: 100vh;
                border-radius: 0;
                margin: 0;
                box-shadow: none;
            }

            body {
                padding: 0;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="profile-container">
        <span class="close-btn" onclick="window.location.href='dashboard.php'">&times;</span>
        <h1 class="profile-title">Change Profile Picture</h1>
        
        <div class="profile-pic-large">
            <div id="image-preview">
                <?php if (!empty($current_profile_picture)): ?>
                    <img src="<?php echo $current_profile_picture; ?>" alt="Profile Picture" style="width:100%; height:100%; object-fit:cover;">
                <?php else: ?>
                    <i class="fas fa-camera camera-icon"></i>
                <?php endif; ?>
            </div>
        </div>
        
        <p class="preview-text">Preview of your new profile picture</p>
        
        <div class="upload-options">
            <button class="option-btn" onclick="document.getElementById('file-input').click()">
                <i class="fas fa-folder-open"></i> Choose from Gallery
            </button>
            <button class="option-btn" onclick="openCamera()">
                <i class="fas fa-camera"></i> Take Photo
            </button>
            <button class="option-btn" onclick="removePhoto()">
                <i class="fas fa-trash-alt"></i> Remove Current Photo
            </button>
        </div>
        
        <input type="file" id="file-input" accept="image/*" onchange="handleFileSelect(event)">
        
        <button class="save-btn" id="saveBtn" onclick="saveProfilePicture()" disabled>
            <i class="fas fa-save"></i> Save Profile Picture
        </button>
    </div>

    <!-- Camera Modal -->
    <div class="camera-modal" id="cameraModal">
        <div class="camera-container">
            <video id="camera-preview" autoplay playsinline></video>
            <div class="camera-controls">
                <button class="camera-btn close" onclick="closeCamera()">
                    <i class="fas fa-times"></i>
                </button>
                <button class="camera-btn capture" onclick="capturePhoto()">
                    <i class="fas fa-camera"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Indicator -->
    <div class="loading" id="loadingIndicator">
        <div class="loading-spinner"></div>
    </div>

    <script>
        let currentImage = null;
        let stream = null;
        const imagePreview = document.getElementById('image-preview');
        const saveBtn = document.getElementById('saveBtn');
        const cameraModal = document.getElementById('cameraModal');
        const cameraPreview = document.getElementById('camera-preview');
        const loadingIndicator = document.getElementById('loadingIndicator');

        // Handle file selection from gallery
        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    updatePreview(e.target.result);
                };
                reader.readAsDataURL(file);
            }
        }

        // Open camera
        async function openCamera() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        facingMode: 'user',
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    } 
                });
                cameraPreview.srcObject = stream;
                cameraModal.style.display = 'block';
            } catch (err) {
                alert('Error accessing camera: ' + err.message);
            }
        }

        // Close camera
        function closeCamera() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            cameraModal.style.display = 'none';
        }

        // Capture photo from camera
        function capturePhoto() {
            const canvas = document.createElement('canvas');
            canvas.width = cameraPreview.videoWidth;
            canvas.height = cameraPreview.videoHeight;
            const ctx = canvas.getContext('2d');
            
            // Draw the video frame to the canvas
            ctx.drawImage(cameraPreview, 0, 0);
            
            // Convert to base64 and update preview
            const imageData = canvas.toDataURL('image/jpeg');
            updatePreview(imageData);
            
            // Close camera
            closeCamera();
        }

        // Update preview image
        function updatePreview(imageData) {
            currentImage = imageData;
            imagePreview.innerHTML = `<img src="${imageData}" alt="Profile Preview" style="width:100%; height:100%; object-fit:cover;">`;
            saveBtn.disabled = false;
        }

        // Remove current photo
        function removePhoto() {
            currentImage = null;
            imagePreview.innerHTML = '<i class="fas fa-camera camera-icon"></i>';
            saveBtn.disabled = true;
        }

        // Save profile picture
        // Save profile picture
// Save profile picture
async function saveProfilePicture() {
    if (!currentImage) return;

    try {
        showLoading();
        
        // Method 1: Convert base64 to blob and send as file (Recommended)
        const response = await fetch(currentImage);
        const blob = await response.blob();
        
        // Create form data
        const formData = new FormData();
        formData.append('profile_picture', blob, 'profile.jpg');
        formData.append('employee_no', '<?php echo $employee_no; ?>');
        formData.append('action', 'upload_profile');
        
        console.log('Sending file to server...'); // Debug
        
        // Send to server
        const uploadResponse = await fetch('save_profile_picture.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await uploadResponse.json();
        console.log('Server response:', result); // Debug
        
        if (result.success) {
            alert('Profile picture updated successfully!');
            window.location.href = 'dashboard.php';
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        console.error('Upload error:', error);
        alert('Error saving profile picture: ' + error.message);
    } finally {
        hideLoading();
    }
}

        // Show loading indicator
        function showLoading() {
            loadingIndicator.style.display = 'flex';
        }

        // Hide loading indicator
        function hideLoading() {
            loadingIndicator.style.display = 'none';
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Load current profile picture if exists
            if (currentImage) {
                updatePreview(currentImage);
            }
        });
    </script>
</body>
</html>