<?php
// api_temporeal.php
ob_start(); // Segura o buffer
error_reporting(0); // Esconde warnings do PHP para não sujar o JSON
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once 'config/database/conexao.php';

// Segurança
$nivel = $_SESSION['usuario_nivel'] ?? 7;
if (!isset($_SESSION['usuario_id']) || $nivel > 4) {
    ob_clean();
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

date_default_timezone_set('America/Sao_Paulo');

// Estrutura padrão de resposta
$response = [
    'error' => false,
    'kpi' => ['abertas' => 0, 'atrasadas' => 0, 'hoje' => 0, 'hora' => date('H:i:s')],
    'total_monitorado' => 0,
    'tabela' => [],
    'tabela_engajamento' => [],
    'charts' => [
        'equipe' => ['labels' => [], 'em_dia' => [], 'atrasadas' => [], 'concluidas' => []],
        'status' => [0, 0, 0],
        'prio'   => ['atrasado' => [0,0,0,0], 'andamento' => [0,0,0,0], 'arquivado' => [0,0,0,0]],
        'logins_nivel' => ['labels' => [], 'data' => []]
    ]
];

try {
    // -----------------------------------------------------------------------
    // 1. DADOS DE TAREFAS
    // -----------------------------------------------------------------------
    $sqlBaseJoin = "FROM tarefas t JOIN usuarios u ON t.usuario_id = u.id WHERE u.nivel < 7";
    
    // KPI Abertas
    $stmt = $pdo->query("SELECT COUNT(*) $sqlBaseJoin AND t.status IN ('pendente', 'em_andamento') AND t.prazo >= NOW()");
    if($stmt) $response['kpi']['abertas'] = $stmt->fetchColumn();

    // KPI Atrasadas
    $stmt = $pdo->query("SELECT COUNT(*) $sqlBaseJoin AND (t.status = 'atrasado' OR (t.status != 'concluido' AND t.status != 'arquivado' AND t.prazo < NOW()))");
    if($stmt) $response['kpi']['atrasadas'] = $stmt->fetchColumn();

    // KPI Hoje
    $stmt = $pdo->query("SELECT COUNT(DISTINCT tarefa_id) FROM historico_tarefas WHERE acao = 'status' AND descricao LIKE '%Conclui%' AND DATE(data_acao) = CURDATE()");
    if($stmt) $response['kpi']['hoje'] = $stmt->fetchColumn();

    // --- CORREÇÃO AQUI (Gráfico Equipe) ---
    // Removemos a soma de aliases no ORDER BY e usamos COUNT direto
    $sqlEquipe = "SELECT u.nome, 
                  COUNT(CASE WHEN t.status IN ('pendente', 'em_andamento') AND t.prazo >= NOW() THEN 1 END) as em_dia,
                  COUNT(CASE WHEN t.status = 'atrasado' OR (t.status != 'concluido' AND t.status != 'arquivado' AND t.prazo < NOW()) THEN 1 END) as atrasadas,
                  COUNT(CASE WHEN t.status = 'concluido' THEN 1 END) as concluidas
                  FROM usuarios u 
                  LEFT JOIN tarefas t ON u.id = t.usuario_id 
                  WHERE u.nivel < 7 AND u.ativo = 1
                  GROUP BY u.id 
                  ORDER BY COUNT(CASE WHEN t.status != 'concluido' THEN 1 END) DESC LIMIT 10";
                  
    $stmt = $pdo->query($sqlEquipe);
    if($stmt) {
        $equipeRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['tabela'] = $equipeRaw;
        foreach($equipeRaw as $d) {
            $parts = explode(' ', $d['nome']);
            $nome = $parts[0] . (isset($parts[1]) ? ' '.substr($parts[1],0,1).'.' : '');
            $response['charts']['equipe']['labels'][] = $nome;
            $response['charts']['equipe']['em_dia'][] = intval($d['em_dia']);
            $response['charts']['equipe']['atrasadas'][] = intval($d['atrasadas']);
            $response['charts']['equipe']['concluidas'][] = intval($d['concluidas']);
        }
    }

    // Gráfico Status
    $stmt = $pdo->query("SELECT 
        COUNT(CASE WHEN status = 'concluido' THEN 1 END) as feito,
        COUNT(CASE WHEN status IN ('pendente', 'em_andamento') AND prazo >= NOW() THEN 1 END) as andamento,
        COUNT(CASE WHEN status = 'atrasado' OR (status != 'concluido' AND status != 'arquivado' AND prazo < NOW()) THEN 1 END) as atrasado
        FROM tarefas t JOIN usuarios u ON t.usuario_id = u.id WHERE u.nivel < 7");
    if($stmt) {
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        $response['charts']['status'] = [intval($s['andamento']), intval($s['atrasado']), intval($s['feito'])];
        $response['total_monitorado'] = array_sum($response['charts']['status']);
    }

    // Gráfico Prioridades
    $stmt = $pdo->query("SELECT prioridade,
                COUNT(CASE WHEN status = 'arquivado' THEN 1 END) as qtd_arquivado,
                COUNT(CASE WHEN status = 'atrasado' OR (status != 'concluido' AND status != 'arquivado' AND prazo < NOW()) THEN 1 END) as qtd_atrasado,
                COUNT(CASE WHEN status IN ('pendente', 'em_andamento') AND prazo >= NOW() THEN 1 END) as qtd_andamento
                FROM tarefas t JOIN usuarios u ON t.usuario_id = u.id 
                WHERE u.nivel < 7 AND YEAR(t.prazo) = YEAR(CURDATE())
                GROUP BY prioridade");
    if($stmt) {
        $resPrio = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resPrio as $row) {
            $p = strtolower($row['prioridade']); 
            $idx = -1;
            if (strpos($p, 'baixa') !== false) $idx = 0; elseif (strpos($p, 'media') !== false) $idx = 1; elseif (strpos($p, 'alta') !== false) $idx = 2; elseif (strpos($p, 'urgente') !== false) $idx = 3;
            if ($idx >= 0) {
                $response['charts']['prio']['atrasado'][$idx] = intval($row['qtd_atrasado']);
                $response['charts']['prio']['andamento'][$idx] = intval($row['qtd_andamento']);
                $response['charts']['prio']['arquivado'][$idx] = intval($row['qtd_arquivado']);
            }
        }
    }

    // -----------------------------------------------------------------------
    // 2. DADOS DE LOGIN
    // -----------------------------------------------------------------------
    try {
        // Teste se tabela existe
        $pdo->query("SELECT 1 FROM historico_logins LIMIT 1");
        
        // Logins por Nível
        $stmt = $pdo->query("SELECT u.nivel, COUNT(h.id) as total FROM historico_logins h JOIN usuarios u ON h.usuario_id = u.id WHERE h.data_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY u.nivel");
        $resLogin = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cargos = [ 1=>'Super Admin', 2=>'Admin', 3=>'Secretário', 4=>'Gerente', 5=>'Fiscal', 6=>'Admin.', 7=>'Público' ];
        foreach($resLogin as $r) {
            $response['charts']['logins_nivel']['labels'][] = $cargos[$r['nivel']] ?? 'Outro';
            $response['charts']['logins_nivel']['data'][] = intval($r['total']);
        }

        // Engajamento
        $stmt = $pdo->query("SELECT u.nome, COUNT(h.id) as qtd_logins, MAX(h.data_login) as ultimo_acesso FROM usuarios u LEFT JOIN historico_logins h ON u.id = h.usuario_id AND h.data_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE u.nivel < 7 AND u.ativo = 1 GROUP BY u.id ORDER BY qtd_logins DESC LIMIT 15");
        $response['tabela_engajamento'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        // Tabela não existe, ignora silenciosamente
    }

} catch (PDOException $e) {
    $response['error'] = 'Erro SQL: ' . $e->getMessage();
}

ob_end_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>