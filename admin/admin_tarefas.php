<?php
// admin/admin_tarefas.php
session_start();
require_once '../config/database/conexao.php';

// 1. SEGURANÇA
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] > 4) {
    header("Location: ../dashboard.php");
    exit;
}

$msg = "";
$erro = "";

// 2. AÇÕES DE BANCO
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['acao']) && $_POST['acao'] == 'excluir_tarefa') {
        $id = intval($_POST['id_tarefa']);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM tarefa_anexos WHERE tarefa_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM historico_tarefas WHERE tarefa_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tarefas WHERE id = ?")->execute([$id]);
            $pdo->commit();
            $msg = "Tarefa #$id excluída permanentemente.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $erro = "Erro ao excluir: " . $e->getMessage();
        }
    }
}

// 3. CONSULTA
$filtroStatus = $_GET['status'] ?? '';
$filtroResp = $_GET['responsavel'] ?? '';
$filtroCriador = $_GET['criador'] ?? '';

$sql = "SELECT t.*, u.nome as responsavel_nome, c.nome as criador_nome 
        FROM tarefas t
        LEFT JOIN usuarios u ON t.usuario_id = u.id
        LEFT JOIN usuarios c ON t.criado_por = c.id
        WHERE 1=1";

$params = [];

if ($filtroStatus) {
    if ($filtroStatus == 'pendente') $sql .= " AND t.status != 'concluido' AND t.status != 'arquivado' AND t.prazo >= NOW()";
    elseif ($filtroStatus == 'concluido') $sql .= " AND t.status = 'concluido'";
    elseif ($filtroStatus == 'arquivado') $sql .= " AND t.status = 'arquivado'";
    elseif ($filtroStatus == 'atrasado') $sql .= " AND t.status != 'concluido' AND t.status != 'arquivado' AND t.prazo < NOW()";
}

if ($filtroResp) { $sql .= " AND t.usuario_id = ?"; $params[] = $filtroResp; }
if ($filtroCriador) { $sql .= " AND t.criado_por = ?"; $params[] = $filtroCriador; }

