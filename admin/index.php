<?php
// admin/index.php
session_start();
require_once '../config/database/conexao.php';

// 1. SEGURANÇA
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] > 4) {
    header("Location: ../dashboard.php");
    exit;
}

// -------------------------------------------------------------------------
// 2. AÇÕES DE CATEGORIA (CRUD Simples via POST na mesma página)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_categoria'])) {
    $acao = $_POST['acao_categoria'];
    
    if ($acao === 'criar') {
        $nome = trim($_POST['nome']);
        $cor = $_POST['cor'];
        if ($nome) $pdo->prepare("INSERT INTO categorias (nome, cor) VALUES (?, ?)")->execute([$nome, $cor]);
    }
    elseif ($acao === 'excluir') {
        $id = intval($_POST['id']);
        // Remove a categoria das tarefas antes de excluir (seta NULL)
        $pdo->prepare("UPDATE tarefas SET categoria_id = NULL WHERE categoria_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM categorias WHERE id = ?")->execute([$id]);
    }
    
    // Redireciona para evitar reenvio de formulário
    header("Location: index.php");
    exit;
}

// -------------------------------------------------------------------------
// 3. BUSCAR DADOS (KPIs, Listas, Gráficos)
// -------------------------------------------------------------------------
$anoAtual = date('Y');

// KPIs
$totalTarefas = $pdo->query("SELECT COUNT(*) FROM tarefas t JOIN usuarios u ON t.usuario_id = u.id WHERE u.nivel < 7 AND YEAR(t.criado_em) = $anoAtual")->fetchColumn();
$totalAtrasadas = $pdo->query("SELECT COUNT(*) FROM tarefas t JOIN usuarios u ON t.usuario_id = u.id WHERE t.status != 'concluido' AND t.prazo < NOW() AND u.nivel < 7 AND YEAR(t.criado_em) = $anoAtual")->fetchColumn();
$totalConcluidas = $pdo->query("SELECT COUNT(*) FROM tarefas t JOIN usuarios u ON t.usuario_id = u.id WHERE t.status = 'concluido' AND u.nivel < 7 AND YEAR(t.criado_em) = $anoAtual")->fetchColumn();
$totalUsuarios = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1 AND nivel < 7")->fetchColumn();

// Listagem de Categorias (Para o Modal e para o Filtro)
$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nome ASC")->fetchAll();

// DADOS PARA O GRÁFICO DE PIZZA (Tarefas Ativas por Categoria no Ano Corrente)
// Consideramos ativas as tarefas que NÃO estão 'concluido' ou 'arquivado'.
$sqlChart = "
    SELECT 
        COALESCE(c.nome, 'Sem Categoria') as categoria_nome, 
        COALESCE(c.cor, '#cbd5e1') as categoria_cor,
        COUNT(t.id) as total 
    FROM tarefas t
    JOIN usuarios u ON t.usuario_id = u.id
    LEFT JOIN categorias c ON t.categoria_id = c.id
    WHERE u.nivel < 7 
      AND YEAR(t.criado_em) = ? 
      AND t.status NOT IN ('concluido', 'arquivado')
    GROUP BY t.categoria_id
    ORDER BY total DESC
";
$stmtChart = $pdo->prepare($sqlChart);
$stmtChart->execute([$anoAtual]);
$dadosChart = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

$labelsChart = [];
$dataChart = [];
$colorsChart = [];

foreach ($dadosChart as $row) {
    $labelsChart[] = $row['categoria_nome'];
    $dataChart[] = $row['total'];
    $colorsChart[] = $row['categoria_cor'];
}

// Paginação e Filtros
$filtroStatus = $_GET['status'] ?? '';
$filtroUsuario = $_GET['usuario'] ?? '';
$filtroCategoria = $_GET['categoria'] ?? ''; // Pode ser ID ou 'sem_categoria'
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$porPagina = 10;
$inicio = ($pagina - 1) * $porPagina;

$sqlBase = "FROM tarefas t 
            JOIN usuarios u ON t.usuario_id = u.id 
            JOIN usuarios c ON t.criado_por = c.id 
            LEFT JOIN categorias cat ON t.categoria_id = cat.id 
            WHERE u.nivel < 7 AND c.nivel < 7 AND YEAR(t.criado_em) = ?";
