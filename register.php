<?php
umask(0002);
session_start();

$data_dir = __DIR__ . '/data';
if (!is_dir($data_dir)) { @mkdir($data_dir, 0775, true); }
$pm2_env_file = $data_dir . '/pm2_env.json';
if (!file_exists($pm2_env_file)) {
    $defaults = [
        'GEMINI_API_KEY' => '',
        'GEMINI_MODEL' => 'gemini-1.5-flash-latest',
        'GOOGLE_TRANSLATE_API_KEY' => '',
        'TRANSLATOR_TARGET_LANG' => 'en',
        'TRANSLATOR_INTERVAL_MS' => '60000',
        'TELEGRAM_AUTO_SEND_ES' => '1',
        'PM2_BIN' => '',
        'INPUT_RSS' => __DIR__ . '/rss.xml',
        'OUTPUT_RSS' => __DIR__ . '/rss_en.xml'
    ];
    $encoded = json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        @file_put_contents($pm2_env_file, $encoded . "\n");
    }
}
$credentials_file = $data_dir . '/auth.json';

// Si ya existe un usuario, ir a login
if (file_exists($credentials_file)) {
    header('Location: telex.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Usuario y contraseña son obligatorios.';
    } else {
        // Pequeña sanitización del usuario
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
            $error = 'El usuario debe tener 3-40 caracteres alfanuméricos, ., _ o -';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $data = [ 'username' => $username, 'password_hash' => $hash, 'created_at' => date('c') ];
            $tmp = $credentials_file . '.tmp';
            @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            @rename($tmp, $credentials_file);
            $_SESSION['loggedin'] = true;
            header('Location: telex.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Telex — Registro</title>
  <link rel="icon" href="telex.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Special+Elite&display=swap" rel="stylesheet">
  <style>
    body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background-color:#f8f9fa;}
    .login-container{background:white;padding:2rem 3rem;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);width:340px;}
    h1{text-align:center;margin-bottom:1.5rem;font-weight:500;color:#333;}
    .form-group{margin-bottom:1rem;}
    input{width:100%;padding:.75rem;box-sizing:border-box;border:1px solid #ccc;border-radius:4px;font-size:1rem;}
    .button{width:100%;padding:.75rem;background-color:#0d6efd;color:white;border:none;border-radius:4px;cursor:pointer;font-size:1rem;}
    .error{color:#dc3545;text-align:center;margin-top:1rem;font-size:.9rem;}
    .special-elite-regular {font-family: "Special Elite", system-ui; font-weight: 400;font-style: normal; font-size:2em; color:#0d6efd; padding:16px; padding-top:0px;}
  </style>
</head>
<body>
  <div class="login-container">
    <form method="post">
      <img src="telex.png" alt="Telex" style="display:block;margin:auto;width:92px;height:auto;" />
      <h1 class="special-elite-regular">Registro inicial</h1>
      <div class="form-group"><input type="text" name="username" placeholder="Usuario" required autofocus></div>
      <div class="form-group"><input type="password" name="password" placeholder="Contraseña" required></div>
      <button type="submit" class="button">Crear usuario</button>
      <?php if($error) echo '<p class="error">'.htmlspecialchars($error).'</p>'; ?>
    </form>
  </div>
</body>
</html>
