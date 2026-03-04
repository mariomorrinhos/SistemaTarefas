<?php
// index.php

// 1. CONFIGURAÇÃO DO TEMPO DE VIDA DA SESSÃO (1 HORA = 3600 SEGUNDOS)
ini_set('session.gc_maxlifetime', 3600); 
session_set_cookie_params(3600);

session_start();
require_once 'config/database/conexao.php';

// Se já logado, redireciona
if (isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit;
}

$erro = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];
    $ip = $_SERVER['REMOTE_ADDR']; // Captura o IP do usuário

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND ativo = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($senha, $user['senha'])) {
        
        // --- PROTEÇÃO CONTRA SEQUESTRO DE SESSÃO / SESSÕES PRESAS ---
        // Apaga o cookie antigo e gera um novo ID de sessão limpo no servidor
        session_regenerate_id(true); 
        
        // --- LOGIN SUCESSO ---
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['usuario_nome'] = $user['nome'];
        $_SESSION['usuario_nivel'] = $user['nivel'];
        // Marca a hora do login para controle manual se necessário em outras páginas
        $_SESSION['ultimo_acesso'] = time(); 
        
        // 1. Mantém o histórico antigo (para lógica de negócio)
        $pdo->prepare("INSERT INTO historico_logins (usuario_id) VALUES (?)")->execute([$user['id']]);
        
        // 2. Novo: Log de Segurança (Sucesso)
        $pdo->prepare("INSERT INTO logs_tentativas (email_tentado, ip_origem, status) VALUES (?, ?, 'sucesso')")
            ->execute([$email, $ip]);

        if ($user['trocar_senha'] == 1) {
            header("Location: nova_senha.php");
            exit;
        }

        header("Location: dashboard.php");
        exit;
    } else {
        // --- LOGIN FALHA ---
        // Registra a tentativa falha com o email digitado e o IP
        $pdo->prepare("INSERT INTO logs_tentativas (email_tentado, ip_origem, status) VALUES (?, ?, 'falha')")
            ->execute([$email, $ip]);

        $erro = "E-mail ou senha inválidos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - HabitaNet Tarefas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        
        body {
            background: linear-gradient(135deg, #004d26 0%, #002b15 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
            padding: 20px 0;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(15px);
            border-radius: 24px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        }

        .btn-atlas {
            background: #004d26;
            color: white;
            border: none;
            font-weight: 700;
            padding: 14px;
            font-size: 1rem;
            letter-spacing: 0.5px;
            transition: all 0.2s;
        }
        .btn-atlas:hover { background: #00381c; color: white; transform: translateY(-2px); }
        
        .form-control {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 14px;
            font-size: 1rem;
        }
        .form-control:focus {
            background: white;
            border-color: #004d26;
            box-shadow: 0 0 0 4px rgba(0, 77, 38, 0.1);
        }

        .footer-login {
            margin-top: 2rem;
            text-align: center;
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.85rem;
        }
        .footer-login a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: color 0.2s;
        }
        .footer-login a:hover { color: #4ade80; }

        @media (max-height: 600px) {
            body { align-items: flex-start; padding-top: 40px; justify-content: flex-start; }
        }
    </style>
</head>
<body>
    
    <div class="login-card mx-3 p-4 p-md-5">
        <div class="text-center mb-4">
            <h2 class="fw-bold mb-1" style="color: #004d26; font-size: 2rem;">
                <i class="fa-solid fa-layer-group me-2"></i>HabitaNet
            </h2>
            <p class="text-muted small fw-bold text-uppercase letter-spacing-1">Gestão de Tarefas</p>
        </div>

        <?php if($erro): ?>
            <div class="alert alert-danger py-3 text-center small rounded-3 shadow-sm mb-4 border-0" style="background-color: #fef2f2; color: #991b1b;">
                <i class="fa-solid fa-circle-exclamation me-1"></i> <?= $erro ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted ms-1">E-mail</label>
                <input type="email" name="email" class="form-control rounded-4" placeholder="nome@exemplo.com" required>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-bold text-muted ms-1">Senha</label>
                <input type="password" name="senha" class="form-control rounded-4" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-atlas w-100 rounded-4 mb-4 shadow-sm">ENTRAR</button>
        </form>

        <div class="text-center pt-3 border-top">
            <p class="small text-muted mb-2">Não possui acesso?</p>
            <a href="cadastro.php" class="btn btn-outline-secondary w-100 rounded-4 py-2 fw-bold" style="font-size: 0.9rem;">
                Criar Conta / Auto Cadastro
            </a>
        </div>
    </div>

    <div class="footer-login container px-4">
        <p class="mb-1">&copy; <?= date('Y') ?> HabitaNet</p>
        <p class="mb-2">Plataforma desenvolvida por <strong>Mário Henrique Inácio de Paula</strong></p>
        <div class="d-flex justify-content-center gap-3 mt-3">
            <a href="https://wa.me/5564992238703" target="_blank"><i class="fa-brands fa-whatsapp me-1"></i> (64) 99223-8703</a>
            <span class="text-white-50">|</span>
            <a href="https://instagram.com/mariomorrinhos" target="_blank"><i class="fa-brands fa-instagram me-1"></i> @mariomorrinhos</a>
        </div>
    </div>

</body>
</html>
