<?php
// agenda.php
session_start();
require_once 'config/database/conexao.php';

// Verifica login
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$usuario_id = $_SESSION['usuario_id'];
$msg = "";
$erro = "";

// =================================================================================
// 1. AUTOMAÇÃO: CONCLUIR EVENTOS PASSADOS
// =================================================================================
// Se a data já passou e o status ainda é 'pendente', marca como 'concluido'
$pdo->query("UPDATE eventos SET status = 'concluido' WHERE inicio < NOW() AND status = 'pendente'");

// =================================================================================
// 2. PROCESSAMENTO DE AÇÕES (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CRIAR
    if (isset($_POST['acao']) && $_POST['acao'] == 'criar_evento') {
        $titulo = trim($_POST['titulo']);
        $data = $_POST['data_evento'];
        $hora = $_POST['hora_evento'];
        $descricao = trim($_POST['descricao']);
        $inicio = $data . ' ' . $hora;

        if (!empty($titulo) && !empty($data)) {
            $stmt = $pdo->prepare("INSERT INTO eventos (usuario_id, titulo, inicio, descricao, status) VALUES (?, ?, ?, ?, 'pendente')");
            if ($stmt->execute([$usuario_id, $titulo, $inicio, $descricao])) { $msg = "Evento agendado!"; } 
            else { $erro = "Erro ao salvar."; }
        }
    }

    // EDITAR
    if (isset($_POST['acao']) && $_POST['acao'] == 'editar_evento') {
        $id = intval($_POST['id_evento']);
        $titulo = trim($_POST['titulo']);
        $descricao = trim($_POST['descricao']);
        
        $stmt = $pdo->prepare("UPDATE eventos SET titulo = ?, descricao = ? WHERE id = ? AND usuario_id = ?");
        if($stmt->execute([$titulo, $descricao, $id, $usuario_id])) { $msg = "Evento atualizado."; }
    }

    // ADIAR
    if (isset($_POST['acao']) && $_POST['acao'] == 'adiar_evento') {
        $id = intval($_POST['id_evento']);
        $nova_data = $_POST['nova_data'];
        $nova_hora = $_POST['nova_hora'];
        $novo_inicio = $nova_data . ' ' . $nova_hora;

        $stmt = $pdo->prepare("UPDATE eventos SET inicio = ?, status = 'pendente' WHERE id = ? AND usuario_id = ?");
        if($stmt->execute([$novo_inicio, $id, $usuario_id])) { $msg = "Evento adiado com sucesso."; }
    }

    // CONCLUIR MANUALMENTE
    if (isset($_POST['acao']) && $_POST['acao'] == 'concluir_evento') {
        $id = intval($_POST['id_evento']);
        $stmt = $pdo->prepare("UPDATE eventos SET status = 'concluido' WHERE id = ? AND usuario_id = ?");
        if($stmt->execute([$id, $usuario_id])) { $msg = "Evento marcado como concluído."; }
    }

    // EXCLUIR
    if (isset($_POST['acao']) && $_POST['acao'] == 'excluir_evento') {
        $id = intval($_POST['id_evento']);
        $pdo->prepare("DELETE FROM eventos WHERE id = ? AND usuario_id = ?")->execute([$id, $usuario_id]);
        $msg = "Evento removido.";
    }
}

// -------------------------------------------------------------------------
// 3. BUSCAR DADOS
// -------------------------------------------------------------------------
$mesAtual = isset($_GET['mes']) ? intval($_GET['mes']) : date('m');
$anoAtual = isset($_GET['ano']) ? intval($_GET['ano']) : date('Y');
$diaSelecionado = isset($_GET['dia']) ? intval($_GET['dia']) : date('d');

$mesesPT = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
$nomeMesAtual = $mesesPT[intval($mesAtual)];

// Navegação
$antMes = $mesAtual - 1; $antAno = $anoAtual; if ($antMes < 1) { $antMes = 12; $antAno--; }
$proxMes = $mesAtual + 1; $proxAno = $anoAtual; if ($proxMes > 12) { $proxMes = 1; $proxAno++; }

$diasNoMes = cal_days_in_month(CAL_GREGORIAN, $mesAtual, $anoAtual);
$diaSemanaInicio = date('w', strtotime("$anoAtual-$mesAtual-01"));

