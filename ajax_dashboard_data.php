<?php
// ajax_dashboard_data.php
session_start();
require_once 'config/database/conexao.php';
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['usuario_id'])) { echo json_encode([]); exit; }

$usuario_id = $_SESSION['usuario_id'];
$anoKpi = isset($_GET['ano']) ? intval($_GET['ano']) : date('Y');
$mesAtual = date('m');
$anoAtual = date('Y');

// 1. Estatísticas
$sqlStats = "SELECT 
    COUNT(CASE WHEN status = 'concluido' THEN 1 END) as total_concluidas, 
    COUNT(CASE WHEN status = 'atrasado' OR (status != 'concluido' AND prazo < NOW()) THEN 1 END) as total_atrasadas, 
    COUNT(CASE WHEN status IN ('pendente', 'em_andamento') AND prazo >= NOW() THEN 1 END) as total_abertas, 
    COUNT(CASE WHEN status IN ('pendente', 'em_andamento') AND MONTH(prazo) = ? AND YEAR(prazo) = ? THEN 1 END) as pendentes_mes,
    COUNT(*) as total_geral 
FROM tarefas 
WHERE usuario_id = ? AND YEAR(prazo) = ?";

$stmt = $pdo->prepare($sqlStats);
$stmt->execute([$mesAtual, $anoAtual, $usuario_id, $anoKpi]); 
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Notificações
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0");
$stmt->execute([$usuario_id]); 
$qtdNotificacoes = $stmt->fetchColumn();

// Retorna JSON
echo json_encode([
    'abertas' => $stats['total_abertas'],
    'pendentes_mes' => $stats['pendentes_mes'],
    'concluidas' => $stats['total_concluidas'],
    'atrasadas' => $stats['total_atrasadas'],
    'avisos' => $qtdNotificacoes
]);
?>