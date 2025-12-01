body {
  margin: 0;
  padding: 0;
  font-family: Arial, sans-serif;
  background: url("gwegw.jpg") no-repeat center center fixed;
  background-size: cover;
}

.login-wrapper {
  height: 100vh;
  display: flex;
  justify-content: center;
  align-items: center;
}

.login-box {
  background-color: white;
  width: 350px;
  padding: 30px;
  border-radius: 15px;
  text-align: center;
  box-shadow: 0px 4px 20px rgba(0, 0, 0, 0.2);
}

.logo {
  width: 180px;
  margin-bottom: 10px;
}

.admin-title {
  color: red;
  font-size: 24px;
  margin: 10px 0 20px;
}

input[type="text"],
input[type="password"] {
  width: 85%;
  padding: 14px 20px;
  margin: 12px auto;
  display: block;
  border: none;
  border-radius: 25px;
  background-color: #e0e0e0;
  font-size: 15px;
}

.password-wrapper {
  position: relative;
  width: 100%;
  margin: 12px auto;
}

.toggle-password {
  position: absolute;
  top: 50%;
  right: 25px;
  transform: translateY(-50%);
  cursor: pointer;
  width: 20px;
  height: 20px;
  user-select: none;
}

.forgot-container {
  text-align: right;
  width: 85%;
  margin: auto;
}

.forgot-container a {
  font-size: 13px;
  color: #333;
  text-decoration: underline;
}

button {
  width: 85%;
  padding: 12px;
  background-color: #007bff;
  color: white;
  border: none;
  border-radius: 25px;
  font-size: 15px;
  font-weight: bold;
  cursor: pointer;
  margin-top: 15px;
}

#error-message {
  color: red;
  font-size: 14px;
  margin-top: 10px;
}

/* Modal styles */
.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.4);
  justify-content: center;
  align-items: center;
}

.modal-content {
  background-color: #fff;
  padding: 20px;
  border-radius: 5px;
  width: 300px;
  text-align: center;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.modal-content p {
  font-size: 16px;
  margin-bottom: 20px;
}

.modal-buttons {
  display: flex;
  justify-content: center;
  gap: 10px;
}

.modal-buttons button {
  padding: 8px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  width: auto;
}

.yes-btn {
  background-color: #dc3545;
  color: white;
}

.no-btn {
  background-color: #6c757d;
  color: white;
}