$params = [$anoAtual];

if ($filtroStatus) {
    if ($filtroStatus == 'atrasado') {
        $sqlBase .= " AND t.status != 'concluido' AND t.prazo < NOW()";
    } else { 
        $sqlBase .= " AND t.status = ?"; 
        $params[] = $filtroStatus; 
    }
}
if ($filtroUsuario) { 
    $sqlBase .= " AND t.usuario_id = ?"; 
    $params[] = $filtroUsuario; 
}
if ($filtroCategoria !== '') { 
    if ($filtroCategoria === 'sem_categoria') {
        $sqlBase .= " AND t.categoria_id IS NULL";
    } else {
        $sqlBase .= " AND t.categoria_id = ?"; 
        $params[] = $filtroCategoria; 
    }
}

// Total de registros para paginação
$stmtCount = $pdo->prepare("SELECT COUNT(*) $sqlBase");
$stmtCount->execute($params);
$totalRegistros = $stmtCount->fetchColumn();
$totalPaginas = ceil($totalRegistros / $porPagina);

// Buscar tarefas ordenadas pela ÚLTIMA atualização de histórico (ou data de criação caso não tenha histórico)
$sqlGeral = "SELECT t.*, u.nome as responsavel, c.nome as criador, cat.nome as categoria_nome, cat.cor as categoria_cor,
            (SELECT descricao FROM historico_tarefas h WHERE h.tarefa_id = t.id ORDER BY data_acao DESC LIMIT 1) as ultimo_historico_desc,
            (SELECT data_acao FROM historico_tarefas h WHERE h.tarefa_id = t.id ORDER BY data_acao DESC LIMIT 1) as ultimo_historico_data
            $sqlBase 
            ORDER BY COALESCE(ultimo_historico_data, t.criado_em) DESC 
            LIMIT $inicio, $porPagina";
$stmt = $pdo->prepare($sqlGeral);
$stmt->execute($params);
$tarefasGerais = $stmt->fetchAll();

