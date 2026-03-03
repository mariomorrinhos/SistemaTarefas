<?php
// eventos/index.php
session_start();
require_once '../config/database/conexao.php';
setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'portuguese');

// 1. SEGURANÇA
if (!isset($_SESSION['usuario_id'])) { header("Location: ../index.php"); exit; }

$usuario_id = $_SESSION['usuario_id'];
$msg = "";
$erro = "";

// Configuração do Mês/Ano
$mesAtual = isset($_GET['mes']) ? intval($_GET['mes']) : date('m');
$anoAtual = isset($_GET['ano']) ? intval($_GET['ano']) : date('Y');

// Navegação
$mesAnt = $mesAtual - 1; $anoAnt = $anoAtual;
if ($mesAnt < 1) { $mesAnt = 12; $anoAnt--; }
$mesProx = $mesAtual + 1; $anoProx = $anoAtual;
if ($mesProx > 12) { $mesProx = 1; $anoProx++; }

// -------------------------------------------------------------------------
// 2. PROCESSAR FORMULÁRIO
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['acao']) && $_POST['acao'] == 'criar_evento') {
        $titulo = trim($_POST['titulo']);
        $data = $_POST['data_evento'];
        $hora = $_POST['hora_evento'];
        $descricao = trim($_POST['descricao']);
        $inicio = $data . ' ' . $hora;

        if (!empty($titulo) && !empty($data) && !empty($hora)) {
            $stmt = $pdo->prepare("INSERT INTO eventos (usuario_id, titulo, inicio, descricao) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$usuario_id, $titulo, $inicio, $descricao])) {
                $msg = "Evento criado!";
            } else { $erro = "Erro ao salvar."; }
        } else { $erro = "Preencha todos os campos obrigatórios."; }
    }

    if (isset($_POST['acao']) && $_POST['acao'] == 'excluir_evento') {
        $id = intval($_POST['id_evento']);
        $pdo->prepare("DELETE FROM eventos WHERE id = ? AND usuario_id = ?")->execute([$id, $usuario_id]);
        $msg = "Evento removido.";
    }
}

