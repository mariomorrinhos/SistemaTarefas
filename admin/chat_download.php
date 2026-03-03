<?php
// chat_download.php
session_start();
require_once '../config/database/conexao.php';
date_default_timezone_set('America/Sao_Paulo'); // Importante para comparar horários

if (!isset($_SESSION['usuario_id']) || !isset($_GET['id'])) { die("Acesso negado."); }

$id_msg = intval($_GET['id']);
$meu_id = $_SESSION['usuario_id'];

// Busca dados e DATA DE ENVIO
$stmt = $pdo->prepare("SELECT arquivo_nome, arquivo_tipo, arquivo_tamanho, arquivo_dados, data_envio 
                       FROM chat_mensagens 
                       WHERE id = ? AND (id_de = ? OR id_para = ?)");
$stmt->execute([$id_msg, $meu_id, $meu_id]);
$arquivo = $stmt->fetch(PDO::FETCH_ASSOC);

if ($arquivo) {
    // 1. Verifica se já passou 1 hora (3600 segundos)
    $dataEnvio = strtotime($arquivo['data_envio']);
    $agora = time();
    
    if (($agora - $dataEnvio) > 3600) {
        die("Este arquivo expirou (disponível apenas por 1 hora).");
    }

    // 2. Verifica se o conteúdo ainda existe
    if ($arquivo['arquivo_dados']) {
        header("Content-Type: " . $arquivo['arquivo_tipo']);
        header("Content-Length: " . $arquivo['arquivo_tamanho']);
        header("Content-Disposition: attachment; filename=" . $arquivo['arquivo_nome']);
        echo $arquivo['arquivo_dados'];
    } else {
        die("O conteúdo do arquivo já foi removido do servidor.");
    }
} else {
    echo "Arquivo não encontrado.";
}
?>