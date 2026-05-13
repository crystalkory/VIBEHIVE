<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Payment Successful</title>
<style>
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; 
    max-width: 600px; 
    margin: 50px auto; 
    padding: 20px; 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.success-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 40px;
    text-align: center;
    border: 2px solid #7b68ee;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}
.success-icon {
    font-size: 64px;
    color: #10b981;
    margin-bottom: 20px;
}
h1 {
    color: #10b981;
    margin-bottom: 20px;
}
p {
    color: #6b7280;
    margin-bottom: 30px;
    font-size: 18px;
}
.button {
    display: inline-block;
    padding: 12px 30px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    text-decoration: none;
    border-radius: 10px;
    font-weight: bold;
    transition: all 0.3s ease;
}
.button:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
}
</style>
</head>
<body>
<div class="success-container">
    <div class="success-icon">✓</div>
    <h1>Payment Successful!</h1>
    <p>Your posts have been successfully boosted. They will receive increased visibility for the selected duration.</p>
    <a href="boost_post.php" class="button">Boost More Posts</a>
    <a href="index.php" style="margin-left: 15px;" class="button">Return to Home</a>
</div>
</body>
</html>