// Dados do Mês (Para Bolinhas)
$sqlMesTarefas = "SELECT DAY(prazo) as dia, prioridade, 'tarefa' as tipo FROM tarefas WHERE usuario_id = ? AND MONTH(prazo) = ? AND YEAR(prazo) = ? AND status != 'concluido' AND status != 'arquivado'";
$stmt = $pdo->prepare($sqlMesTarefas); $stmt->execute([$usuario_id, $mesAtual, $anoAtual]); $tarefasMes = $stmt->fetchAll(PDO::FETCH_GROUP);

$sqlMesEventos = "SELECT DAY(inicio) as dia, status, 'evento' as tipo FROM eventos WHERE usuario_id = ? AND MONTH(inicio) = ? AND YEAR(inicio) = ?";
$stmtE = $pdo->prepare($sqlMesEventos); $stmtE->execute([$usuario_id, $mesAtual, $anoAtual]); $eventosMes = $stmtE->fetchAll(PDO::FETCH_GROUP);

// Dados do Dia Selecionado
$dataFull = "$anoAtual-$mesAtual-$diaSelecionado";

// Tarefas
$sqlDiaT = "SELECT id, titulo, descricao, prazo as data_hora, prioridade, status, 'tarefa' as tipo FROM tarefas WHERE usuario_id = ? AND DATE(prazo) = ? AND status != 'arquivado'";
$stmtT = $pdo->prepare($sqlDiaT); $stmtT->execute([$usuario_id, $dataFull]); $listaTarefas = $stmtT->fetchAll(PDO::FETCH_ASSOC);

// Eventos (Trazendo o status)
$sqlDiaE = "SELECT id, titulo, descricao, inicio as data_hora, status, 'evento' as tipo FROM eventos WHERE usuario_id = ? AND DATE(inicio) = ?";
$stmtE = $pdo->prepare($sqlDiaE); $stmtE->execute([$usuario_id, $dataFull]); $listaEventos = $stmtE->fetchAll(PDO::FETCH_ASSOC);

$agendaDoDia = array_merge($listaTarefas, $listaEventos);
usort($agendaDoDia, function($a, $b) { return strtotime($a['data_hora']) - strtotime($b['data_hora']); });

