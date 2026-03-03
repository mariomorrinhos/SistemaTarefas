<?php
// cadastro.php
session_start();
require_once 'config/database/conexao.php';

$msg = "";
$erro = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];
    $confirma = $_POST['confirma_senha'];
    $token = trim($_POST['token']);

    // Validações básicas
    if ($senha !== $confirma) {
        $erro = "As senhas não conferem.";
    } else {
        // Verifica se email já existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            $erro = "Este e-mail já está cadastrado.";
        } else {
            // Lógica do Nível e Token
            $nivelCadastro = 7; // Padrão: Público
            
            if (!empty($token)) {
                $stmtToken = $pdo->prepare("SELECT nivel_vinculado FROM tokens_cadastro WHERE token = ? AND ativo = 1");
                $stmtToken->execute([$token]);
                
                if ($stmtToken->rowCount() > 0) {
                    $nivelCadastro = $stmtToken->fetchColumn();
                } else {
                    $erro = "Token inválido ou inativo. Tente novamente ou deixe em branco.";
                }
            }

            // Se não houve erro de token, prossegue
            if (empty($erro)) {
                $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
                
                $sql = "INSERT INTO usuarios (nome, email, senha, nivel, ativo) VALUES (?, ?, ?, ?, 1)";
                $stmt = $pdo->prepare($sql);
                
                if ($stmt->execute([$nome, $email, $senhaHash, $nivelCadastro])) {
                    $msg = "Cadastro realizado com sucesso! Você já pode entrar.";
                } else {
                    $erro = "Erro ao cadastrar. Tente novamente.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Atlas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #f0f2f5; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card-cadastro { background: white; border-radius: 20px; padding: 2.5rem; width: 100%; max-width: 500px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .btn-atlas { background: #004d26; color: white; font-weight: bold; }
        .btn-atlas:hover { background: #00381c; color: white; }
    </style>
</head>
<body>
    <div class="card-cadastro">
        <h4 class="fw-bold mb-4 text-center" style="color: #004d26;">Auto Cadastro Atlas</h4>
        
        <?php if($erro): ?>
            <div class="alert alert-danger small rounded-3"><?= $erro ?></div>
        <?php endif; ?>
        
        <?php if($msg): ?>
            <div class="alert alert-success text-center rounded-3">
                <?= $msg ?><br>
                <a href="index.php" class="btn btn-sm btn-outline-success mt-2">Ir para Login</a>
            </div>
        <?php else: ?>

        <form method="POST">
            <div class="mb-3">
                <label class="small fw-bold text-muted">Nome Completo</label>
                <input type="text" name="nome" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="small fw-bold text-muted">E-mail</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="row mb-3">
                <div class="col-6">
                    <label class="small fw-bold text-muted">Senha</label>
                    <input type="password" name="senha" class="form-control" required>
                </div>
                <div class="col-6">
                    <label class="small fw-bold text-muted">Confirmar Senha</label>
                    <input type="password" name="confirma_senha" class="form-control" required>
                </div>
            </div>
            
            <div class="mb-4 bg-light p-3 rounded-3 border">
                <label class="small fw-bold text-dark"><i class="fa-solid fa-key me-1"></i> Token de Acesso (Opcional)</label>
                <input type="text" name="token" class="form-control mt-1 border-secondary" placeholder="Possui um código da empresa?">
                <small class="text-muted" style="font-size: 0.7rem;">Se não preencher, seu acesso será de nível "Público".</small>
            </div>

            <button type="submit" class="btn btn-atlas w-100 py-2 rounded-3">Finalizar Cadastro</button>
            <div class="text-center mt-3">
                <a href="index.php" class="text-decoration-none small text-secondary">Voltar ao Login</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>