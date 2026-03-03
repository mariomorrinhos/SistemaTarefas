<?php
// minhas_tarefas.php
// ATIVAR EXIBIÇÃO DE ERROS (Para debug, remova em produção)
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'config/database/conexao.php';

// Verifica login
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$msg = "";

// =================================================================================
// 1. AUTOMAÇÃO: CONCLUIR EVENTOS PASSADOS (Mesma lógica da Agenda)
// =================================================================================
$pdo->query("UPDATE eventos SET status = 'concluido' WHERE inicio < NOW() AND status = 'pendente'");

// =================================================================================
// 2. PROCESSAMENTO DE AÇÕES (POST) - TAREFAS E EVENTOS
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // REATIVAR TAREFA (NOVO)
    if (isset($_POST['acao']) && $_POST['acao'] == 'reativar_tarefa') {
        $id = intval($_POST['id_tarefa']);
        $novo_prazo = $_POST['novo_prazo'];
        
        if (!empty($novo_prazo)) {
            $stmt = $pdo->prepare("UPDATE tarefas SET status = 'pendente', prazo = ? WHERE id = ? AND usuario_id = ?");
            if ($stmt->execute([$novo_prazo, $id, $usuario_id])) {
                $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (?, ?, 'status', 'Tarefa reativada')")
                    ->execute([$id, $usuario_id]);
                $msg = "Tarefa reativada com sucesso!";
            }
        }
    }

    // AÇÕES DE EVENTOS
    if (isset($_POST['acao_evento'])) {
        if ($_POST['acao_evento'] == 'editar_evento') {
            $id = intval($_POST['id_evento']);
            $titulo = trim($_POST['titulo']);
            $descricao = trim($_POST['descricao']);
            $stmt = $pdo->prepare("UPDATE eventos SET titulo = ?, descricao = ? WHERE id = ? AND usuario_id = ?");
            if($stmt->execute([$titulo, $descricao, $id, $usuario_id])) { $msg = "Evento atualizado."; }
        }
        if ($_POST['acao_evento'] == 'adiar_evento') {
            $id = intval($_POST['id_evento']);
            $novo_inicio = $_POST['nova_data'] . ' ' . $_POST['nova_hora'];
            $stmt = $pdo->prepare("UPDATE eventos SET inicio = ?, status = 'pendente' WHERE id = ? AND usuario_id = ?");
            if($stmt->execute([$novo_inicio, $id, $usuario_id])) { $msg = "Evento adiado."; }
        }
        if ($_POST['acao_evento'] == 'concluir_evento') {
            $id = intval($_POST['id_evento']);
            $stmt = $pdo->prepare("UPDATE eventos SET status = 'concluido' WHERE id = ? AND usuario_id = ?");
            if($stmt->execute([$id, $usuario_id])) { $msg = "Evento concluído."; }
        }
        if ($_POST['acao_evento'] == 'excluir_evento') {
            $id = intval($_POST['id_evento']);
            $pdo->prepare("DELETE FROM eventos WHERE id = ? AND usuario_id = ?")->execute([$id, $usuario_id]);
            $msg = "Evento removido.";
        }
    }
}

// AÇÃO RÁPIDA TAREFA (GET)
if (isset($_GET['acao']) && $_GET['acao'] == 'concluir' && isset($_GET['id'])) {
    $id_tarefa = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("UPDATE tarefas SET status = 'concluido' WHERE id = :id AND usuario_id = :uid");
        $stmt->execute([':id' => $id_tarefa, ':uid' => $usuario_id]);
        $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (:tid, :uid, 'status', 'Concluiu via Kanban')")->execute([':tid' => $id_tarefa, ':uid' => $usuario_id]);
        $msg = "Tarefa marcada como concluída!";
    } catch (Exception $e) { }
}

// -------------------------------------------------------------------------
// 3. FILTROS E BUSCA (TAREFAS)
// -------------------------------------------------------------------------
$busca_termo = $_GET['busca'] ?? '';
$data_cria_ini = $_GET['cria_ini'] ?? '';
$data_cria_fim = $_GET['cria_fim'] ?? '';
$data_venc_ini = $_GET['venc_ini'] ?? '';
$data_venc_fim = $_GET['venc_fim'] ?? '';

$sqlFiltros = "";
$paramsFiltros = [];

if (!empty($busca_termo)) {
    $sqlFiltros .= " AND (t.titulo LIKE :termo OR t.protocolo LIKE :termo OR t.descricao LIKE :termo OR EXISTS (SELECT 1 FROM historico_tarefas h WHERE h.tarefa_id = t.id AND h.descricao LIKE :termo))";
    $paramsFiltros[':termo'] = "%$busca_termo%";
}
if (!empty($data_cria_ini)) { $sqlFiltros .= " AND DATE(t.criado_em) >= :cria_ini"; $paramsFiltros[':cria_ini'] = $data_cria_ini; }
if (!empty($data_cria_fim)) { $sqlFiltros .= " AND DATE(t.criado_em) <= :cria_fim"; $paramsFiltros[':cria_fim'] = $data_cria_fim; }
if (!empty($data_venc_ini)) { $sqlFiltros .= " AND DATE(t.prazo) >= :venc_ini"; $paramsFiltros[':venc_ini'] = $data_venc_ini; }
if (!empty($data_venc_fim)) { $sqlFiltros .= " AND DATE(t.prazo) <= :venc_fim"; $paramsFiltros[':venc_fim'] = $data_venc_fim; }