$sql .= " ORDER BY t.id DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$usuarios = $pdo->query("SELECT id, nome FROM usuarios ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Dados - Atlas Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #f0f2f5; font-family: 'Inter', sans-serif; }
        .navbar-admin { background: linear-gradient(90deg, #1a2a6c, #b21f1f); }
        .filter-bar { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table-card { background: white; border-radius: 12px; overflow: visible; /* IMPORTANTE: Visible para o tooltip sair */ box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .badge-status { font-size: 0.75rem; font-weight: 600; padding: 5px 10px; border-radius: 20px; }
        .bg-pendente { background-color: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
        .bg-concluido { background-color: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; }
        .bg-atrasado { background-color: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }
        .bg-arquivado { background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

        /* TOOLTIP CUSTOMIZADO MELHORADO */
        .tarefa-item { position: relative; display: inline-block; }
        
        .tarefa-item .custom-tooltip {
            visibility: hidden;
            width: 350px; /* Largura fixa boa para leitura */
            background-color: #2d3748;
            color: #fff;
            text-align: justify; /* Justificado */
            border-radius: 8px;
            padding: 12px;
            position: absolute;
            z-index: 1050; /* Maior que dropdowns */
            top: 100%; /* Abaixo do item */
            left: 0;
            margin-top: 10px;
            opacity: 0;
            transition: opacity 0.3s;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            font-size: 0.85rem;
            line-height: 1.5;
            white-space: normal; /* Permite quebra de linha */
        }

        /* Seta do Tooltip */
        .tarefa-item .custom-tooltip::after {
            content: "";
            position: absolute;
            bottom: 100%;
            left: 20px; /* Ajuste conforme necessário */
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: transparent transparent #2d3748 transparent;
        }

        .tarefa-item:hover .custom-tooltip { visibility: visible; opacity: 1; }
        .tooltip-titulo { font-weight: 800; color: #fff; font-size: 0.95rem; margin-bottom: 8px; display: block; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 5px; }
        .tooltip-desc { color: #e2e8f0; display: block; max-height: 300px; overflow-y: auto; } /* Scroll se for muito grande */

        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark navbar-admin fixed-top no-print">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="index.php"><i class="fa-solid fa-database me-2"></i>GESTÃO DE DADOS</a>
            <div class="d-flex">
                <a href="index.php" class="btn btn-sm btn-outline-light rounded-pill px-3"><i class="fa-solid fa-arrow-left me-1"></i> Voltar ao Painel</a>
            </div>
        </div>
    </nav>

    <div style="margin-top: 80px;"></div>

    <div class="container-fluid px-4 pb-5">
        
        <?php if($msg): ?>
            <div class="alert alert-success border-0 shadow-sm rounded-3 mb-4"><i class="fa-solid fa-check-circle me-2"></i> <?= $msg ?></div>
        <?php endif; ?>
        <?php if($erro): ?>
            <div class="alert alert-danger border-0 shadow-sm rounded-3 mb-4"><i class="fa-solid fa-circle-exclamation me-2"></i> <?= $erro ?></div>
        <?php endif; ?>

        <div class="filter-bar no-print">
            <h6 class="fw-bold text-muted mb-3"><i class="fa-solid fa-filter me-2"></i>Filtrar Registros</h6>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="small fw-bold text-muted mb-1">Status</label>
                    <select name="status" class="form-select bg-light border-0">
                        <option value="">Todos</option>
                        <option value="pendente" <?= $filtroStatus == 'pendente' ? 'selected' : '' ?>>Pendentes</option>
                        <option value="atrasado" <?= $filtroStatus == 'atrasado' ? 'selected' : '' ?>>Atrasados</option>
                        <option value="concluido" <?= $filtroStatus == 'concluido' ? 'selected' : '' ?>>Concluídos</option>
                        <option value="arquivado" <?= $filtroStatus == 'arquivado' ? 'selected' : '' ?>>Arquivados</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="small fw-bold text-muted mb-1">Responsável Atual</label>
                    <select name="responsavel" class="form-select bg-light border-0">
                        <option value="">Todos os usuários</option>
                        <?php foreach($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filtroResp == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="small fw-bold text-muted mb-1">Criado Por</label>
                    <select name="criador" class="form-select bg-light border-0">
                        <option value="">Todos os emissores</option>
                        <?php foreach($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filtroCriador == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="fa-solid fa-search me-2"></i>Buscar</button>
                    <a href="admin_tarefas.php" class="btn btn-outline-secondary w-50" title="Limpar"><i class="fa-solid fa-eraser"></i></a>
                </div>
            </form>
        </div>

        <div class="table-card">
            <div class="table-responsive" style="overflow: visible;"> <table class="table table-hover mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 text-secondary text-uppercase small">ID</th>
                            <th class="py-3 text-secondary text-uppercase small">Título</th>
                            <th class="py-3 text-secondary text-uppercase small">Responsável</th>
                            <th class="py-3 text-secondary text-uppercase small">Criado Por</th>
                            <th class="py-3 text-secondary text-uppercase small">Criação</th> 
                            <th class="py-3 text-secondary text-uppercase small">Status</th>
                            <th class="py-3 text-end pe-4 text-secondary text-uppercase small">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($tarefas) == 0): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach($tarefas as $t): 
                                $criacao = new DateTime($t['criado_em']);
                                $prazo = new DateTime($t['prazo']);
                                $hoje = new DateTime();
                                $classStatus = 'bg-pendente'; $txtStatus = 'Pendente';

                                if($t['status'] == 'concluido') { $classStatus = 'bg-concluido'; $txtStatus = 'Concluído'; }
                                elseif($t['status'] == 'arquivado') { $classStatus = 'bg-arquivado'; $txtStatus = 'Arquivado'; }
                                elseif($prazo < $hoje) { $classStatus = 'bg-atrasado'; $txtStatus = 'Atrasado'; }
                                
                                $protocolo = !empty($t['protocolo']) ? $t['protocolo'] : $t['id'];
                            ?>
                            <tr>
                                <td class="ps-4 fw-bold text-secondary">#<?= $protocolo ?></td>
                                <td style="position: relative;"> <div class="tarefa-item">
                                        <div class="fw-bold text-dark text-truncate titulo-com-tooltip" style="max-width: 250px;">
                                            <?= htmlspecialchars($t['titulo']) ?>
                                        </div>
                                        <div class="custom-tooltip">
                                            <span class="tooltip-titulo"><?= htmlspecialchars($t['titulo']) ?></span>
                                            <span class="tooltip-desc"><?= nl2br(htmlspecialchars($t['descricao'])) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2" style="width: 25px; height: 25px; font-size: 0.7rem;">
                                            <?= substr($t['responsavel_nome'], 0, 1) ?>
                                        </div>
                                        <?= htmlspecialchars($t['responsavel_nome']) ?>
                                    </div>
                                </td>
                                <td><div class="small text-muted"><?= htmlspecialchars($t['criador_nome']) ?></div></td>
                                <td><?= $criacao->format('d/m/Y H:i') ?></td>
                                <td><span class="badge-status <?= $classStatus ?>"><?= $txtStatus ?></span></td>
                                <td class="text-end pe-4">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border-0" type="button" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                            <li><a class="dropdown-item small" href="../detalhes_tarefa.php?id=<?= $t['id'] ?>" target="_blank"><i class="fa-solid fa-eye me-2 text-primary"></i>Ver Detalhes</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('ATENÇÃO: Isso apagará a tarefa, histórico e anexos permanentemente. Confirmar?');">
                                                    <input type="hidden" name="acao" value="excluir_tarefa">
                                                    <input type="hidden" name="id_tarefa" value="<?= $t['id'] ?>">
                                                    <button type="submit" class="dropdown-item small text-danger fw-bold"><i class="fa-solid fa-trash me-2"></i>Excluir Definitivamente</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top-0 py-3 text-muted small text-center">
                Exibindo os últimos 100 registros correspondentes ao filtro.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include '../chat/chat_widget.php'; ?>
</body>
</html>