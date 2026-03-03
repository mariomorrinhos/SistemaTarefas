<?php
// criar_usuario.php
session_start();
require_once 'config/database/conexao.php';

// 1. SEGURANÇA ESTRITA: Apenas Super Admin (Nível 1)
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] != 1) {
    header("Location: usuarios.php");
    exit;
}

$msg = "";
$erro = "";

// 2. PROCESSAMENTO DO FORMULÁRIO
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];
    $nivel = intval($_POST['nivel']);
    
    // Verifica email duplicado
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() > 0) {
        $erro = "Erro: Este e-mail já está em uso.";
    } else {
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        
        // AQUI ESTÁ A MUDANÇA: 'trocar_senha' = 1
        $sql = "INSERT INTO usuarios (nome, email, senha, nivel, ativo, trocar_senha) VALUES (?, ?, ?, ?, 1, 1)";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$nome, $email, $hash, $nivel])) {
            $msg = "Usuário cadastrado com sucesso! Ele deverá trocar a senha no primeiro acesso.";
        } else {
            $erro = "Erro ao inserir no banco de dados.";
        }
    }
}

// Lista de Níveis
$cargos = [ 
    1 => 'Super Admin', 2 => 'Administrador', 3 => 'Secretário', 
    4 => 'Gerente', 5 => 'Fiscal', 6 => 'Administrativo', 7 => 'Público' 
];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Novo Usuário - Atlas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #f0f2f5; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        
        .card-form {
            background: white; border-radius: 20px; padding: 2.5rem; width: 100%; max-width: 500px;
            box-shadow: 0 15px 40px rgba(0, 77, 38, 0.1); border-top: 5px solid #004d26;
        }
        .btn-atlas { background: #004d26; color: white; font-weight: bold; }
        .btn-atlas:hover { background: #00381c; color: white; }
    </style>
</head>
<body>

    <div class="container d-flex justify-content-center">
        <div class="card-form">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold m-0" style="color: #004d26;"><i class="fa-solid fa-user-plus me-2"></i>Novo Usuário</h4>
                <a href="usuarios.php" class="btn btn-sm btn-outline-secondary rounded-pill">Voltar</a>
            </div>

            <?php if($msg): ?>
                <div class="alert alert-success rounded-3 shadow-sm text-center">
                    <i class="fa-solid fa-check-circle fa-2x mb-2 d-block"></i>
                    <?= $msg ?>
                    <div class="mt-3">
                        <a href="usuarios.php" class="btn btn-sm btn-success">Ver Lista</a>
                        <a href="criar_usuario.php" class="btn btn-sm btn-outline-success">Cadastrar Outro</a>
                    </div>
                </div>
            <?php else: ?>

                <?php if($erro): ?>
                    <div class="alert alert-danger rounded-3 small"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $erro ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="fw-bold small text-muted">Nome Completo</label>
                        <input type="text" name="nome" class="form-control rounded-3" required>
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold small text-muted">E-mail de Acesso</label>
                        <input type="email" name="email" class="form-control rounded-3" required>
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold small text-muted">Nível de Acesso</label>
                        <select name="nivel" class="form-select rounded-3" required>
                            <option value="">Selecione...</option>
                            <?php foreach($cargos as $id => $nome): ?>
                                <option value="<?= $id ?>"><?= $id ?> - <?= $nome ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="fw-bold small text-muted">Senha Inicial</label>
                        <input type="text" name="senha" class="form-control rounded-3" placeholder="Ex: atlas2024" required>
                        <div class="form-text">Crie uma senha forte. O usuário será obrigado a trocar no primeiro acesso.</div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-atlas py-2 rounded-3">Cadastrar Usuário</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php include 'chat_widget.php'; ?>
</body>
</html>