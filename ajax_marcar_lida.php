<?php
// ajax_marcar_lida.php
session_start();
require_once 'config/database/conexao.php';

if (!isset($_SESSION['usuario_id']) || !isset($_POST['id'])) {
    http_response_code(400); exit;
}

$id_notificacao = intval($_POST['id']);
$usuario_id = $_SESSION['usuario_id'];

try {
    // Atualiza pelo ID da TABELA NOTIFICACOES (Chave Primária)
    $stmt = $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id_notificacao, $usuario_id]);
    echo "ok";
} catch (Exception $e) {
    http_response_code(500);
}
?>