function buscarTarefas($pdo, $uid, $statusArr, $sqlExtra, $paramsExtra) {
    try {
        $statusPlaceholders = []; $statusParams = [];
        foreach ($statusArr as $k => $status) { $key = ":status_{$k}"; $statusPlaceholders[] = $key; $statusParams[$key] = $status; }
        $inQuery = implode(', ', $statusPlaceholders);
        $sql = "SELECT t.*, DATEDIFF(t.prazo, NOW()) as dias_restantes FROM tarefas t WHERE t.usuario_id = :usuario_logado AND t.status IN ($inQuery) $sqlExtra ORDER BY t.prazo DESC"; 
        $stmt = $pdo->prepare($sql);
        $allParams = array_merge([':usuario_logado' => $uid], $statusParams, $paramsExtra);
        $stmt->execute($allParams);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

$todasPendentes = buscarTarefas($pdo, $usuario_id, ['pendente', 'em_andamento', 'atrasado'], $sqlFiltros, $paramsFiltros);
$sqlAtrasadas = $sqlFiltros . " AND t.prazo < NOW()";
$tarefasAtrasadas = buscarTarefas($pdo, $usuario_id, ['pendente', 'em_andamento', 'atrasado'], $sqlAtrasadas, $paramsFiltros);
$tarefasConcluidas = buscarTarefas($pdo, $usuario_id, ['concluido'], $sqlFiltros, $paramsFiltros);
$tarefasArquivadas = buscarTarefas($pdo, $usuario_id, ['arquivado'], $sqlFiltros, $paramsFiltros);

$kanban = [ 'baixa' => [], 'media' => [], 'alta' => [], 'urgente' => [] ];
foreach ($todasPendentes as $t) { if (isset($kanban[$t['prioridade']])) { $kanban[$t['prioridade']][] = $t; } else { $kanban['media'][] = $t; } }

// -------------------------------------------------------------------------
// 4. BUSCA EVENTOS (NOVA LÓGICA DO CALENDÁRIO INTERATIVO)
// -------------------------------------------------------------------------
// Removemos a restrição de data para carregar o histórico também.
$sqlEventos = "SELECT * FROM eventos WHERE usuario_id = ?";
$paramsEventos = [$usuario_id];
if (!empty($busca_termo)) {
    $sqlEventos .= " AND (titulo LIKE ? OR descricao LIKE ?)";
    $paramsEventos[] = "%$busca_termo%";
    $paramsEventos[] = "%$busca_termo%";
}
$sqlEventos .= " ORDER BY inicio ASC";
$stmtEvt = $pdo->prepare($sqlEventos);
$stmtEvt->execute($paramsEventos);
$todosEventos = $stmtEvt->fetchAll(PDO::FETCH_ASSOC);

$countEventosFuturos = 0;
$hojeTime = strtotime(date('Y-m-d'));
foreach ($todosEventos as $evt) {
    if ($evt['status'] !== 'concluido' && strtotime($evt['inicio']) >= $hojeTime) {
        $countEventosFuturos++;
    }
}

// Codificamos em JSON para que o Javascript do calendário possa utilizá-los na mesma página
$eventosJson = json_encode($todosEventos);
$temFiltro = !empty($busca_termo) || !empty($data_cria_ini) || !empty($data_venc_ini);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Tarefas - Atlas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap');
        
        body { background-color: #f4f6f9; font-family: 'Inter', sans-serif; min-height: 100vh; font-size: 0.9rem; display: flex; flex-direction: column; }

        .navbar-glass {
            background: rgba(255, 255, 255, 0.98); border-bottom: 1px solid rgba(0,0,0,0.05);
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }
        .navbar-brand { font-weight: 800; color: #004d26 !important; letter-spacing: -0.5px; }

        /* Filtros */
        .card-filter { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
        .form-control, .form-select { font-size: 0.85rem; border-radius: 8px; }
        .btn-filter { background: #004d26; color: white; border-radius: 8px; font-weight: 600; font-size: 0.85rem; }
        
        /* --- KANBAN BOARD STYLES --- */
        .kanban-container { display: flex; gap: 1rem; overflow-x: auto; padding-bottom: 1rem; }
        .kanban-col {
            flex: 1; min-width: 260px; background: #eef2f5;
            border-radius: 12px; padding: 10px; display: flex; flex-direction: column; border: 1px solid rgba(0,0,0,0.02);
        }

        .col-header {
            padding: 10px 15px; border-radius: 8px; margin-bottom: 12px;
            font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px;
            display: flex; justify-content: space-between; align-items: center; color: white;
        }
        .header-baixa { background: linear-gradient(135deg, #198754, #20c997); box-shadow: 0 4px 10px rgba(25, 135, 84, 0.2); }
        .header-media { background: linear-gradient(135deg, #0dcaf0, #0aa2c0); box-shadow: 0 4px 10px rgba(13, 202, 240, 0.2); }
        .header-alta  { background: linear-gradient(135deg, #fd7e14, #e06c0d); box-shadow: 0 4px 10px rgba(253, 126, 20, 0.2); }
        .header-urgente { background: linear-gradient(135deg, #dc3545, #b02a37); box-shadow: 0 4px 10px rgba(220, 53, 69, 0.2); }

        /* Cards Compactos */
        .task-card-mini {
            background: white; border-radius: 8px; padding: 12px; margin-bottom: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.03);
            text-decoration: none; display: block; transition: transform 0.2s, box-shadow 0.2s; position: relative;
        }
        .task-card-mini:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,0.08); z-index: 2; }

        .border-baixa { border-left: 4px solid #198754; }
        .border-media { border-left: 4px solid #0dcaf0; }
        .border-alta  { border-left: 4px solid #fd7e14; }
        .border-urgente { border-left: 4px solid #dc3545; }

        .mini-protocol { font-size: 0.65rem; font-weight: 800; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; display: inline-block; margin-bottom: 4px; }
        .mini-title { font-weight: 700; color: #344767; font-size: 0.85rem; line-height: 1.3; margin-bottom: 6px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .mini-meta { font-size: 0.7rem; color: #8392ab; display: flex; justify-content: space-between; align-items: center; }
        .prazo-ok { color: #198754; font-weight: 600; }
        .prazo-atrasado { color: #dc3545; font-weight: 600; }
        
        /* Tabs Estilizadas */
        .nav-pills .nav-link { color: #64748b; font-weight: 600; font-size: 0.85rem; padding: 8px 16px; transition: all 0.2s; }
        .nav-pills .nav-link.active { background-color: #004d26; color: white; }
        
        /* Cores Abas */
        .nav-pills .nav-link-red.active { background-color: #dc3545 !important; color: white !important; }
        .nav-pills .nav-link-red { color: #dc3545; }
        .nav-pills .nav-link-red:hover { background-color: #ffeaea; }

        .nav-pills .nav-link-blue.active { background-color: #0d6efd !important; color: white !important; }
        .nav-pills .nav-link-blue { color: #0d6efd; }
        .nav-pills .nav-link-blue:hover { background-color: #f0f7ff; }

        .nav-pills .nav-link-gray.active { background-color: #6c757d !important; color: white !important; }
        .nav-pills .nav-link-gray { color: #6c757d; }
        .nav-pills .nav-link-gray:hover { background-color: #f8f9fa; }

        .nav-pills .nav-link-purple.active { background-color: #8b5cf6 !important; color: white !important; }
        .nav-pills .nav-link-purple { color: #8b5cf6; }
        .nav-pills .nav-link-purple:hover { background-color: #f5f3ff; }

        /* --- NOVO ESTILO ABA CONCLUÍDAS --- */
        .completed-container { background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); overflow: hidden; }
        .completed-search-bar { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; background: #fafbfc; }
        .completed-list-scroll { max-height: 600px; overflow-y: auto; }
        .completed-item { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; }
        .completed-item:hover { background: #f8fafc; }
        .completed-item:last-child { border-bottom: none; }
        
        .completed-icon { width: 40px; height: 40px; border-radius: 50%; background: #d1fae5; color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .completed-details { flex-grow: 1; margin: 0 15px; overflow: hidden; }
        .completed-title { font-weight: 700; color: #64748b; text-decoration: line-through; margin-bottom: 2px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; }
        .completed-meta { font-size: 0.75rem; color: #94a3b8; display: flex; gap: 15px; flex-wrap: wrap; }
        
        .completed-actions { display: flex; gap: 8px; opacity: 0; transition: opacity 0.2s; }
        .completed-item:hover .completed-actions { opacity: 1; }
        .btn-action-circle { width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: white; border: 1px solid #e2e8f0; color: #64748b; transition: all 0.2s; text-decoration: none; }
        .btn-action-circle:hover { background: #f1f5f9; color: #0f172a; border-color: #cbd5e1; }
        .btn-action-circle.reactivate:hover { background: #0d6efd; color: white; border-color: #0d6efd; }

        /* ==============================================
           ESTILOS EXCLUSIVOS DO NOVO CALENDÁRIO EVENTOS 
           ============================================== */
        .calendar-weekdays div { width: 14.28%; text-align: center; }
        .calendar-days div { width: 14.28%; height: 60px; display: flex; align-items: center; justify-content: center; position: relative; cursor: pointer; border-radius: 8px; font-weight: 500; font-size: 0.9rem; color: #475569; transition: all 0.2s; }
        .calendar-days div:hover { background-color: #f8fafc; color: #0f172a; }
        .calendar-days div.empty { cursor: default; background: transparent; }
        .calendar-days div.empty:hover { background: transparent; }
        .calendar-days div.today { border: 2px solid #8b5cf6; color: #8b5cf6; font-weight: 700; }
        .calendar-days div.selected { background-color: #8b5cf6 !important; color: white !important; box-shadow: 0 4px 10px rgba(139, 92, 246, 0.3); }
        .cal-event-dot { width: 6px; height: 6px; border-radius: 50%; background-color: #8b5cf6; }
        .cal-event-dot.concluido { background-color: #10b981; }
        .cal-event-dot.atrasado { background-color: #ef4444; }
        .cal-event-dot-container { position: absolute; bottom: 8px; display: flex; gap: 3px; justify-content: center; width: 100%; }
        
        .inline-event-card { background: white; border-radius: 12px; padding: 15px; margin-bottom: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border-left: 4px solid #8b5cf6; cursor: pointer; transition: transform 0.2s; }
        .inline-event-card:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,0.08); }
        .inline-event-card.concluido { border-left-color: #10b981; opacity: 0.8; }
        .inline-event-card.concluido:hover { opacity: 1; }

        /* --- RODAPÉ ESTILIZADO --- */
        .footer-custom {
            background-color: #ffffff; border-top: 4px solid #198754; 
            padding: 1.5rem 0; margin-top: auto;
        }
        .footer-dev-label { font-size: 0.7rem; font-weight: 800; color: #1e3a8a; text-transform: uppercase; margin-bottom: 0.2rem; letter-spacing: 0.5px; }
        .footer-dev-name { font-size: 1.1rem; font-weight: 800; color: #0f766e; text-decoration: none; }
        .footer-dev-name:hover { color: #047857; }
        .footer-social-btn { width: 45px; height: 45px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; text-decoration: none; transition: transform 0.2s; margin: 0 0.5rem; }
        .footer-social-btn:hover { transform: scale(1.1); color: white; }
        .footer-whatsapp { background-color: #25D366; box-shadow: 0 4px 15px rgba(37, 211, 102, 0.4); }
        .footer-instagram { background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); box-shadow: 0 4px 15px rgba(220, 39, 67, 0.4); }

        @media (max-width: 992px) { 
            .kanban-container { flex-direction: column; } 
            .kanban-col { min-width: 100%; } 
            .completed-actions { opacity: 1; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-glass fixed-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="#"><i class="fa-solid fa-layer-group me-2"></i>HabitaNet Tarefas</a>
            <div class="d-flex gap-2">
                <a href="criar_tarefa.php" class="btn btn-primary rounded-pill btn-sm fw-bold px-3" style="background: linear-gradient(90deg, #2193b0, #6dd5ed); border: none;">
                    <i class="fa-solid fa-plus me-1"></i> Nova
                </a>
                <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> Voltar ao App
                </a>
            </div>
        </div>
    </nav>
    <div style="margin-top: 80px;"></div>

    <div class="container-fluid px-4 py-3 flex-grow-1">
        
        <?php if($msg): ?>
            <div class="alert alert-success rounded-3 shadow-sm border-0 py-2 px-3 mb-3 small"><i class="fa-solid fa-check-circle me-2"></i> <?= $msg ?></div>
        <?php endif; ?>

        <div class="card card-filter mb-3">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center filter-header" data-bs-toggle="collapse" data-bs-target="#collapseFiltros" style="cursor: pointer;">
                    <h6 class="fw-bold m-0 text-secondary small text-uppercase"><i class="fa-solid fa-filter me-2"></i>Filtros Avançados</h6>
                    <i class="fa-solid fa-chevron-down text-muted small"></i>
                </div>
                <div class="collapse <?= $temFiltro ? 'show' : '' ?> mt-3" id="collapseFiltros">
                    <form method="GET">
                        <div class="row g-2">
                            <div class="col-lg-4">
                                <input type="text" name="busca" class="form-control" placeholder="Buscar (Título, Protocolo, Histórico)..." value="<?= htmlspecialchars($busca_termo) ?>">
                            </div>
                            <div class="col-lg-3">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light">Criação</span>
                                    <input type="date" name="cria_ini" class="form-control" value="<?= $data_cria_ini ?>">
                                    <input type="date" name="cria_fim" class="form-control" value="<?= $data_cria_fim ?>">
                                </div>
                            </div>
                            <div class="col-lg-3">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light">Prazo</span>
                                    <input type="date" name="venc_ini" class="form-control" value="<?= $data_venc_ini ?>">
                                    <input type="date" name="venc_fim" class="form-control" value="<?= $data_venc_fim ?>">
                                </div>
                            </div>
                            <div class="col-lg-2">
                                <button type="submit" class="btn btn-filter w-100 btn-sm">Filtrar</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pills-pendentes">Em andamento <span class="badge bg-white text-success rounded-pill ms-1"><?= count($todasPendentes) ?></span></button></li>
            <li class="nav-item"><button class="nav-link nav-link-red" data-bs-toggle="pill" data-bs-target="#pills-atrasadas"><i class="fa-solid fa-circle-exclamation me-1"></i> Atrasadas <?php if(count($tarefasAtrasadas) > 0): ?><span class="badge bg-white text-danger ms-1"><?= count($tarefasAtrasadas) ?></span><?php endif; ?></button></li>
            <li class="nav-item"><button class="nav-link nav-link-blue" data-bs-toggle="pill" data-bs-target="#pills-concluidas">Concluídas <?php if(count($tarefasConcluidas) > 0): ?><span class="badge bg-primary text-white ms-1"><?= count($tarefasConcluidas) ?></span><?php endif; ?></button></li>
            <li class="nav-item"><button class="nav-link nav-link-gray" data-bs-toggle="pill" data-bs-target="#pills-arquivadas"><i class="fa-solid fa-box-archive me-1"></i> Arquivo</button></li>
            <li class="nav-item"><button class="nav-link nav-link-purple" data-bs-toggle="pill" data-bs-target="#pills-eventos"><i class="fa-regular fa-calendar-star me-1"></i> Eventos <span class="badge bg-white text-dark ms-1"><?= $countEventosFuturos ?></span></button></li>
        </ul>

        <div class="tab-content pb-4" id="pills-tabContent">
            
            <div class="tab-pane fade show active" id="pills-pendentes">
                <div class="kanban-container">
                    <?php 
                    $colunas = [
                        'baixa' => ['titulo' => 'Baixa Prioridade', 'classe' => 'header-baixa'],
                        'media' => ['titulo' => 'Média Prioridade', 'classe' => 'header-media'],
                        'alta'  => ['titulo' => 'Alta Prioridade', 'classe' => 'header-alta'],
                        'urgente' => ['titulo' => 'Urgente', 'classe' => 'header-urgente']
                    ];
                    foreach($colunas as $chave => $dados): ?>
                        <div class="kanban-col">
                            <div class="col-header <?= $dados['classe'] ?>"><span><?= $dados['titulo'] ?></span><span class="badge bg-white text-dark bg-opacity-75"><?= count($kanban[$chave]) ?></span></div>
                            <?php foreach($kanban[$chave] as $t): $prazo = new DateTime($t['prazo']); $atrasado = ($prazo < new DateTime()); $protocolo = $t['protocolo'] ?: $t['id']; ?>
                            <a href="detalhes_tarefa.php?id=<?= $t['id'] ?>" class="task-card-mini border-<?= $t['prioridade'] ?>"><div class="d-flex justify-content-between align-items-start"><span class="mini-protocol">#<?= $protocolo ?></span><?php if($atrasado): ?><i class="fa-solid fa-circle-exclamation text-danger" style="font-size: 0.8rem;" title="Atrasado"></i><?php endif; ?></div><div class="mini-title" title="<?= htmlspecialchars($t['titulo']) ?>"><?= htmlspecialchars($t['titulo']) ?></div><div class="mini-meta"><span class="<?= $atrasado ? 'prazo-atrasado' : 'prazo-ok' ?>"><i class="fa-regular fa-clock me-1"></i> <?= $prazo->format('d/m H:i') ?></span><i class="fa-solid fa-arrow-right-long text-muted opacity-50"></i></div></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="pills-atrasadas">
                <div class="alert alert-danger border-0 rounded-3 mb-3 d-flex align-items-center"><i class="fa-solid fa-triangle-exclamation fs-4 me-3"></i><div><strong>Atenção!</strong> Estas tarefas já passaram do prazo de entrega. Priorize-as.</div></div>
                <div class="row">
                    <?php if(count($tarefasAtrasadas) == 0): ?><div class="col-12 text-center text-muted py-5">Nenhuma tarefa atrasada! 🎉</div><?php else: ?>
                        <?php foreach ($tarefasAtrasadas as $t): $prazo = new DateTime($t['prazo']); $protocolo = $t['protocolo'] ?: $t['id']; ?>
                            <div class="col-md-6 col-lg-4 col-xl-3"><a href="detalhes_tarefa.php?id=<?= $t['id'] ?>" class="task-card-mini border-urgente shadow-sm"><div class="d-flex justify-content-between"><span class="mini-protocol">#<?= $protocolo ?></span><span class="badge bg-danger">VENCEU</span></div><div class="mini-title mt-2 text-dark"><?= htmlspecialchars($t['titulo']) ?></div><div class="mini-meta mt-2 text-danger fw-bold"><i class="fa-regular fa-calendar-xmark me-1"></i> Era para: <?= $prazo->format('d/m H:i') ?></div></a></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="pills-concluidas">
                <div class="row justify-content-center">
                    <div class="col-lg-10">
                        <div class="completed-container">
                            
                            <div class="completed-search-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div class="position-relative flex-grow-1" style="max-width: 400px;">
                                    <i class="fa-solid fa-search position-absolute top-50 translate-middle-y text-muted" style="left: 15px;"></i>
                                    <input type="text" id="filterConcluidas" class="form-control rounded-pill ps-5 border-0 shadow-sm" placeholder="Buscar nas concluídas..." onkeyup="filtrarConcluidas()">
                                </div>
                                <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill"><?= count($tarefasConcluidas) ?> tarefas feitas</span>
                            </div>
                            
                            <?php if(count($tarefasConcluidas) == 0): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-check-double fa-3x mb-3 opacity-25"></i>
                                    <p class="mb-0">Nenhuma tarefa concluída encontrada.</p>
                                </div>
                            <?php else: ?>
                                <div class="completed-list-scroll" id="listaConcluidas">
                                    <?php foreach ($tarefasConcluidas as $t): 
                                         $protocolo = $t['protocolo'] ?: $t['id'];
                                         $data = date('d/m/Y \à\s H:i', strtotime($t['prazo']));
                                    ?>
                                        <div class="completed-item">
                                            <div class="completed-icon">
                                                <i class="fa-solid fa-check"></i>
                                            </div>
                                            <div class="completed-details">
                                                <div class="completed-title" title="<?= htmlspecialchars($t['titulo']) ?>">
                                                    <?= htmlspecialchars($t['titulo']) ?>
                                                </div>
                                                <div class="completed-meta">
                                                    <span><i class="fa-solid fa-hashtag me-1"></i><?= $protocolo ?></span>
                                                    <span><i class="fa-regular fa-calendar-check me-1"></i>Prazo original: <?= $data ?></span>
                                                </div>
                                            </div>
                                            <div class="completed-actions">
                                                <a href="detalhes_tarefa.php?id=<?= $t['id'] ?>" class="btn-action-circle" title="Ver Detalhes">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <button type="button" class="btn-action-circle reactivate" title="Reativar Tarefa" onclick="abrirModalReativar(<?= $t['id'] ?>, '<?= addslashes($t['titulo']) ?>')">
                                                    <i class="fa-solid fa-rotate-left"></i>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="pills-arquivadas">
                 <div class="row">
                    <?php foreach ($tarefasArquivadas as $t): ?>
                         <div class="col-md-6 col-lg-4"><a href="detalhes_tarefa.php?id=<?= $t['id'] ?>" class="task-card-mini" style="opacity: 0.6;"><div class="d-flex justify-content-between"><span class="mini-protocol">#<?= $t['protocolo'] ?: $t['id'] ?></span><span class="text-secondary small"><i class="fa-solid fa-box-archive"></i> Arquivado</span></div><div class="mini-title text-muted"><?= htmlspecialchars($t['titulo']) ?></div></a></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="pills-eventos">
                <div class="card border-0 shadow-sm rounded-4 p-0 bg-white overflow-hidden">
                    <div class="row g-0">
                        <div class="col-lg-7 border-end p-4">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="fw-bold m-0 text-dark text-capitalize" id="calMonthYear">Mês Ano</h5>
                                <div>
                                    <button class="btn btn-sm btn-light border rounded-circle shadow-sm me-1" onclick="changeMonth(-1)" title="Mês Anterior"><i class="fa-solid fa-chevron-left"></i></button>
                                    <button class="btn btn-sm btn-light border rounded-circle shadow-sm" onclick="changeMonth(1)" title="Próximo Mês"><i class="fa-solid fa-chevron-right"></i></button>
                                </div>
                            </div>
                            <div class="calendar-wrapper">
                                <div class="calendar-weekdays d-flex text-muted small fw-bold mb-2">
                                    <div>Dom</div><div>Seg</div><div>Ter</div><div>Qua</div><div>Qui</div><div>Sex</div><div>Sáb</div>
                                </div>
                                <div class="calendar-days d-flex flex-wrap" id="calDays">
                                    </div>
                            </div>
                            <div class="mt-4 pt-3 border-top d-flex gap-4 small text-muted">
                                <div><span class="cal-event-dot d-inline-block me-1"></span> Pendente</div>
                                <div><span class="cal-event-dot concluido d-inline-block me-1"></span> Realizado</div>
                                <div><span class="cal-event-dot atrasado d-inline-block me-1"></span> Atrasado</div>
                            </div>
                        </div>

                        <div class="col-lg-5 bg-light p-4" id="calEventDetails" style="min-height: 500px; display: flex; flex-direction: column;">
                            <div class="text-center text-muted my-auto opacity-50">
                                <i class="fa-regular fa-calendar-check fa-3x mb-3"></i>
                                <h6>Selecione um dia no calendário</h6>
                                <p class="small">Os compromissos e eventos finalizados aparecerão aqui para fácil acesso.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="modal fade" id="modalReativar" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 bg-light">
                    <h5 class="modal-title fw-bold text-primary">Reativar Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted mb-3">Você está reativando: <strong id="nomeTarefaReativar" class="text-dark"></strong></p>
                    <form method="POST">
                        <input type="hidden" name="acao" value="reativar_tarefa">
                        <input type="hidden" name="id_tarefa" id="idTarefaReativar">
                        <div class="mb-3">
                            <label class="small fw-bold text-muted">Defina um novo prazo:</label>
                            <input type="datetime-local" name="novo_prazo" class="form-control rounded-3" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary fw-bold rounded-pill">Confirmar Reativação</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer-custom">
        <div class="container">
            <div class="row align-items-center text-center text-md-start">
                
                <div class="col-md-6 mb-3 mb-md-0">
                    <div class="footer-dev-label">Sistemas desenvolvidos e cedidos por</div>
                    <a href="https://www.mhos.com.br" target="_blank" class="footer-dev-name d-block">Mário Henrique Inácio de Paula</a>
                    <div style="font-size: 0.75rem; color: #adb5bd; font-weight: normal; margin-top: 3px;">Versão 10.3</div>
                </div>

                <div class="col-md-6 text-center text-md-end mb-3 mb-md-0">
                    <a href="https://wa.me/5564992238703" target="_blank" class="footer-social-btn footer-whatsapp" title="WhatsApp">
                        <i class="fa-brands fa-whatsapp"></i>
                    </a>
                    <a href="https://instagram.com/mariomorrinhos" target="_blank" class="footer-social-btn footer-instagram" title="Instagram">
                        <i class="fa-brands fa-instagram"></i>
                    </a>
                </div>

            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Funções Originais das Tarefas
        var modalReativar = new bootstrap.Modal(document.getElementById('modalReativar'));

        function abrirModalReativar(id, titulo) {
            document.getElementById('idTarefaReativar').value = id;
            document.getElementById('nomeTarefaReativar').innerText = titulo;
            modalReativar.show();
        }

        function filtrarConcluidas() {
            const input = document.getElementById('filterConcluidas');
            const filter = input.value.toLowerCase();
            const container = document.getElementById('listaConcluidas');
            if(!container) return;
            const items = container.getElementsByClassName('completed-item');
            for (let i = 0; i < items.length; i++) {
                const title = items[i].getElementsByClassName('completed-title')[0].innerText;
                const meta = items[i].getElementsByClassName('completed-meta')[0].innerText;
                if (title.toLowerCase().indexOf(filter) > -1 || meta.toLowerCase().indexOf(filter) > -1) {
                    items[i].style.display = "flex";
                } else {
                    items[i].style.display = "none";
                }
            }
        }

        /* ====================================================================
           LÓGICA DO NOVO CALENDÁRIO E VISUALIZAÇÃO NA MESMA PÁGINA
           ==================================================================== */
        const evtData = <?php echo $eventosJson; ?>;
        let currentMonth = new Date().getMonth();
        let currentYear = new Date().getFullYear();
        let selectedDate = null;
        const monthNames = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];

        function renderCalendar(month, year) {
            const calDays = document.getElementById('calDays');
            const calMonthYear = document.getElementById('calMonthYear');
            calDays.innerHTML = '';
            calMonthYear.innerText = `${monthNames[month]} de ${year}`;

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const today = new Date();
            const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

            for (let i = 0; i < firstDay; i++) {
                calDays.innerHTML += `<div class="empty"></div>`;
            }

            for (let i = 1; i <= daysInMonth; i++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
                const dayEvents = evtData.filter(e => e.inicio.startsWith(dateStr));
                
                let dotHtml = '';
                if (dayEvents.length > 0) {
                    dotHtml = '<div class="cal-event-dot-container">';
                    dayEvents.slice(0, 3).forEach(e => {
                        let dotClass = e.status === 'concluido' ? 'concluido' : '';
                        if (e.status === 'pendente' && dateStr < todayStr) dotClass = 'atrasado';
                        dotHtml += `<div class="cal-event-dot ${dotClass}"></div>`;
                    });
                    dotHtml += '</div>';
                }

                let extraClasses = '';
                if (today.getDate() === i && today.getMonth() === month && today.getFullYear() === year) extraClasses += ' today';
                if (selectedDate === dateStr) extraClasses += ' selected';

                calDays.innerHTML += `<div class="${extraClasses}" onclick="selectDate('${dateStr}')">${i}${dotHtml}</div>`;
            }
        }

        function changeMonth(offset) {
            currentMonth += offset;
            if (currentMonth < 0) { currentMonth = 11; currentYear--; }
            else if (currentMonth > 11) { currentMonth = 0; currentYear++; }
            renderCalendar(currentMonth, currentYear);
        }

        function selectDate(dateStr) {
            selectedDate = dateStr;
            renderCalendar(currentMonth, currentYear); 
            showEventsForDate(dateStr);
        }

        function showEventsForDate(dateStr) {
            const container = document.getElementById('calEventDetails');
            const dayEvents = evtData.filter(e => e.inicio.startsWith(dateStr));
            const [y, m, d] = dateStr.split('-');
            const displayDate = `${d}/${m}/${y}`;

            let html = `
                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                    <h6 class="fw-bold m-0 text-primary"><i class="fa-regular fa-calendar-lines me-2"></i>Agenda do dia ${displayDate}</h6>
                    <span class="badge bg-secondary rounded-pill">${dayEvents.length}</span>
                </div>`;

            if (dayEvents.length === 0) {
                html += `<div class="text-center text-muted my-auto opacity-50"><i class="fa-solid fa-mug-hot fa-3x mb-3"></i><p>Tudo limpo! Nenhum evento marcado.</p></div>`;
            } else {
                html += `<div style="overflow-y:auto; flex-grow:1; padding-right:5px;">`;
                dayEvents.forEach(e => {
                    const time = e.inicio.substring(11, 16);
                    const isConcluido = e.status === 'concluido';
                    const badge = isConcluido ? `<span class="badge bg-success small">Realizado</span>` : `<span class="badge bg-warning text-dark small">Pendente</span>`;
                    const safeTitulo = e.titulo.replace(/"/g, '&quot;').replace(/'/g, "\\'");
                    const safeDesc = (e.descricao || '').replace(/\r|\n/g, ' ').replace(/"/g, '&quot;').replace(/'/g, "\\'");

                    html += `
                    <div class="inline-event-card ${isConcluido ? 'concluido' : ''}" onclick="showInlineEventDetail(${e.id}, '${safeTitulo}', '${safeDesc}', '${dateStr}', '${time}', '${e.status}')">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="small fw-bold text-muted"><i class="fa-regular fa-clock me-1"></i>${time}</span>
                            ${badge}
                        </div>
                        <h6 class="fw-bold m-0 text-dark">${e.titulo}</h6>
                    </div>`;
                });
                html += `</div>`;
            }
            container.innerHTML = html;
        }

        function showInlineEventDetail(id, titulo, desc, data, hora, status) {
            const container = document.getElementById('calEventDetails');
            const [y, m, d] = data.split('-');
            const displayDate = `${d}/${m}/${y}`;
            const badge = status === 'concluido' ? `<span class="badge bg-success">Realizado</span>` : `<span class="badge bg-warning text-dark">Pendente</span>`;
            
            let htmlBtns = '';
            if (status !== 'concluido') {
                htmlBtns += `
                <form method="POST" class="w-100 mb-2">
                    <input type="hidden" name="acao_evento" value="concluir_evento">
                    <input type="hidden" name="id_evento" value="${id}">
                    <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm rounded-3"><i class="fa-solid fa-check me-2"></i>Marcar como Realizado</button>
                </form>`;
            }

            container.innerHTML = `
                <button class="btn btn-sm btn-light text-muted border mb-3 shadow-sm" onclick="showEventsForDate('${data}')" style="align-self: flex-start;">
                    <i class="fa-solid fa-arrow-left me-1"></i> Voltar à lista do dia
                </button>
                <div class="bg-white p-4 rounded-4 shadow-sm border flex-grow-1 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h4 class="fw-bold text-dark m-0 pe-2">${titulo}</h4>
                        ${badge}
                    </div>
                    <div class="d-flex gap-3 mb-4 text-muted small border-bottom pb-3">
                        <span><i class="fa-regular fa-calendar text-primary me-1"></i> ${displayDate}</span>
                        <span><i class="fa-regular fa-clock text-primary me-1"></i> ${hora}</span>
                    </div>
                    <div class="mb-4">
                        <h6 class="small fw-bold text-muted text-uppercase mb-2">Detalhes e Anotações</h6>
                        <p class="text-secondary" style="white-space: pre-wrap; line-height: 1.5; font-size: 0.95rem;">${desc || '<i class="opacity-50">Nenhuma descrição adicionada...</i>'}</p>
                    </div>
                    
                    <div class="mt-auto pt-3 border-top">
                        ${htmlBtns}
                        <form method="POST" onsubmit="return confirm('Excluir este evento definitivamente?')" class="w-100">
                            <input type="hidden" name="acao_evento" value="excluir_evento">
                            <input type="hidden" name="id_evento" value="${id}">
                            <button type="submit" class="btn btn-link text-danger w-100 text-decoration-none btn-sm">Excluir Evento</button>
                        </form>
                    </div>
                </div>
            `;
        }

        // Inicia o calendário
        document.addEventListener('DOMContentLoaded', () => {
            renderCalendar(currentMonth, currentYear);
        });
    </script>
</body>
</html>