function getCorItem($tipo, $p) {
    if ($tipo == 'evento') return '#8b5cf6';
    switch($p) { case 'urgente': return '#dc3545'; case 'alta': return '#fd7e14'; case 'media': return '#0dcaf0'; default: return '#198754'; }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda Unificada - Atlas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #f0f2f5; font-family: 'Inter', sans-serif; min-height: 100vh; }
        .navbar-glass { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05); border-bottom: 1px solid rgba(0, 77, 38, 0.1); }
        .navbar-brand { font-weight: 800; color: #004d26 !important; }
        .calendar-card { background: white; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); padding: 20px; overflow: hidden; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; text-align: center; }
        .weekday { font-size: 0.75rem; font-weight: 700; color: #a0aec0; text-transform: uppercase; margin-bottom: 10px; }
        .day-cell { aspect-ratio: 1/1; border-radius: 16px; display: flex; flex-direction: column; align-items: center; justify-content: center; background-color: #f8f9fa; color: #4a5568; font-weight: 600; cursor: pointer; transition: all 0.2s; position: relative; text-decoration: none; border: 2px solid transparent; }
        .day-cell:hover { background-color: #e2e8f0; transform: scale(1.05); }
        .day-cell.active { background: linear-gradient(135deg, #004d26, #00703c); color: white; box-shadow: 0 5px 15px rgba(0, 77, 38, 0.3); }
        .day-cell.today { border-color: #004d26; color: #004d26; }
        .day-cell.active.today { border-color: transparent; color: white; }
        .event-dots { display: flex; gap: 3px; margin-top: 4px; flex-wrap: wrap; justify-content: center; max-width: 80%; }
        .dot { width: 6px; height: 6px; border-radius: 50%; background-color: #cbd5e0; }
        .agenda-details { margin-top: 2rem; animation: slideUp 0.4s ease-out; }
        .task-row { background: white; border-radius: 16px; padding: 1.2rem; margin-bottom: 1rem; border-left: 5px solid #ccc; box-shadow: 0 4px 10px rgba(0,0,0,0.03); display: flex; justify-content: space-between; align-items: center; transition: transform 0.2s; text-decoration: none; color: inherit; cursor: pointer; }
        .task-row:hover { transform: translateX(5px); background-color: #fcfcfc; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        
        /* Estilos do Modal de Controle */
        .modal-control-header { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; }
        .status-badge-concluido { background: #d1fae5; color: #065f46; padding: 4px 12px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; }
        .status-badge-pendente { background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; }
        
        @media print {
            body * { visibility: hidden; }
            #modalEvento, #modalEvento * { visibility: visible; }
            #modalEvento { position: absolute; left: 0; top: 0; width: 100%; height: 100%; background: white; z-index: 9999; }
            .modal-footer, .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-glass fixed-top no-print">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php"><i class="fa-solid fa-arrow-left me-2"></i> ATLAS AGENDA</a>
            <a href="criar_tarefa.php" class="btn btn-sm btn-dark rounded-pill px-3"><i class="fa-solid fa-plus me-1"></i> Novo</a>
        </div>
    </nav>

    <div style="margin-top: 90px;"></div>

    <div class="container pb-5 no-print">
        <?php if($msg): ?><div class="alert alert-success rounded-3 border-0 shadow-sm"><i class="fa-solid fa-check me-2"></i> <?= $msg ?></div><?php endif; ?>
        <?php if($erro): ?><div class="alert alert-danger rounded-3 border-0 shadow-sm"><i class="fa-solid fa-circle-exclamation me-2"></i> <?= $erro ?></div><?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="calendar-card">
                    <div class="calendar-header">
                        <a href="agenda.php?mes=<?= $antMes ?>&ano=<?= $antAno ?>" class="btn btn-light rounded-circle btn-sm shadow-sm"><i class="fa-solid fa-chevron-left"></i></a>
                        <h5 class="fw-bold mb-0 text-dark"><?= $nomeMesAtual ?> <span class="fw-light text-muted"><?= $anoAtual ?></span></h5>
                        <a href="agenda.php?mes=<?= $proxMes ?>&ano=<?= $proxAno ?>" class="btn btn-light rounded-circle btn-sm shadow-sm"><i class="fa-solid fa-chevron-right"></i></a>
                    </div>
                    <div class="calendar-grid mb-2">
                        <div class="weekday">Dom</div><div class="weekday">Seg</div><div class="weekday">Ter</div><div class="weekday">Qua</div><div class="weekday">Qui</div><div class="weekday">Sex</div><div class="weekday">Sab</div>
                    </div>
                    <div class="calendar-grid">
                        <?php
                        for ($i = 0; $i < $diaSemanaInicio; $i++) { echo "<div></div>"; }
                        for ($dia = 1; $dia <= $diasNoMes; $dia++) {
                            $isActive = ($dia == $diaSelecionado);
                            $isToday = ($dia == date('d') && $mesAtual == date('m') && $anoAtual == date('Y'));
                            $classe = "day-cell" . ($isActive ? " active" : "") . ($isToday ? " today" : "");
                            
                            echo "<a href='agenda.php?mes=$mesAtual&ano=$anoAtual&dia=$dia' class='$classe'>";
                            echo "<span>$dia</span>";
                            echo "<div class='event-dots'>";
                            if (isset($tarefasMes[$dia])) { $c=0; foreach($tarefasMes[$dia] as $t) { if($c<3) { $cor=getCorItem('tarefa',$t['prioridade']); echo "<span class='dot' style='background-color:$cor;'></span>"; } $c++; } }
                            if (isset($eventosMes[$dia])) { 
                                // Verifica se todos os eventos do dia estão concluídos
                                $allDone = true;
                                foreach($eventosMes[$dia] as $evt) { if($evt['status'] != 'concluido') $allDone = false; }
                                $corDot = $allDone ? '#d1d5db' : '#8b5cf6'; // Cinza se concluido, Roxo se pendente
                                echo "<span class='dot' style='background-color: $corDot;'></span>"; 
                            }
                            echo "</div></a>";
                        }
                        ?>
                    </div>
                    <div class="mt-4 pt-3 border-top d-flex justify-content-center gap-3 small text-muted">
                        <div><span class="legend-dot" style="background-color: #f59e0b;"></span> Tarefa</div>
                        <div><span class="legend-dot" style="background-color: #8b5cf6;"></span> Evento</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="agenda-details">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-regular fa-calendar-check me-2"></i>Agenda de <?= $diaSelecionado ?> de <?= $nomeMesAtual ?></h5>

                    <?php if (count($agendaDoDia) == 0): ?>
                        <div class="text-center py-5 bg-white rounded-4 shadow-sm opacity-75">
                            <i class="fa-solid fa-mug-hot fa-3x text-secondary mb-3 opacity-25"></i>
                            <h6 class="text-secondary">Dia livre!</h6>
                            <p class="small text-muted mb-0">Nenhuma tarefa ou evento agendado.</p>
                            <a href="criar_tarefa.php" class="btn btn-sm btn-outline-success mt-3 rounded-pill">Novo Agendamento</a>
                        </div>
                    <?php else: ?>
                        
                        <?php foreach($agendaDoDia as $item): 
                            $hora = date('H:i', strtotime($item['data_hora']));
                            $dataISO = date('Y-m-d', strtotime($item['data_hora']));
                            $tipo = $item['tipo'];
                            $status = $item['status'];
                            
                            $corBorda = getCorItem($tipo, isset($item['prioridade']) ? $item['prioridade'] : '');
                            $riscado = ($status == 'concluido') ? 'text-decoration-line-through text-muted' : 'text-dark';
                            
                            // Configuração do Link/Ação
                            if ($tipo == 'tarefa') {
                                $link = "detalhes_tarefa.php?id={$item['id']}";
                                $extraAttrs = "";
                                $badge = ($status == 'concluido') ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Feito</span>' : '<span class="badge bg-warning bg-opacity-10 text-warning rounded-pill px-3">Pendente</span>';
                            } else {
                                $link = "#";
                                // Dados para o Modal de Controle
                                $extraAttrs = "onclick='abrirModalEvento({$item['id']}, \"".addslashes($item['titulo'])."\", \"".addslashes(preg_replace( "/\r|\n/", " ", $item['descricao']))."\", \"$dataISO\", \"$hora\", \"$status\")'";
                                $badge = ($status == 'concluido') ? '<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3"><i class="fa-solid fa-check me-1"></i> Concluído</span>' : '<span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3"><i class="fa-solid fa-gear me-1"></i> Gerenciar</span>';
                            }
                        ?>
                        <a href="<?= $link ?>" <?= $extraAttrs ?> class="task-row" style="border-left-color: <?= $corBorda ?>;">
                            <div class="d-flex align-items-center">
                                <div class="me-3 text-center" style="min-width: 50px;">
                                    <span class="d-block fw-bold fs-5 text-secondary"><?= $hora ?></span>
                                    <small class="text-uppercase" style="font-size: 0.6rem; color: <?= $corBorda ?>;">
                                        <?= ($tipo == 'evento') ? 'EVENTO' : $item['prioridade'] ?>
                                    </small>
                                </div>
                                <div>
                                    <h6 class="mb-1 fw-bold <?= $riscado ?>">
                                        <?php if($tipo == 'evento'): ?><i class="fa-regular fa-star me-1 text-warning"></i><?php endif; ?>
                                        <?= htmlspecialchars($item['titulo']) ?>
                                    </h6>
                                    <small class="text-muted d-block text-truncate" style="max-width: 350px;">
                                        <?= $item['descricao'] ? htmlspecialchars($item['descricao']) : 'Sem descrição' ?>
                                    </small>
                                </div>
                            </div>
                            <div><?= $badge ?></div>
                        </a>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEvento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header modal-control-header border-0">
                    <h5 class="modal-title fw-bold"><i class="fa-regular fa-calendar-check me-2"></i>Gerenciar Evento</h5>
                    <button type="button" class="btn-close btn-close-white no-print" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    
                    <div id="viewMode">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h4 class="fw-bold text-dark mb-0" id="evtTitulo">Titulo</h4>
                            <span id="evtStatusBadge"></span>
                        </div>
                        <div class="d-flex gap-3 mb-4 text-muted small">
                            <span><i class="fa-regular fa-clock me-1"></i> <span id="evtHora">00:00</span></span>
                            <span><i class="fa-regular fa-calendar me-1"></i> <span id="evtData">00/00/0000</span></span>
                        </div>
                        <div class="p-3 bg-light rounded-3 border mb-4">
                            <p class="mb-0 text-secondary" id="evtDesc" style="white-space: pre-line;">...</p>
                        </div>

                        <div class="d-grid gap-2 no-print">
                            <div class="row g-2">
                                <div class="col-6">
                                    <form method="POST">
                                        <input type="hidden" name="acao" value="concluir_evento">
                                        <input type="hidden" name="id_evento" id="idConcluir">
                                        <button type="submit" class="btn btn-success w-100 fw-bold"><i class="fa-solid fa-check me-1"></i> Concluir</button>
                                    </form>
                                </div>
                                <div class="col-6">
                                    <button type="button" class="btn btn-warning w-100 fw-bold text-white" onclick="toggleEditMode()"><i class="fa-solid fa-pen me-1"></i> Editar/Adiar</button>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-dark w-100" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Imprimir</button>
                            <form method="POST" onsubmit="return confirm('Tem certeza? Isso não pode ser desfeito.')">
                                <input type="hidden" name="acao" value="excluir_evento">
                                <input type="hidden" name="id_evento" id="idExcluir">
                                <button type="submit" class="btn btn-link text-danger w-100 text-decoration-none btn-sm">Excluir Evento</button>
                            </form>
                        </div>
                    </div>

                    <div id="editMode" style="display:none;">
                        <h6 class="fw-bold mb-3 text-secondary">Editar ou Adiar Evento</h6>
                        <form method="POST">
                            <input type="hidden" name="acao" value="editar_evento"> <input type="hidden" name="id_evento" id="idEditar">
                            
                            <div class="mb-3">
                                <label class="small fw-bold text-muted">Título</label>
                                <input type="text" name="titulo" id="inpTitulo" class="form-control rounded-3">
                            </div>
                            
                            <div class="row g-2 mb-3">
                                <div class="col-7">
                                    <label class="small fw-bold text-muted">Data (Adiar)</label>
                                    <input type="date" name="nova_data" id="inpData" class="form-control rounded-3" onchange="enableAdiar()">
                                </div>
                                <div class="col-5">
                                    <label class="small fw-bold text-muted">Hora</label>
                                    <input type="time" name="nova_hora" id="inpHora" class="form-control rounded-3" onchange="enableAdiar()">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="small fw-bold text-muted">Descrição</label>
                                <textarea name="descricao" id="inpDesc" class="form-control rounded-3" rows="3"></textarea>
                            </div>

                            <div class="d-grid gap-2" id="btnGroupEdit">
                                <button type="submit" class="btn btn-primary rounded-pill fw-bold">Salvar Alterações</button>
                            </div>
                            <div class="text-center mt-3">
                                <button type="button" class="btn btn-link text-muted text-decoration-none btn-sm" onclick="toggleEditMode()">Cancelar</button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var myModal = new bootstrap.Modal(document.getElementById('modalEvento'));

        function abrirModalEvento(id, titulo, desc, data, hora, status) {
            // Resetar visualização
            document.getElementById('viewMode').style.display = 'block';
            document.getElementById('editMode').style.display = 'none';

            // Popular View
            document.getElementById('evtTitulo').innerText = titulo;
            document.getElementById('evtDesc').innerText = desc;
            document.getElementById('evtData').innerText = data.split('-').reverse().join('/');
            document.getElementById('evtHora').innerText = hora;
            
            // Popular IDs nos forms
            document.getElementById('idConcluir').value = id;
            document.getElementById('idExcluir').value = id;
            document.getElementById('idEditar').value = id;

            // Popular Inputs de Edição
            document.getElementById('inpTitulo').value = titulo;
            document.getElementById('inpDesc').value = desc;
            document.getElementById('inpData').value = data;
            document.getElementById('inpHora').value = hora;

            // Status Badge
            const badgeSpan = document.getElementById('evtStatusBadge');
            if(status === 'concluido') {
                badgeSpan.className = 'status-badge-concluido';
                badgeSpan.innerText = 'Concluído';
            } else {
                badgeSpan.className = 'status-badge-pendente';
                badgeSpan.innerText = 'Pendente';
            }

            myModal.show();
        }

        function toggleEditMode() {
            const view = document.getElementById('viewMode');
            const edit = document.getElementById('editMode');
            if(view.style.display === 'none') {
                view.style.display = 'block';
                edit.style.display = 'none';
            } else {
                view.style.display = 'none';
                edit.style.display = 'block';
            }
        }

        function enableAdiar() {
            // Se o usuário mexer na data/hora, mudamos o hidden input de ação para 'adiar_evento'
            const btnGroup = document.getElementById('btnGroupEdit');
            btnGroup.innerHTML = `
                <input type="hidden" name="acao" value="adiar_evento">
                <button type="submit" class="btn btn-warning text-white rounded-pill fw-bold"><i class="fa-regular fa-calendar-plus me-1"></i> Confirmar Adiamento</button>
            `;
        }
    </script>
    <?php include 'chat_widget.php'; ?>
</body>
</html>