// -------------------------------------------------------------------------
// 3. BUSCAR DADOS UNIFICADOS (TAREFAS + EVENTOS)
// -------------------------------------------------------------------------
// Buscar TAREFAS
$stmtT = $pdo->prepare("SELECT id, titulo, prazo as data_hora, status, 'tarefa' as tipo, descricao FROM tarefas 
                        WHERE usuario_id = ? AND MONTH(prazo) = ? AND YEAR(prazo) = ? AND status != 'arquivado'");
$stmtT->execute([$usuario_id, $mesAtual, $anoAtual]);
$tarefas = $stmtT->fetchAll(PDO::FETCH_ASSOC);

// Buscar EVENTOS
$stmtE = $pdo->prepare("SELECT id, titulo, inicio as data_hora, 'evento' as status, 'evento' as tipo, descricao FROM eventos 
                        WHERE usuario_id = ? AND MONTH(inicio) = ? AND YEAR(inicio) = ?");
$stmtE->execute([$usuario_id, $mesAtual, $anoAtual]);
$eventos = $stmtE->fetchAll(PDO::FETCH_ASSOC);

// Mesclar e Ordenar
$agenda = array_merge($tarefas, $eventos);
usort($agenda, function($a, $b) { return strtotime($a['data_hora']) - strtotime($b['data_hora']); });

// Agrupar por dia para o calendário
$dadosDia = [];
foreach ($agenda as $item) {
    $d = intval(date('d', strtotime($item['data_hora'])));
    $dadosDia[$d][] = $item;
}

// Próximos itens (Painel Lateral - Geral, não só do mês)
$stmtProx = $pdo->prepare("
    (SELECT id, titulo, prazo as data_hora, 'tarefa' as tipo FROM tarefas WHERE usuario_id = ? AND prazo >= NOW() AND status != 'concluido' AND status != 'arquivado' LIMIT 5)
    UNION
    (SELECT id, titulo, inicio as data_hora, 'evento' as tipo FROM eventos WHERE usuario_id = ? AND inicio >= NOW() LIMIT 5)
    ORDER BY data_hora ASC LIMIT 6
");
$stmtProx->execute([$usuario_id, $usuario_id]);
$proximos = $stmtProx->fetchAll(PDO::FETCH_ASSOC);

// Config Calendar
$numDias = cal_days_in_month(CAL_GREGORIAN, $mesAtual, $anoAtual);
$diaSemanaInicio = date('w', strtotime("$anoAtual-$mesAtual-01"));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda Inteligente - Atlas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');
        
        :root {
            --bg-body: #f0f2f5;
            --color-event: #8b5cf6; /* Roxo */
            --color-task-pending: #f59e0b; /* Laranja */
            --color-task-done: #10b981; /* Verde */
            --glass-bg: rgba(255, 255, 255, 0.95);
        }

        body { background-color: var(--bg-body); font-family: 'Inter', sans-serif; color: #1e293b; min-height: 100vh; }
        
        /* Navbar Glass */
        .navbar-glass { background: var(--glass-bg); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(0,0,0,0.05); box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
        .brand-text { font-weight: 800; letter-spacing: -0.5px; color: #0f172a; }

        /* Container Principal */
        .main-wrapper { max-width: 1400px; margin: 0 auto; padding: 20px; margin-top: 70px; }

        /* Card Estilizado */
        .app-card { background: white; border-radius: 24px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.05); padding: 24px; border: 1px solid #f1f5f9; height: 100%; }

        /* Header do Calendário */
        .cal-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; }
        .cal-month-title { font-size: 1.5rem; font-weight: 800; color: #334155; text-transform: capitalize; }
        .btn-nav { width: 40px; height: 40px; border-radius: 12px; border: 1px solid #e2e8f0; background: white; color: #64748b; transition: all 0.2s; }
        .btn-nav:hover { background: #f8fafc; color: #0f172a; border-color: #cbd5e1; }

        /* Grid do Calendário */
        .calendar-grid { 
            display: grid; 
            grid-template-columns: repeat(7, 1fr); 
            gap: 12px; 
        }
        
        .day-header { text-align: center; font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; padding-bottom: 10px; }

        .day-cell {
            background: white; border-radius: 16px; min-height: 110px; padding: 10px;
            border: 1px solid #f1f5f9; position: relative; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .day-cell:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-color: #e2e8f0; z-index: 2; }
        
        .day-number { font-weight: 700; font-size: 1rem; color: #64748b; margin-bottom: 5px; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
        
        .is-today { background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border: 1px solid #bae6fd; }
        .is-today .day-number { background: #0ea5e9; color: white; box-shadow: 0 4px 10px rgba(14, 165, 233, 0.3); }

        /* Pontos Indicadores (Dots) */
        .dots-container { display: flex; gap: 4px; flex-wrap: wrap; }
        .dot { height: 6px; width: 6px; border-radius: 50%; }
        .dot-task { background-color: var(--color-task-pending); }
        .dot-task-ok { background-color: var(--color-task-done); }
        .dot-event { background-color: var(--color-event); width: 16px; border-radius: 4px; } /* Evento é um traço */

        /* Painel Lateral */
        .side-title { font-weight: 800; font-size: 0.9rem; color: #475569; text-transform: uppercase; margin-bottom: 20px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
        
        .next-item { display: flex; align-items: center; padding: 12px; margin-bottom: 10px; border-radius: 12px; background: #f8fafc; transition: all 0.2s; border: 1px solid transparent; text-decoration: none; color: inherit; }
        .next-item:hover { background: white; border-color: #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .date-box { 
            display: flex; flex-direction: column; align-items: center; justify-content: center; 
            width: 45px; height: 45px; border-radius: 10px; background: white; 
            font-weight: 700; margin-right: 12px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); font-size: 0.75rem; 
        }
        .event-purple .date-box { color: var(--color-event); }
        .task-orange .date-box { color: var(--color-task-pending); }
        
        .item-content h6 { font-size: 0.85rem; font-weight: 700; margin: 0; line-height: 1.2; }
        .item-content span { font-size: 0.7rem; color: #64748b; }

        /* Modal do Dia */
        .modal-day-header { background: linear-gradient(135deg, #1e293b, #0f172a); color: white; border-radius: 24px 24px 0 0; padding: 20px; }
        .timeline-box { position: relative; padding-left: 20px; border-left: 2px solid #e2e8f0; margin-top: 15px; }
        .timeline-row { position: relative; margin-bottom: 20px; }
        .timeline-dot { position: absolute; left: -25px; top: 0; width: 12px; height: 12px; border-radius: 50%; border: 2px solid white; box-shadow: 0 0 0 1px #cbd5e1; }
        .timeline-time { font-size: 0.75rem; font-weight: 700; color: #94a3b8; margin-bottom: 2px; }
        .timeline-card { background: #f8fafc; padding: 10px 15px; border-radius: 10px; }
    </style>
</head>
<body>

    <nav class="navbar navbar-glass fixed-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center" href="../dashboard.php">
                <i class="fa-solid fa-arrow-left-long me-3 text-secondary"></i>
                <span class="brand-text">AGENDA INTELIGENTE</span>
            </a>
            <div class="d-flex align-items-center gap-3">
                <div class="d-none d-md-flex align-items-center gap-3 small text-muted fw-bold">
                    <span class="d-flex align-items-center"><span class="dot dot-task me-1"></span> Tarefa</span>
                    <span class="d-flex align-items-center"><span class="dot dot-task-ok me-1"></span> Feita</span>
                    <span class="d-flex align-items-center"><span class="dot dot-event me-1"></span> Evento</span>
                </div>
                <button class="btn btn-primary rounded-pill fw-bold px-4" style="background: var(--color-event); border: none;" onclick="abrirModalHoje()">
                    <i class="fa-solid fa-plus me-1"></i> Novo
                </button>
            </div>
        </div>
    </nav>

    <div class="main-wrapper">
        <?php if($msg): ?><div class="alert alert-success rounded-4 border-0 shadow-sm mb-4"><i class="fa-solid fa-check-circle me-2"></i> <?= $msg ?></div><?php endif; ?>
        <?php if($erro): ?><div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?= $erro ?></div><?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8 col-xl-9">
                <div class="app-card">
                    <div class="cal-nav">
                        <a href="?mes=<?= $mesAnt ?>&ano=<?= $anoAnt ?>" class="btn btn-nav d-flex align-items-center justify-content-center"><i class="fa-solid fa-chevron-left"></i></a>
                        <div class="cal-month-title"><?= strftime('%B %Y', mktime(0, 0, 0, $mesAtual, 1, $anoAtual)) ?></div>
                        <a href="?mes=<?= $mesProx ?>&ano=<?= $anoProx ?>" class="btn btn-nav d-flex align-items-center justify-content-center"><i class="fa-solid fa-chevron-right"></i></a>
                    </div>

                    <div class="calendar-grid">
                        <div class="day-header">Dom</div><div class="day-header">Seg</div><div class="day-header">Ter</div>
                        <div class="day-header">Qua</div><div class="day-header">Qui</div><div class="day-header">Sex</div><div class="day-header">Sáb</div>

                        <?php
                        // Dias vazios
                        for($i=0; $i < $diaSemanaInicio; $i++) { echo '<div style="background:transparent;"></div>'; }

                        // Dias do Mês
                        for($dia=1; $dia <= $numDias; $dia++) {
                            $ehHoje = ($dia == date('d') && $mesAtual == date('m') && $anoAtual == date('Y'));
                            $classeHoje = $ehHoje ? 'is-today' : '';
                            
                            // Prepara dados para JSON (passar para o JS)
                            $itensDoDia = isset($dadosDia[$dia]) ? $dadosDia[$dia] : [];
                            $jsonDia = htmlspecialchars(json_encode($itensDoDia), ENT_QUOTES, 'UTF-8');
                            
                            echo "<div class='day-cell $classeHoje' onclick='abrirDia($dia, $jsonDia)'>";
                            echo "<div class='d-flex justify-content-between align-items-start'>";
                            echo "<span class='day-number'>$dia</span>";
                            if (!empty($itensDoDia)) echo "<span class='badge bg-light text-secondary rounded-pill border' style='font-size:0.6rem;'>".count($itensDoDia)."</span>";
                            echo "</div>";
                            
                            echo "<div class='dots-container mt-2'>";
                            // Mostra até 6 bolinhas/tracinhos para não estourar
                            $count = 0;
                            foreach($itensDoDia as $item) {
                                if($count >= 6) break;
                                if($item['tipo'] == 'evento') echo "<div class='dot dot-event'></div>";
                                elseif($item['status'] == 'concluido') echo "<div class='dot dot-task-ok'></div>";
                                else echo "<div class='dot dot-task'></div>";
                                $count++;
                            }
                            echo "</div>";
                            
                            echo "</div>";
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-xl-3">
                <div class="app-card">
                    <div class="side-title">Próximos Compromissos</div>
                    
                    <?php if(count($proximos) == 0): ?>
                        <div class="text-center py-5 text-muted small">Nada agendado para os próximos dias.</div>
                    <?php else: ?>
                        <?php foreach($proximos as $p): 
                            $dt = new DateTime($p['data_hora']);
                            $classeCor = ($p['tipo'] == 'evento') ? 'event-purple' : 'task-orange';
                        ?>
                            <a href="<?= $p['tipo'] == 'tarefa' ? '../detalhes_tarefa.php?id='.$p['id'] : '#' ?>" class="next-item <?= $classeCor ?>">
                                <div class="date-box">
                                    <span><?= $dt->format('d') ?></span>
                                    <span style="font-size:0.6rem; text-transform:uppercase;"><?= substr(strftime('%b', $dt->getTimestamp()), 0, 3) ?></span>
                                </div>
                                <div class="item-content">
                                    <h6 class="text-dark"><?= htmlspecialchars($p['titulo']) ?></h6>
                                    <span><?= $dt->format('H:i') ?> • <?= ucfirst($p['tipo']) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div class="mt-4 p-3 rounded-4 bg-light border text-center">
                        <small class="d-block text-muted mb-2 fw-bold">Gestão Rápida</small>
                        <button class="btn btn-outline-dark btn-sm rounded-pill w-100 mb-2" onclick="location.href='../criar_tarefa.php'">Nova Tarefa</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalDia" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 rounded-4 shadow-lg">
                <div class="modal-day-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="fw-bold m-0" id="modalDiaNumero">00</h2>
                            <span class="opacity-75 text-uppercase small" id="modalDiaExtenso">Mês Ano</span>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-0">
                    <ul class="nav nav-tabs nav-fill" id="myTab" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active fw-bold text-dark" id="agenda-tab" data-bs-toggle="tab" data-bs-target="#agenda-pane">Agenda do Dia</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link fw-bold text-dark" id="novo-tab" data-bs-toggle="tab" data-bs-target="#novo-pane">Novo Evento</button>
                        </li>
                    </ul>

                    <div class="tab-content p-4">
                        <div class="tab-pane fade show active" id="agenda-pane">
                            <div id="listaEventosContainer"></div>
                        </div>

                        <div class="tab-pane fade" id="novo-pane">
                            <form method="POST">
                                <input type="hidden" name="acao" value="criar_evento">
                                <input type="hidden" name="data_evento" id="inputDataModal">
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Título</label>
                                    <input type="text" name="titulo" class="form-control rounded-3 bg-light" placeholder="Ex: Reunião de Pauta" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Horário</label>
                                    <input type="time" name="hora_evento" class="form-control rounded-3 bg-light" required value="09:00">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Descrição</label>
                                    <textarea name="descricao" class="form-control rounded-3 bg-light" rows="3"></textarea>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary rounded-pill fw-bold" style="background: var(--color-event); border:none;">Salvar na Agenda</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const mesAtual = <?= $mesAtual ?>;
        const anoAtual = <?= $anoAtual ?>;
        const nomesMeses = ["", "Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];

        function abrirModalHoje() {
            const hoje = new Date();
            abrirDia(hoje.getDate(), []);
            // Troca para a aba de novo evento automaticamente
            const triggerEl = document.querySelector('#myTab button[data-bs-target="#novo-pane"]');
            bootstrap.Tab.getInstance(triggerEl).show();
        }

        function abrirDia(dia, itens) {
            // 1. Atualizar Cabeçalho do Modal
            document.getElementById('modalDiaNumero').innerText = dia;
            document.getElementById('modalDiaExtenso').innerText = nomesMeses[mesAtual] + ' ' + anoAtual;
            
            // 2. Atualizar input hidden do formulário (YYYY-MM-DD)
            const dataFmt = `${anoAtual}-${String(mesAtual).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
            document.getElementById('inputDataModal').value = dataFmt;

            // 3. Renderizar Lista
            const container = document.getElementById('listaEventosContainer');
            container.innerHTML = '';

            if (itens.length === 0) {
                container.innerHTML = '<div class="text-center py-4 text-muted opacity-50"><i class="fa-regular fa-calendar-xmark fa-3x mb-2"></i><p>Nada agendado para hoje.</p></div>';
            } else {
                let html = '<div class="timeline-box">';
                itens.forEach(item => {
                    const hora = item.data_hora.split(' ')[1].substring(0, 5);
                    const isTask = item.tipo === 'tarefa';
                    const color = isTask ? (item.status === 'concluido' ? '#10b981' : '#f59e0b') : '#8b5cf6';
                    const icon = isTask ? (item.status === 'concluido' ? 'fa-check-circle' : 'fa-clipboard-check') : 'fa-star';
                    const link = isTask ? `../detalhes_tarefa.php?id=${item.id}` : '#';
                    
                    // Botão excluir se for evento
                    let btnDelete = '';
                    if (!isTask) {
                        btnDelete = `
                        <form method="POST" class="d-inline ms-2">
                            <input type="hidden" name="acao" value="excluir_evento">
                            <input type="hidden" name="id_evento" value="${item.id}">
                            <button class="btn btn-link p-0 text-danger" style="font-size:0.8rem;" onclick="return confirm('Excluir?')"><i class="fa-solid fa-trash"></i></button>
                        </form>`;
                    }

                    html += `
                    <div class="timeline-row">
                        <div class="timeline-dot" style="background-color: ${color}; border-color: ${color}; box-shadow: 0 0 0 3px rgba(255,255,255,0.8);"></div>
                        <div class="timeline-time">${hora}</div>
                        <div class="timeline-card shadow-sm border-0 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold mb-1 text-dark" style="font-size:0.9rem;">
                                    <i class="fa-solid ${icon} me-1" style="color:${color}"></i> ${item.titulo}
                                </h6>
                                <p class="mb-0 text-muted small">${item.descricao ? item.descricao.substring(0, 50) : ''}</p>
                            </div>
                            <div class="d-flex align-items-center">
                                ${isTask ? `<a href="${link}" class="btn btn-sm btn-light border"><i class="fa-solid fa-arrow-right"></i></a>` : ''}
                                ${btnDelete}
                            </div>
                        </div>
                    </div>`;
                });
                html += '</div>';
                container.innerHTML = html;
            }

            // Resetar aba para "Agenda"
            const tabAgenda = new bootstrap.Tab(document.querySelector('#agenda-tab'));
            tabAgenda.show();

            // Abrir Modal
            var myModal = new bootstrap.Modal(document.getElementById('modalDia'));
            myModal.show();
        }
    </script>
</body>
</html>