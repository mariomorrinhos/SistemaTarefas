<?php
// admin/historico_acessos.php
session_start();
require_once '../config/database/conexao.php';
date_default_timezone_set('America/Sao_Paulo');

// 1. SEGURANÇA (Nível 1 a 4)
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] > 4) {
    header("Location: ../dashboard.php");
    exit;
}

// -------------------------------------------------------------------------
// 2. MANUTENÇÃO AUTOMÁTICA (Limpa tabela antiga e nova)
// -------------------------------------------------------------------------
$anoPassado = date('Y') - 1;
$dataCorte = "$anoPassado-12-01 00:00:00";
// Limpa logs de negócio
$pdo->query("DELETE FROM historico_logins WHERE data_login < '$dataCorte'");
// Limpa logs de segurança
$pdo->query("DELETE FROM logs_tentativas WHERE data_tentativa < '$dataCorte'");

// -------------------------------------------------------------------------
// 3. AÇÃO MANUAL: LIMPAR LOGS
// -------------------------------------------------------------------------
$msg = "";
if (isset($_POST['acao']) && $_POST['acao'] == 'limpar_logs') {
    $emailAlvo = $_POST['email_usuario_limpar'] ?? 'todos';
    
    if ($emailAlvo == 'todos') {
        $pdo->query("DELETE FROM logs_tentativas");
        $msg = "Todos os logs de segurança foram removidos.";
    } else {
        $stmt = $pdo->prepare("DELETE FROM logs_tentativas WHERE email_tentado = ?");
        $stmt->execute([$emailAlvo]);
        $msg = "Logs do usuário removidos.";
    }
}

// -------------------------------------------------------------------------
// 4. FILTROS E CONSULTA
// -------------------------------------------------------------------------
$filtroIdUsuario = $_GET['usuario'] ?? '';
$filtroPeriodo = $_GET['periodo'] ?? 'mes'; // hoje, data, mes, ano
$filtroDataExata = $_GET['data_exata'] ?? ''; // Nova variável para a data

// Busca email do usuário selecionado para filtrar na tabela de logs (que usa email)
$emailFiltro = '';
if($filtroIdUsuario) {
    $stmtU = $pdo->prepare("SELECT email FROM usuarios WHERE id = ?");
    $stmtU->execute([$filtroIdUsuario]);
    $emailFiltro = $stmtU->fetchColumn();
}

// Lista de usuários para o select
$usuarios = $pdo->query("SELECT id, nome, email FROM usuarios WHERE nivel < 7 ORDER BY nome ASC")->fetchAll();

// QUERY PRINCIPAL (Busca na tabela logs_tentativas)
// Faz LEFT JOIN com usuarios para tentar mostrar o nome se o email existir no cadastro
$sql = "SELECT l.*, u.nome as nome_usuario 
        FROM logs_tentativas l 
        LEFT JOIN usuarios u ON l.email_tentado = u.email
        WHERE 1=1 ";

$params = [];

if ($emailFiltro) {
    $sql .= " AND l.email_tentado = ?";
    $params[] = $emailFiltro;
}

// Lógica de Filtro de Data aprimorada
if ($filtroPeriodo == 'hoje') {
    $sql .= " AND DATE(l.data_tentativa) = CURDATE()";
} elseif ($filtroPeriodo == 'data' && !empty($filtroDataExata)) {
    $sql .= " AND DATE(l.data_tentativa) = ?";
    $params[] = $filtroDataExata;
} elseif ($filtroPeriodo == 'mes') {
    $sql .= " AND MONTH(l.data_tentativa) = MONTH(NOW()) AND YEAR(l.data_tentativa) = YEAR(NOW())";
} elseif ($filtroPeriodo == 'ano') {
    $sql .= " AND YEAR(l.data_tentativa) = YEAR(NOW())";
}

$sql .= " ORDER BY l.data_tentativa DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// -------------------------------------------------------------------------
// 5. DADOS PARA O GRÁFICO (Sucesso vs Falha)
// -------------------------------------------------------------------------
$labels = [];
$dataSucesso = [];
$dataFalha = [];

// Agrupamento
$agrupamento = [];
foreach ($logs as $l) {
    $time = strtotime($l['data_tentativa']);
    // Agrupa por hora se for 'hoje' ou uma data específica
    if ($filtroPeriodo == 'hoje' || ($filtroPeriodo == 'data' && !empty($filtroDataExata))) {
        $key = date('H:00', $time);
    } elseif ($filtroPeriodo == 'mes') {
        $key = date('d/m', $time);
    } else {
        $key = date('M/Y', $time);
    }
    
    if (!isset($agrupamento[$key])) {
        $agrupamento[$key] = ['sucesso' => 0, 'falha' => 0];
    }
    
    if($l['status'] == 'sucesso') {
        $agrupamento[$key]['sucesso']++;
    } else {
        $agrupamento[$key]['falha']++;
    }
}

// Ordena cronologicamente
$agrupamento = array_reverse($agrupamento);

