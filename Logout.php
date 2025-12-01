<?php
// Logout.php
// Start session
session_start();

// Destroy all session data
session_unset();
session_destroy();

// Redirect to login page with success message
header("Location: Login.php?logout=success");
exit();
?>