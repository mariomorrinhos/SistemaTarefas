<?php
// chat/api.php
session_start();
require_once '../config/database/conexao.php';
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json');

// Segurança Básica
if (!isset($_SESSION['usuario_id'])) { echo json_encode(['error' => 'Auth']); exit; }

$uid = $_SESSION['usuario_id'];
$unome = $_SESSION['usuario_nome'];
$acao = $_POST['acao'] ?? '';

// ---------------------------------------------------------------------
// 1. LOGIN NA SALA (Validação de Token)
// ---------------------------------------------------------------------
if ($acao == 'login') {
    $sala = $_POST['sala'];
    $token = $_POST['token'];
    $nivel = $_SESSION['usuario_nivel'];

    // Regra: Público (7) não entra
    if ($nivel >= 7) { 
        echo json_encode(['status'=>'erro', 'msg'=>'Acesso negado para perfil público.']); 
        exit; 
    }
    
    // Validação dos Tokens
    $tokenHabitacao = '@habita#';
    $tokenPosturas = '#posturasmorrinhos#';
    $salaAlvo = '';

    if ($sala == 'habitacao' && $token === $tokenHabitacao) $salaAlvo = 'habitacao';
    if ($sala == 'posturas' && $token === $tokenPosturas) $salaAlvo = 'posturas';

    if ($salaAlvo) {
        $_SESSION['chat_sala'] = $salaAlvo;
        $_SESSION['chat_entrada'] = date('Y-m-d H:i:s'); // Marco temporal para ver msgs
        
        // Registra presença
        $pdo->prepare("REPLACE INTO chat_online (usuario_id, nome, sala, ultimo_ping) VALUES (?, ?, ?, NOW())")->execute([$uid, $unome, $salaAlvo]);
        
        // Avisa no chat que entrou
        $pdo->prepare("INSERT INTO chat_buffer (sala, id_remetente, nome_remetente, tipo) VALUES (?, ?, ?, 'entrada')")->execute([$salaAlvo, $uid, $unome]);
        
        echo json_encode(['status'=>'ok']);
    } else {
        echo json_encode(['status'=>'erro', 'msg'=>'Token de acesso inválido.']);
    }
    exit;
}

// Verifica se usuário já está logado em alguma sala
if (!isset($_SESSION['chat_sala'])) { echo json_encode(['error' => 'No Room']); exit; }
$sala = $_SESSION['chat_sala'];

// ---------------------------------------------------------------------
// 2. ENVIAR MENSAGEM
// ---------------------------------------------------------------------
if ($acao == 'enviar') {
    $msg = trim($_POST['mensagem']);
    $destinatario = intval($_POST['destinatario']); // 0 = Todos

    if ($msg != '') {
        $stmt = $pdo->prepare("INSERT INTO chat_buffer (sala, id_remetente, nome_remetente, id_destinatario, mensagem, tipo) VALUES (?, ?, ?, ?, ?, 'texto')");
        $stmt->execute([$sala, $uid, $unome, $destinatario, $msg]);
    }
    
    // Atualiza Ping (Estou vivo)
    $pdo->prepare("UPDATE chat_online SET ultimo_ping = NOW() WHERE usuario_id = ?")->execute([$uid]);
    echo json_encode(['status'=>'ok']);
    exit;
}

// ---------------------------------------------------------------------
// 3. ATUALIZAR (POLLING - O Coração do Chat)
// ---------------------------------------------------------------------
if ($acao == 'atualizar') {
    
    // A. Manutenção: Remove quem não deu sinal de vida há 15s e avisa saída
    $stmtOff = $pdo->prepare("SELECT * FROM chat_online WHERE sala = ? AND ultimo_ping < DATE_SUB(NOW(), INTERVAL 15 SECOND)");
    $stmtOff->execute([$sala]);
    $offlineUsers = $stmtOff->fetchAll();

    foreach($offlineUsers as $off) {
        // Gera msg de saída
        $pdo->prepare("INSERT INTO chat_buffer (sala, id_remetente, nome_remetente, tipo) VALUES (?, ?, ?, 'saida')")->execute([$sala, $off['usuario_id'], $off['nome']]);
        // Remove da lista online
        $pdo->prepare("DELETE FROM chat_online WHERE usuario_id = ?")->execute([$off['usuario_id']]);
    }
    
    // Atualiza meu ping
    $pdo->prepare("UPDATE chat_online SET ultimo_ping = NOW() WHERE usuario_id = ?")->execute([$uid]);

    // B. Buscar Lista de Usuários Online
    $stmtOnline = $pdo->prepare("SELECT usuario_id, nome FROM chat_online WHERE sala = ? ORDER BY nome ASC");
    $stmtOnline->execute([$sala]);
    $users = $stmtOnline->fetchAll(PDO::FETCH_ASSOC);

    // C. Buscar Mensagens
    // Filtros: Mesma sala, ID maior que o último recebido, Data maior que minha entrada
    // Privacidade: Se destino for 0 (todos), OU destino for EU, OU remetente for EU.
    $lastId = intval($_POST['last_id']);
    $entrada = $_SESSION['chat_entrada'];

    $sql = "SELECT * FROM chat_buffer 
            WHERE sala = ? 
            AND id > ? 
            AND data_envio >= ? 
            AND (id_destinatario = 0 OR id_destinatario = ? OR id_remetente = ?)
            ORDER BY id ASC";
    
    $stmtMsg = $pdo->prepare($sql);
    $stmtMsg->execute([$sala, $lastId, $entrada, $uid, $uid]);
    $msgs = $stmtMsg->fetchAll(PDO::FETCH_ASSOC);

    // D. Limpeza Automática (Garante a regra de não salvar histórico)
    // Apaga qualquer mensagem da sala com mais de 30 minutos
    if (rand(1, 10) == 1) { // 10% de chance de rodar a limpeza por request
        $pdo->query("DELETE FROM chat_buffer WHERE data_envio < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    }

    echo json_encode(['users' => $users, 'msgs' => $msgs]);
    exit;
}

// ---------------------------------------------------------------------
// 4. SAIR
// ---------------------------------------------------------------------
if ($acao == 'sair') {
    $pdo->prepare("INSERT INTO chat_buffer (sala, id_remetente, nome_remetente, tipo) VALUES (?, ?, ?, 'saida')")->execute([$sala, $uid, $unome]);
    $pdo->prepare("DELETE FROM chat_online WHERE usuario_id = ?")->execute([$uid]);
    unset($_SESSION['chat_sala']);
    echo json_encode(['status'=>'ok']);
    exit;
}
?>