$chartLabels = array_keys($agrupamento);
foreach($agrupamento as $dados) {
    $dataSucesso[] = $dados['sucesso'];
    $dataFalha[] = $dados['falha'];
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoria de Segurança - Atlas Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; }
        .navbar-admin { background: linear-gradient(90deg, #1a2a6c, #b21f1f); }
        
        .filter-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; }
        .log-table th { font-size: 0.8rem; text-transform: uppercase; color: #64748b; }
        .log-table td { font-size: 0.9rem; }
        
        .row-falha { background-color: #fef2f2 !important; }
        .row-falha td { color: #991b1b !important; }
        
        @media print {
            .no-print { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
            .navbar { display: none !important; }
            body { background: white; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark navbar-admin fixed-top no-print">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Voltar</a>
            <span class="navbar-text text-white fw-bold">Auditoria & Segurança</span>
        </div>
    </nav>
    <div style="margin-top: 70px;" class="no-print"></div>

    <div class="container py-4">
        
        <?php if($msg): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-3 mb-4 no-print">
                <i class="fa-solid fa-check-circle me-2"></i> <?= $msg ?>
            </div>
        <?php endif; ?>

        <div class="card filter-card p-4 mb-4 no-print shadow-sm">
            <h5 class="fw-bold mb-3"><i class="fa-solid fa-shield-halved me-2 text-primary"></i>Filtros de Segurança</h5>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="small text-muted fw-bold mb-1">Usuário</label>
                    <select name="usuario" class="form-select">
                        <option value="">Todos os Usuários</option>
                        <?php foreach($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filtroIdUsuario == $u['id'] ? 'selected' : '' ?>><?= $u['nome'] ?> (<?= $u['email'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="small text-muted fw-bold mb-1">Período</label>
                    <select name="periodo" id="periodoSelect" class="form-select" onchange="toggleDataExata()">
                        <option value="hoje" <?= $filtroPeriodo == 'hoje' ? 'selected' : '' ?>>Hoje</option>
                        <option value="data" <?= $filtroPeriodo == 'data' ? 'selected' : '' ?>>Data Específica</option>
                        <option value="mes" <?= $filtroPeriodo == 'mes' ? 'selected' : '' ?>>Mês Atual</option>
                        <option value="ano" <?= $filtroPeriodo == 'ano' ? 'selected' : '' ?>>Ano Corrente</option>
                    </select>
                </div>
                
                <div class="col-md-2" id="divDataExata" style="<?= $filtroPeriodo == 'data' ? 'display:block;' : 'display:none;' ?>">
                    <label class="small text-muted fw-bold mb-1">Qual Data?</label>
                    <input type="date" name="data_exata" class="form-control" value="<?= $filtroDataExata ?>">
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="fa-solid fa-search"></i> Buscar</button>
                    <button type="button" onclick="window.print()" class="btn btn-dark w-100"><i class="fa-solid fa-print"></i></button>
                </div>
            </form>
        </div>

        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-body">
                <h6 class="fw-bold text-muted mb-3">Tentativas de Acesso (Sucesso x Falha)</h6>
                <div style="height: 300px;">
                    <canvas id="accessChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold m-0 text-dark"><i class="fa-solid fa-list-ul me-2"></i>Registros Detalhados (<?= count($logs) ?>)</h6>
                
                <form method="POST" onsubmit="return confirm('Tem certeza? Isso apagará o histórico selecionado permanentemente.')" class="no-print">
                    <input type="hidden" name="acao" value="limpar_logs">
                    <input type="hidden" name="email_usuario_limpar" value="<?= $emailFiltro ? $emailFiltro : 'todos' ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="fa-solid fa-trash me-1"></i> Limpar Histórico <?= $emailFiltro ? 'deste Usuário' : 'Geral' ?>
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 log-table">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Data e Hora</th>
                                <th>Usuário / E-mail Tentado</th>
                                <th>IP de Origem</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($logs) > 0): ?>
                                <?php foreach($logs as $l): 
                                    $classeLinha = ($l['status'] == 'falha') ? 'row-falha' : '';
                                    $iconStatus = ($l['status'] == 'falha') ? '<i class="fa-solid fa-triangle-exclamation me-1"></i> Senha Inválida' : '<i class="fa-solid fa-check me-1"></i> Sucesso';
                                    
                                    // Se achou o usuário no join, mostra nome, senão mostra o email tentado
                                    $identificacao = $l['nome_usuario'] ? "<strong>{$l['nome_usuario']}</strong><br><small class='text-muted'>{$l['email_tentado']}</small>" : $l['email_tentado'];
                                ?>
                                <tr class="<?= $classeLinha ?>">
                                    <td class="ps-4 fw-bold text-secondary"><?= date('d/m/Y H:i:s', strtotime($l['data_tentativa'])) ?></td>
                                    <td><?= $identificacao ?></td>
                                    <td class="text-monospace"><?= $l['ip_origem'] ?></td>
                                    <td>
                                        <span class="badge <?= $l['status']=='falha' ? 'bg-danger' : 'bg-success' ?> bg-opacity-10 text-<?= $l['status']=='falha'?'danger':'success' ?>">
                                            <?= $iconStatus ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">Nenhum acesso registrado no período.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script>
        // Função para mostrar/esconder o campo de data exata baseado na seleção do Período
        function toggleDataExata() {
            var select = document.getElementById('periodoSelect');
            var divData = document.getElementById('divDataExata');
            if (select.value === 'data') {
                divData.style.display = 'block';
            } else {
                divData.style.display = 'none';
            }
        }

        const ctx = document.getElementById('accessChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [
                    {
                        label: 'Sucessos',
                        data: <?= json_encode($dataSucesso) ?>,
                        backgroundColor: '#10b981',
                        borderRadius: 4
                    },
                    {
                        label: 'Falhas (Senha Errada)',
                        data: <?= json_encode($dataFalha) ?>,
                        backgroundColor: '#ef4444',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, stacked: true, ticks: { stepSize: 1 } },
                    x: { stacked: true }
                },
                plugins: {
                    legend: { position: 'top' }
                }
            }
        });
    </script>
    <?php include '../chat_widget.php'; ?>
</body>
</html>