$listaUsuarios = $pdo->query("SELECT id, nome FROM usuarios WHERE nivel < 7 ORDER BY nome ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro Administrativo - HabitaNet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; min-height: 100vh; }
        .navbar-admin { background: linear-gradient(90deg, #1a2a6c, #b21f1f); box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        .navbar-brand { font-weight: 800; letter-spacing: 1px; color: white !important; }
        
        /* Ajuste do tamanho dos cards de KPI com ALTURA FIXA E PEQUENA */
        .card-kpi { border: none; border-radius: 12px; background: white; padding: 1rem; box-shadow: 0 3px 10px rgba(0,0,0,0.04); transition: transform 0.2s; display: flex; align-items: center; justify-content: space-between; height: 100px; }
        .card-kpi:hover { transform: translateY(-2px); }
        .kpi-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .kpi-value { font-size: 1.4rem; font-weight: 800; color: #2d3748; line-height: 1; margin-bottom: 4px; }
        .kpi-label { font-size: 0.65rem; color: #718096; font-weight: 600; text-transform: uppercase; line-height: 1.1; }
        .kpi-blue { background-color: #ebf8ff; color: #3182ce; } .kpi-red { background-color: #fff5f5; color: #e53e3e; } .kpi-green { background-color: #f0fff4; color: #38a169; } .kpi-purple { background-color: #faf5ff; color: #805ad5; }
        
        /* Ajuste do tamanho do Card do Gráfico com ALTURA FIXA IGUAL AOS KPIs */
        .card-chart { border: none; border-radius: 12px; background: white; padding: 0.8rem 1rem; box-shadow: 0 3px 10px rgba(0,0,0,0.04); height: 100px; display: flex; flex-direction: column; }
        .chart-title { font-size: 0.65rem; color: #4a5568; font-weight: 700; text-transform: uppercase; margin-bottom: 0.2rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.2rem; }
        .chart-container-inner { position: relative; height: 60px; width: 100%; display: flex; justify-content: center; align-items: center;}

        .table-container { background: white; border-radius: 20px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.05); display: flex; flex-direction: column; height: 100%; }
        .table-responsive { flex-grow: 1; }
        .table thead th { border: none; text-transform: uppercase; font-size: 0.75rem; color: #a0aec0; font-weight: 700; padding-bottom: 1rem; }
        .table tbody td { vertical-align: middle; color: #4a5568; font-size: 0.9rem; border-top: 1px solid #f7fafc; padding: 1rem 0.5rem; }

        .pagination .page-item .page-link { color: #1a2a6c; border: none; margin: 0 3px; border-radius: 8px; font-weight: 600; font-size: 0.9rem; }
        .pagination .page-item.active .page-link { background-color: #1a2a6c; color: white; box-shadow: 0 4px 10px rgba(26, 42, 108, 0.3); }
        .pagination .page-item.disabled .page-link { color: #cbd5e1; background: transparent; }
        .pagination .page-link:hover { background-color: #f1f5f9; }
        
        /* Categoria Badge */
        .badge-cat { font-size: 0.65rem; padding: 3px 8px; border-radius: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display: inline-block; color: white; }
        
        /* Tooltip Nativo Estilizado e Linha Clicável */
        .historico-hover { cursor: help; border-bottom: 1px dotted #ccc; display: inline-block; max-width: 250px; }
        .clickable-row { cursor: pointer; transition: background-color 0.2s; }
        .clickable-row:hover td { background-color: #f8faff; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark navbar-admin fixed-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="#"><i class="fa-solid fa-building-shield me-2"></i>CENTRO ADMINISTRATIVO</a>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#modalCategorias">
                    <i class="fa-solid fa-tags me-1"></i> Categorias
                </button>
                <a href="../dashboard.php" class="btn btn-sm btn-outline-light rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> Voltar ao App
                </a>
            </div>
        </div>
    </nav>

    <div style="margin-top: 80px;"></div>

    <div class="container-fluid px-4 pb-5">
        
        <h4 class="fw-bold text-dark mb-4">Visão Geral da Organização (<?= $anoAtual ?>)</h4>

        <div class="row g-2 mb-4">
            
            <div class="col-lg-2 col-md-6">
                <div class="card-kpi">
                    <div>
                        <div class="kpi-value"><?= $totalTarefas ?></div>
                        <div class="kpi-label">Total de Tarefas</div>
                    </div>
                    <div class="kpi-icon kpi-blue"><i class="fa-solid fa-layer-group"></i></div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-6">
                <div class="card-kpi" style="cursor: pointer;" onclick="window.location.href='atrasadas.php'" title="Ver tarefas atrasadas">
                    <div>
                        <div class="kpi-value text-danger"><?= $totalAtrasadas ?></div>
                        <div class="kpi-label text-danger">Atrasadas (Risco)</div>
                    </div>
                    <div class="kpi-icon kpi-red"><i class="fa-solid fa-circle-exclamation"></i></div>
                </div>
            </div>

            <div class="col-lg-2 col-md-6">
                <div class="card-kpi">
                    <div>
                        <div class="kpi-value"><?= $totalConcluidas ?></div>
                        <div class="kpi-label">Entregas Feitas</div>
                    </div>
                    <div class="kpi-icon kpi-green"><i class="fa-solid fa-check-circle"></i></div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-6">
                <div class="card-kpi">
                    <div>
                        <div class="kpi-value"><?= $totalUsuarios ?></div>
                        <div class="kpi-label">Equipe Ativa</div>
                    </div>
                    <div class="kpi-icon kpi-purple"><i class="fa-solid fa-users"></i></div>
                </div>
            </div>

            <div class="col-lg-4 col-md-12">
                <div class="card-chart">
                    <div class="chart-title"><i class="fa-solid fa-chart-pie me-2 text-primary"></i> Ativas por Categoria</div>
                    <div class="chart-container-inner">
                        <?php if(empty($dataChart)): ?>
                            <div class="text-muted small text-center w-100"><i class="fa-solid fa-folder-open mb-1 fs-5 opacity-25"></i><br>Nenhuma ativa.</div>
                        <?php else: ?>
                            <canvas id="chartCategorias"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
        </div>

        <div class="row mb-4 g-3">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm text-white" style="background: linear-gradient(135deg, #4b6cb7 0%, #182848 100%);">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div><h5 class="fw-bold mb-1"><i class="fa-solid fa-fingerprint me-2"></i>Monitoramento de Acessos</h5><p class="mb-0 opacity-75 small">Auditoria de logs, frequência e segurança.</p></div>
                        <a href="historico_acessos.php" class="btn btn-light text-dark fw-bold rounded-pill px-4">Ver Logs <i class="fa-solid fa-arrow-right ms-2"></i></a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <?php if($_SESSION['usuario_nivel'] <= 4): ?>
                <div class="card border-0 shadow-sm" style="background: linear-gradient(90deg, #1a2a6c, #2a4858); color: white;">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div><h5 class="fw-bold mb-1"><i class="fa-solid fa-database me-2"></i>Gestão de Dados</h5><p class="mb-0 opacity-75 small">Excluir dados em massa e manutenção.</p></div>
                        <a href="admin_tarefas.php" class="btn btn-light text-dark fw-bold rounded-pill px-4">Acessar Painel <i class="fa-solid fa-arrow-right ms-2"></i></a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="table-container">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="fw-bold m-0 text-secondary">Monitoramento de Tarefas</h5>
                        <span class="badge bg-light text-muted border">Total: <?= $totalRegistros ?></span>
                    </div>
                    
                    <form method="GET" class="row g-2 mb-4 p-3 bg-light rounded-3">
                        <div class="col-md-3">
                            <select name="usuario" class="form-select form-select-sm border-0 shadow-sm">
                                <option value="">Todos os Funcionários</option>
                                <?php foreach($listaUsuarios as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $filtroUsuario == $u['id'] ? 'selected' : '' ?>><?= $u['nome'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="categoria" class="form-select form-select-sm border-0 shadow-sm">
                                <option value="">Todas as Categorias</option>
                                <option value="sem_categoria" <?= $filtroCategoria === 'sem_categoria' ? 'selected' : '' ?>>Sem Categoria</option>
                                <?php foreach($categorias as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $filtroCategoria == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="status" class="form-select form-select-sm border-0 shadow-sm">
                                <option value="">Todos os Status</option>
                                <option value="pendente" <?= $filtroStatus == 'pendente' ? 'selected' : '' ?>>Pendentes</option>
                                <option value="atrasado" <?= $filtroStatus == 'atrasado' ? 'selected' : '' ?>>Atrasados</option>
                                <option value="concluido" <?= $filtroStatus == 'concluido' ? 'selected' : '' ?>>Concluídos</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-dark btn-sm w-100 rounded-pill"><i class="fa-solid fa-filter me-1"></i> Filtrar</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Protocolo</th>
                                    <th>Tarefa</th>
                                    <th>Responsável</th>
                                    <th>Prazo</th>
                                    <th>Status</th>
                                    <th>Última Atualização (Histórico)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($tarefasGerais) == 0): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">Nenhum registro encontrado.</td></tr>
                                <?php else: ?>
                                    <?php foreach($tarefasGerais as $t): 
                                        $prazoObj = new DateTime($t['prazo']);
                                        $atrasada = ($t['status'] != 'concluido' && $prazoObj < new DateTime());
                                        $protocolo = !empty($t['protocolo']) ? $t['protocolo'] : $t['id'];
                                    ?>
                                    <tr class="clickable-row" onclick="window.location.href='../detalhes_tarefa.php?id=<?= $t['id'] ?>'" title="Clique para abrir esta tarefa">
                                        <td><span class="badge bg-light text-dark border">#<?= $protocolo ?></span></td>
                                        <td>
                                            <?php if($t['categoria_nome']): ?>
                                                <span class="badge-cat" style="background-color: <?= $t['categoria_cor'] ?>"><?= $t['categoria_nome'] ?></span><br>
                                            <?php else: ?>
                                                <span class="badge-cat" style="background-color: #6c757d;">Sem Categoria</span><br>
                                            <?php endif; ?>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($t['titulo']) ?></div>
                                            <small class="text-muted" style="font-size: 0.7rem;">Criado por: <?= $t['criador'] ?></small>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 25px; height: 25px; font-size: 0.7rem;">
                                                    <?= substr($t['responsavel'], 0, 1) ?>
                                                </div>
                                                <?= $t['responsavel'] ?>
                                            </div>
                                        </td>
                                        <td><span class="<?= $atrasada ? 'text-danger fw-bold' : '' ?>"><?= $prazoObj->format('d/m/y H:i') ?></span></td>
                                        <td>
                                            <?php if($t['status'] == 'concluido'): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success px-2 py-1 rounded-pill">Feito</span>
                                            <?php elseif($atrasada): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1 rounded-pill">Atrasado</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning bg-opacity-10 text-warning px-2 py-1 rounded-pill">Andamento</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($t['ultimo_historico_desc'])): ?>
                                                <div class="text-truncate historico-hover" title="<?= htmlspecialchars($t['ultimo_historico_desc']) ?>">
                                                    <small class="text-muted fw-bold"><?= date('d/m H:i', strtotime($t['ultimo_historico_data'])) ?> -</small> 
                                                    <span class="small text-secondary"><?= htmlspecialchars($t['ultimo_historico_desc']) ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small fst-italic">Sem atualizações</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if($totalPaginas > 1): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <nav aria-label="Navegação">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?pagina=<?= $pagina-1 ?>&status=<?= $filtroStatus ?>&usuario=<?= $filtroUsuario ?>&categoria=<?= $filtroCategoria ?>" aria-label="Anterior"><span aria-hidden="true">&laquo;</span></a>
                                </li>
                                <?php 
                                $range = 2; 
                                for($i = 1; $i <= $totalPaginas; $i++): 
                                    if ($i == 1 || $i == $totalPaginas || ($i >= $pagina - $range && $i <= $pagina + $range)):
                                ?>
                                    <li class="page-item <?= ($pagina == $i) ? 'active' : '' ?>">
                                        <a class="page-link" href="?pagina=<?= $i ?>&status=<?= $filtroStatus ?>&usuario=<?= $filtroUsuario ?>&categoria=<?= $filtroCategoria ?>"><?= $i ?></a>
                                    </li>
                                <?php elseif ($i == $pagina - $range - 1 || $i == $pagina + $range + 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; endfor; ?>
                                <li class="page-item <?= ($pagina >= $totalPaginas) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?pagina=<?= $pagina+1 ?>&status=<?= $filtroStatus ?>&usuario=<?= $filtroUsuario ?>&categoria=<?= $filtroCategoria ?>" aria-label="Próximo"><span aria-hidden="true">&raquo;</span></a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalCategorias" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-tags me-2 text-warning"></i>Gerenciar Categorias</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" class="d-flex gap-2 mb-4">
                        <input type="hidden" name="acao_categoria" value="criar">
                        <input type="text" name="nome" class="form-control" placeholder="Nova Categoria..." required>
                        <input type="color" name="cor" class="form-control form-control-color" value="#0d6efd" title="Escolha a cor">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i></button>
                    </form>

                    <ul class="list-group list-group-flush">
                        <?php foreach($categorias as $cat): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-2" style="width: 15px; height: 15px; background-color: <?= $cat['cor'] ?>;"></div>
                                <?= htmlspecialchars($cat['nome']) ?>
                            </div>
                            <form method="POST" onsubmit="return confirm('Tem certeza? Isso removerá a categoria das tarefas existentes.');">
                                <input type="hidden" name="acao_categoria" value="excluir">
                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="btn btn-sm text-danger border-0"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                        <?php if(count($categorias) == 0): ?>
                            <li class="list-group-item text-center text-muted small border-0">Nenhuma categoria cadastrada.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if(!empty($dataChart)): ?>
        document.addEventListener("DOMContentLoaded", function() {
            var ctx = document.getElementById('chartCategorias').getContext('2d');
            var chartCat = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($labelsChart) ?>,
                    datasets: [{
                        data: <?= json_encode($dataChart) ?>,
                        backgroundColor: <?= json_encode($colorsChart) ?>,
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            display: true,
                            position: 'right',
                            labels: { boxWidth: 8, padding: 4, font: { size: 9, family: "'Inter', sans-serif" } }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed !== null) { label += context.parsed + ' tarefa(s)'; }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        });
        <?php endif; ?>
    </script>
    <?php include '../chat/chat_widget.php'; ?>
</body>
</html>