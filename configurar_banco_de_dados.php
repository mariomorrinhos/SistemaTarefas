<?php
// ATIVAR EXIBIÇÃO DE ERROS
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>Instalador do Banco de Dados - HabitaNet Tarefas</h2>";
echo "Conectando ao banco de dados...<br>";

// Inclui a sua conexão
require_once 'config/database/conexao.php';

try {
    // Inicia a transação
    $pdo->beginTransaction();

    echo "Criando tabelas (se não existirem)...<br>";

    $queries = [
        // 1. TABELA USUÁRIOS
        "CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            senha VARCHAR(255) NOT NULL,
            nivel INT DEFAULT 7 COMMENT '1=Super Admin, 2=Admin, 3=Sec, 4=Gerente, 5=Fiscal, 6=Admin, 7=Publico',
            ativo TINYINT(1) DEFAULT 1,
            trocar_senha TINYINT(1) DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 2. TABELA CATEGORIAS
        "CREATE TABLE IF NOT EXISTS categorias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(50) NOT NULL,
            cor VARCHAR(7) DEFAULT '#6c757d'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 3. TABELA TAREFAS
        "CREATE TABLE IF NOT EXISTS tarefas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            protocolo VARCHAR(20) NULL,
            titulo VARCHAR(255) NOT NULL,
            descricao TEXT,
            prazo DATETIME NOT NULL,
            prioridade VARCHAR(20) DEFAULT 'media' COMMENT 'baixa, media, alta, urgente',
            status VARCHAR(20) DEFAULT 'pendente' COMMENT 'pendente, em_andamento, atrasado, concluido, arquivado',
            usuario_id INT NOT NULL,
            criado_por INT NOT NULL,
            categoria_id INT NULL,
            numero_prodata VARCHAR(50) NULL,
            ci VARCHAR(50) NULL,
            cci VARCHAR(50) NULL,
            nome_interessado VARCHAR(255) NULL,
            endereco VARCHAR(255) NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (criado_por) REFERENCES usuarios(id),
            FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 4. TABELA EVENTOS (CALENDÁRIO)
        "CREATE TABLE IF NOT EXISTS eventos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            descricao TEXT NULL,
            inicio DATETIME NOT NULL,
            termino DATETIME NULL,
            numero_tarefa VARCHAR(50) NULL,
            status VARCHAR(20) DEFAULT 'pendente' COMMENT 'pendente, concluido',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 5. TABELA HISTÓRICO DE TAREFAS
        "CREATE TABLE IF NOT EXISTS historico_tarefas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tarefa_id INT NOT NULL,
            usuario_id INT NOT NULL,
            acao VARCHAR(50) NOT NULL COMMENT 'status, comentario, edicao, prazo, transferencia, anexo, info',
            descricao TEXT NOT NULL,
            data_acao DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tarefa_id) REFERENCES tarefas(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 6. TABELA ANEXOS DE TAREFAS (Armazenando arquivos em BLOB)
        "CREATE TABLE IF NOT EXISTS tarefa_anexos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tarefa_id INT NOT NULL,
            nome_arquivo VARCHAR(255) NOT NULL,
            tamanho INT NOT NULL,
            dados_arquivo LONGBLOB NOT NULL,
            tipo_arquivo VARCHAR(100) NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tarefa_id) REFERENCES tarefas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 7. TABELA NOTIFICAÇÕES
        "CREATE TABLE IF NOT EXISTS notificacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            mensagem TEXT NOT NULL,
            link VARCHAR(255) NULL,
            lida TINYINT(1) DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 8. TABELA HISTÓRICO DE LOGINS
        "CREATE TABLE IF NOT EXISTS historico_logins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            data_login DATETIME DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(50) NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 9. TABELA LOGS DE TENTATIVAS (AUDITORIA/SEGURANÇA)
        "CREATE TABLE IF NOT EXISTS logs_tentativas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email_tentado VARCHAR(100) NOT NULL,
            ip_origem VARCHAR(50) NULL,
            status VARCHAR(20) NOT NULL COMMENT 'sucesso, falha',
            data_tentativa DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    // Executa a criação das tabelas
    foreach ($queries as $sql) {
        $pdo->exec($sql);
    }
    echo "<span style='color: green;'>✔ Todas as tabelas foram criadas com sucesso.</span><br><br>";

    // =========================================================================
    // INSERÇÕES PADRÃO (SETUP INICIAL)
    // =========================================================================

    // Inserir categoria de TI forçando o ID 13 (Se não existir)
    $checkCat = $pdo->prepare("SELECT id FROM categorias WHERE id = 13");
    $checkCat->execute();
    if (!$checkCat->fetch()) {
        echo "Inserindo categoria 'TI' (ID 13)...<br>";
        $pdo->exec("INSERT INTO categorias (id, nome, cor) VALUES (13, 'TI', '#0d6efd')");
    }

    // Inserir algumas categorias básicas adicionais (opcional, só se a tabela estiver vazia exceto pela TI)
    $checkCatAll = $pdo->query("SELECT COUNT(*) FROM categorias")->fetchColumn();
    if ($checkCatAll <= 1) {
        echo "Inserindo categorias padrões adicionais...<br>";
        $pdo->exec("INSERT IGNORE INTO categorias (nome, cor) VALUES 
            ('Geral', '#6c757d'),
            ('Urgência', '#dc3545'),
            ('Reunião', '#ffc107')
        ");
    }

    // Inserir Usuário Administrador Padrão (Se não existir nenhum admin)
    $checkUser = $pdo->prepare("SELECT id FROM usuarios WHERE email = 'admin@habitanet.com.br'");
    $checkUser->execute();
    if (!$checkUser->fetch()) {
        echo "Criando usuário Administrador padrão...<br>";
        // A senha padrão será: 123456
        $senhaHash = password_hash('123456', PASSWORD_DEFAULT);
        $stmtUser = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, nivel, ativo, trocar_senha) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtUser->execute(['Administrador HabitaNet', 'admin@habitanet.com.br', $senhaHash, 1, 1, 0]);
        
        echo "<div style='background: #e2e3e5; padding: 15px; border-radius: 8px; margin-top: 10px;'>
                <strong>Credenciais de Acesso Criadas:</strong><br>
                E-mail: <code>admin@habitanet.com.br</code><br>
                Senha: <code>123456</code><br>
              </div><br>";
    }

    $pdo->commit();
    echo "<h3><span style='color: green;'>Banco de Dados configurado e pronto para uso! 🚀</span></h3>";
    echo "<p><a href='index.php' style='padding: 10px 20px; background: #004d26; color: white; text-decoration: none; border-radius: 5px;'>Ir para a Tela de Login</a></p>";
    echo "<p style='color: red; font-size: 12px;'>Aviso: Após confirmar que o sistema está funcionando, apague este arquivo (setup_db.php) por segurança.</p>";

} catch (PDOException $e) {
    $pdo->rollBack();
    echo "<h3><span style='color: red;'>ERRO FATAL:</span></h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
?>
