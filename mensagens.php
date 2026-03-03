<?php
// mensagens.php
session_start();
ini_set('display_errors', 0); 
require_once 'config/database/conexao.php';

setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'pt_BR.utf-8', 'portuguese');
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$nivel_usuario = $_SESSION['usuario_nivel'];
$usuario_id = $_SESSION['usuario_id'];
$msg_feedback = "";

// 1. PUBLICAR MENSAGEM (MURAL)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['postar_msg'])) {
    if ($nivel_usuario <= 4) {
        $titulo = trim($_POST['titulo']);
        $conteudo = trim($_POST['conteudo']);
        $tipo = $_POST['tipo'];

        if (!empty($titulo) && !empty($conteudo)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO mensagens (titulo, conteudo, tipo, criado_por) VALUES (?, ?, ?, ?)");
                $stmt->execute([$titulo, $conteudo, $tipo, $usuario_id]);
                $msg_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO notificacoes (usuario_id, mensagem_id, lida) SELECT id, ?, 0 FROM usuarios WHERE ativo = 1")->execute([$msg_id]);
                $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE mensagem_id = ? AND usuario_id = ?")->execute([$msg_id, $usuario_id]);
                $msg_feedback = "Aviso publicado no mural!";
            } catch (PDOException $e) { /* Erro */ }
        }
    }
}

// 2. EXCLUIR MENSAGEM ÚNICA (MURAL - ADMIN)
// *Atenção:* Isso apaga a mensagem original para TODOS
if (isset($_GET['excluir_mural']) && $nivel_usuario <= 2) {
    $id_msg = intval($_GET['excluir_mural']);
    
    // Deleta as notificações atreladas a ela
    $pdo->prepare("DELETE FROM notificacoes WHERE mensagem_id = ?")->execute([$id_msg]);
    // Deleta a mensagem matriz
    $pdo->prepare("DELETE FROM mensagens WHERE id = ?")->execute([$id_msg]);
    
    header("Location: mensagens.php");
    exit;
}

// 3. EXCLUIR NOTIFICAÇÃO ÚNICA (SISTEMA - USUÁRIO)
// Limpa o aviso apenas para o usuário logado
if (isset($_GET['limpar_notif'])) {
    $id_notif = intval($_GET['limpar_notif']);
    $pdo->prepare("DELETE FROM notificacoes WHERE id = ? AND usuario_id = ?")->execute([$id_notif, $usuario_id]);
    header("Location: mensagens.php");
    exit;
}

// 4. LIMPAR TODOS OS AVISOS DE SISTEMA (DO USUÁRIO)
if (isset($_GET['limpar_sistema_todas'])) {
    // Exclui apenas as notificações que NÃO estão atreladas ao mural (mensagem_id IS NULL)
    $pdo->prepare("DELETE FROM notificacoes WHERE usuario_id = ? AND mensagem_id IS NULL")->execute([$usuario_id]);
    header("Location: mensagens.php");
    exit;
}

// 5. LIMPAR TODO O MURAL (DO USUÁRIO)
// *Atenção:* Isso não apaga a matriz. Apenas tira da visão (notificacoes) do usuário logado.
if (isset($_GET['limpar_mural_todas'])) {
    // Exclui as notificações desse usuário que SÃO do mural (mensagem_id IS NOT NULL)
    $pdo->prepare("DELETE FROM notificacoes WHERE usuario_id = ? AND mensagem_id IS NOT NULL")->execute([$usuario_id]);
    header("Location: mensagens.php");
    exit;
}

// 6. CONSULTA NOTIFICAÇÕES
$sql = "SELECT n.id as id_notif, n.lida, n.mensagem as texto_sistema, n.link, n.criado_em,
               m.id as id_msg_mural, m.titulo, m.conteudo, m.tipo, u.nome as autor_nome
        FROM notificacoes n
        LEFT JOIN mensagens m ON n.mensagem_id = m.id
        LEFT JOIN usuarios u ON m.criado_por = u.id
        WHERE n.usuario_id = ? ORDER BY n.criado_em DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute([$usuario_id]);
$todasNotificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$avisosMural = [];
$notificacoesSistema = [];
$totalNaoLidas = 0;

foreach($todasNotificacoes as $item) {
    if ($item['lida'] == 0) $totalNaoLidas++;
    if ($item['id_msg_mural']) { $avisosMural[] = $item; } else { $notificacoesSistema[] = $item; }
}

// 7. RADAR DE ATRASOS
$sqlAtrasos = ($nivel_usuario <= 4) 
    ? "SELECT t.*, u.nome as responsavel_nome FROM tarefas t JOIN usuarios u ON t.usuario_id = u.id WHERE t.status != 'concluido' AND t.prazo < NOW() ORDER BY t.prazo ASC LIMIT 10"
    : "SELECT t.*, u.nome as responsavel_nome FROM tarefas t JOIN usuarios u ON t.usuario_id = u.id WHERE t.usuario_id = ? AND t.status != 'concluido' AND t.prazo < NOW() ORDER BY t.prazo ASC LIMIT 10";
$stmt = $pdo->prepare($sqlAtrasos);
if ($nivel_usuario > 4) $stmt->execute([$usuario_id]); else $stmt->execute();
$atrasadas = $stmt->fetchAll();

function getStyle($tipo) {
    switch($tipo) {
        case 'urgente': return ['icon'=>'fa-fire', 'bg'=>'#fee2e2', 'text'=>'#dc2626', 'badge'=>'bg-danger'];
        case 'info':    return ['icon'=>'fa-circle-info', 'bg'=>'#e0f2fe', 'text'=>'#0284c7', 'badge'=>'bg-info'];
        default:        return ['icon'=>'fa-thumbtack', 'bg'=>'#fef3c7', 'text'=>'#d97706', 'badge'=>'bg-warning text-dark'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Central de Avisos - HabitaNet</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; color: #334155; padding-bottom: 80px; }
        
        .navbar-glass { background: rgba(255,255,255,0.9); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(0,0,0,0.05); }
        .navbar-brand { font-weight: 800; color: #004d26 !important; letter-spacing: -0.5px; }

        .welcome-section {
            background: linear-gradient(to right, #004d26, #0f7642); color: white; border-radius: 16px;
            padding: 2rem; margin-bottom: 2rem; box-shadow: 0 4px 15px rgba(0, 77, 38, 0.15);
            display: flex; align-items: center; justify-content: space-between;
        }
        .welcome-text h2 { font-weight: 800; margin: 0; font-size: 1.8rem; }
        .welcome-text p { margin: 0; opacity: 0.8; }
        
        .kpi-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .kpi-card { background: white; border-radius: 20px; padding: 1.5rem; box-shadow: 0 4px 20px rgba(0,0,0,0.03); display: flex; align-items: center; justify-content: space-between; transition: transform 0.2s; }
        .kpi-card:hover { transform: translateY(-5px); }
        .kpi-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .kpi-info h3 { font-weight: 800; margin: 0; color: #1f2937; font-size: 1.8rem; }
        .kpi-info p { margin: 0; color: #6b7280; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .kpi-blue { background: #eff6ff; color: #3b82f6; } .kpi-orange { background: #fff7ed; color: #ea580c; } .kpi-red { background: #fef2f2; color: #dc2626; }

        /* MURAL (ESQUERDA) */
        .msg-card { background: white; border-radius: 16px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 1.5rem; overflow: hidden; transition: all 0.2s; cursor: pointer; }
        .msg-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.06); }
        .msg-header { padding: 1.25rem; display: flex; align-items: start; gap: 15px; }
        .msg-icon { width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .msg-body { background: #f8fafc; padding: 1.5rem; border-top: 1px solid #f1f5f9; font-size: 0.95rem; line-height: 1.6; color: #475569; }
        
        /* SISTEMA (DIREITA - ESTILO CARD INDIVIDUAL) */
        .system-card-item { 
            background: white; 
            border-radius: 12px; 
            border: 1px solid #e2e8f0; 
            padding: 1.2rem; 
            margin-bottom: 1rem; 
            transition: all 0.2s; 
            position: relative;
            display: block;
            text-decoration: none;
            color: inherit;
        }
        .system-card-item:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-color: #cbd5e1; }
        .system-card-item.unread { border-left: 4px solid #10b981; background: #fcfdfd; }
        
        /* BOTÃO DE EXCLUIR DESTACADO */
        .btn-trash-card {
            position: absolute; 
            top: 10px; 
            right: 10px; 
            background: #fee2e2;
            color: #dc2626;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
            z-index: 10;
            border: 1px solid #fca5a5;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .btn-trash-card:hover { background: #dc2626; color: white; border-color: #dc2626; transform: scale(1.1); }

        /* RADAR ESTILIZADO E COLAPSÁVEL */
        .radar-card {
            background: white; border-radius: 16px; border: 1px solid #fca5a5; box-shadow: 0 4px 15px rgba(220, 38, 38, 0.1); margin-bottom: 2rem; overflow: hidden;
        }
        .radar-header-toggle {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            padding: 1.5rem; color: #991b1b; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: background 0.3s;
        }
        .radar-header-toggle:hover { background: #fca5a5; }
        .radar-header-title { font-weight: 800; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
        
        .radar-list { background: #fff1f2; }
        .radar-item { padding: 1rem 1.5rem; border-bottom: 1px dashed rgba(220, 38, 38, 0.2); color: #991b1b; display: block; text-decoration: none; transition: background 0.2s; }
        .radar-item:last-child { border-bottom: none; }
        .radar-item:hover { background: rgba(255,255,255,0.8); }
        .radar-title { font-weight: 700; font-size: 0.95rem; }
        
        .btn-post { background: white; color: #004d26; font-weight: 700; border: none; padding: 10px 25px; border-radius: 50px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); transition: 0.2s; }
        .btn-post:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.2); }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-glass fixed-top">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php"><i class="fa-solid fa-layer-group me-2"></i>HabitaNet Tarefas</a>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-dark btn-sm rounded-pill px-3 fw-bold">Voltar ao Painel</a>
            </div>
        </div>
    </nav>

    <div style="margin-top: 80px;"></div>

    <div class="container">
        
        <div class="welcome-section">
            <div class="welcome-text">
                <h2>Central de Avisos</h2>
                <p>Mural de comunicados e notificações do sistema.</p>
            </div>
            <?php if($nivel_usuario <= 4): ?>
                <button class="btn-post" data-bs-toggle="modal" data-bs-target="#modalPost">
                    <i class="fa-solid fa-pen me-2"></i>Novo Comunicado
                </button>
            <?php endif; ?>
        </div>

        <?php if($msg_feedback): ?>
            <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4"><i class="fa-solid fa-check-circle me-2"></i> <?= $msg_feedback ?></div>
        <?php endif; ?>

        <div class="kpi-container">
            <div class="kpi-card">
                <div class="kpi-info"><h3><?= count($avisosMural) ?></h3><p>Mural</p></div>
                <div class="kpi-icon kpi-blue"><i class="fa-solid fa-bullhorn"></i></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-info"><h3><?= $totalNaoLidas ?></h3><p>Não Lidas</p></div>
                <div class="kpi-icon kpi-orange"><i class="fa-solid fa-envelope"></i></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-info"><h3><?= count($atrasadas) ?></h3><p>Críticas</p></div>
                <div class="kpi-icon kpi-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
            </div>
        </div>

        <div class="row g-4">
            
            <div class="col-lg-7">
                <div class="d-flex justify-content-between align-items-center mb-3 ps-2 pe-2">
                    <h6 class="text-uppercase text-muted fw-bold small mb-0">Mural de Comunicados</h6>
                    <?php if(count($avisosMural) > 0): ?>
                        <a href="mensagens.php?limpar_mural_todas=1" class="text-muted small text-decoration-none" onclick="return confirm('Deseja ocultar todas as mensagens do mural da sua tela?')"><i class="fa-solid fa-broom me-1"></i> Limpar Mural</a>
                    <?php endif; ?>
                </div>
                
                <?php if(count($avisosMural) == 0): ?>
                    <div class="text-center py-5 opacity-50">
                        <i class="fa-regular fa-clipboard fa-3x mb-3"></i>
                        <p class="fw-bold">Nenhum comunicado no mural.</p>
                    </div>
                <?php else: ?>
                    <?php foreach($avisosMural as $m): $st = getStyle($m['tipo']); ?>
                    <div class="msg-card" onclick="lerMensagem(<?= $m['id_notif'] ?>, this)" data-bs-toggle="collapse" data-bs-target="#content-<?= $m['id_notif'] ?>">
                        <div class="msg-header">
                            <div class="msg-icon" style="background: <?= $st['bg'] ?>; color: <?= $st['text'] ?>;">
                                <i class="fa-solid <?= $st['icon'] ?>"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="d-flex align-items-center mb-1">
                                            <?php if($m['lida'] == 0): ?><span class="badge bg-danger rounded-pill me-2" id="badge-<?= $m['id_notif'] ?>">NOVO</span><?php endif; ?>
                                            <h5 class="fw-bold mb-0 text-dark" style="font-size: 1.1rem;"><?= htmlspecialchars($m['titulo']) ?></h5>
                                        </div>
                                        <div class="text-muted small">
                                            Por <strong><?= htmlspecialchars($m['autor_nome']) ?></strong> &bull; <?= date('d/m/Y \à\s H:i', strtotime($m['criado_em'])) ?>
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-chevron-down text-muted"></i>
                                </div>
                            </div>
                        </div>
                        <div class="collapse" id="content-<?= $m['id_notif'] ?>">
                            <div class="msg-body">
                                <?= nl2br(htmlspecialchars($m['conteudo'])) ?>
                                
                                <div class="mt-3 pt-3 border-top d-flex justify-content-between">
                                    <a href="mensagens.php?limpar_notif=<?= $m['id_notif'] ?>" class="text-secondary small fw-bold text-decoration-none" onclick="return confirm('Ocultar este aviso da sua lista?')">
                                        <i class="fa-regular fa-eye-slash me-1"></i> Ocultar
                                    </a>

                                    <?php if($nivel_usuario <= 2): ?>
                                    <a href="mensagens.php?excluir_mural=<?= $m['id_msg_mural'] ?>" class="text-danger small fw-bold text-decoration-none" onclick="return confirm('Excluir DEFINITIVAMENTE este aviso para TODOS os usuários?')">
                                        <i class="fa-solid fa-trash me-1"></i> Excluir para todos
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="col-lg-5">
                
                <?php if(count($atrasadas) > 0 && $nivel_usuario <= 4): ?>
                <div class="radar-card">
                    <div class="radar-header-toggle" data-bs-toggle="collapse" data-bs-target="#collapseRadar">
                        <div class="radar-header-title">
                            <i class="fa-solid fa-triangle-exclamation fa-lg"></i> 
                            Atenção: <?= count($atrasadas) ?> Pendências Críticas
                        </div>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>
                    
                    <div class="collapse radar-list" id="collapseRadar">
                        <?php foreach($atrasadas as $t): $dt = new DateTime($t['prazo']); ?>
                        <a href="detalhes_tarefa.php?id=<?= $t['id'] ?>" class="radar-item">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="radar-title text-truncate pe-2"><?= htmlspecialchars($t['titulo']) ?></span>
                                <span class="badge bg-danger rounded-pill shadow-sm"><i class="fa-regular fa-clock me-1"></i><?= $dt->format('d/m') ?></span>
                            </div>
                            <div class="small fw-normal"><i class="fa-regular fa-user me-1 opacity-75"></i> <?= $t['responsavel_nome'] ?></div>
                        </a>
                        <?php endforeach; ?>
                        <div class="p-3 text-center border-top border-danger border-opacity-25">
                            <a href="minhas_tarefas.php?status=atrasado" class="btn btn-sm btn-outline-danger rounded-pill fw-bold px-4">Ver todas as atrasadas</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-3 ps-2">
                    <h6 class="text-uppercase text-muted fw-bold small mb-0">Avisos do Sistema</h6>
                    <?php if(count($notificacoesSistema) > 0): ?>
                        <a href="mensagens.php?limpar_sistema_todas=1" class="text-muted small text-decoration-none" onclick="return confirm('Deseja limpar todos os avisos de sistema?')"><i class="fa-solid fa-broom me-1"></i> Limpar Avisos</a>
                    <?php endif; ?>
                </div>
                
                <div style="max-height: 600px; overflow-y: auto; padding-right: 5px;">
                    <?php if(count($notificacoesSistema) == 0): ?>
                        <div class="p-4 text-center text-muted small border rounded-4 bg-white">Nenhuma notificação recente.</div>
                    <?php else: ?>
                        <?php foreach($notificacoesSistema as $s): ?>
                        <div class="position-relative">
                            <a href="<?= $s['link'] ?>" class="system-card-item <?= $s['lida'] == 0 ? 'unread' : '' ?>" onclick="lerMensagem(<?= $s['id_notif'] ?>, this)">
                                <div class="d-flex gap-3">
                                    <div class="text-center" style="width: 40px;">
                                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center text-primary" style="width: 40px; height: 40px;">
                                            <i class="fa-solid fa-robot"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 pe-4"> <p class="mb-1 text-dark small fw-semibold" style="line-height: 1.4;"><?= htmlspecialchars($s['texto_sistema']) ?></p>
                                        <small class="text-muted" style="font-size: 0.75rem;"><?= date('d/m H:i', strtotime($s['criado_em'])) ?></small>
                                    </div>
                                </div>
                            </a>
                            <a href="mensagens.php?limpar_notif=<?= $s['id_notif'] ?>" class="btn-trash-card" title="Excluir Aviso" onclick="return confirm('Apagar este aviso da sua lista?')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <div class="modal fade" id="modalPost" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow-lg">
                <div class="modal-header bg-dark text-white border-0">
                    <h5 class="modal-title fw-bold">Novo Comunicado</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <form method="POST">
                        <label class="small fw-bold text-muted mb-1">Título</label>
                        <input type="text" name="titulo" class="form-control rounded-3 mb-3" required placeholder="Resumo do assunto...">
                        
                        <label class="small fw-bold text-muted mb-1">Tipo de Comunicado</label>
                        <div class="row g-2 mb-3">
                            <div class="col-4"><input type="radio" class="btn-check" name="tipo" id="t1" value="aviso" checked><label class="btn btn-outline-warning w-100 fw-bold border-0 bg-white shadow-sm py-2" for="t1">🟡 Aviso</label></div>
                            <div class="col-4"><input type="radio" class="btn-check" name="tipo" id="t2" value="info"><label class="btn btn-outline-info w-100 fw-bold border-0 bg-white shadow-sm py-2" for="t2">🔵 Info</label></div>
                            <div class="col-4"><input type="radio" class="btn-check" name="tipo" id="t3" value="urgente"><label class="btn btn-outline-danger w-100 fw-bold border-0 bg-white shadow-sm py-2" for="t3">🔴 Urgente</label></div>
                        </div>

                        <label class="small fw-bold text-muted mb-1">Conteúdo</label>
                        <textarea name="conteudo" class="form-control rounded-3 mb-4" rows="5" required placeholder="Digite a mensagem completa..."></textarea>
                        
                        <div class="d-grid">
                            <button type="submit" name="postar_msg" class="btn btn-dark rounded-pill py-2 fw-bold">Publicar Agora</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function lerMensagem(id_notif, elemento) {
            const badge = document.getElementById('badge-' + id_notif);
            if(badge) badge.remove();
            elemento.classList.remove('unread');
            const formData = new FormData();
            formData.append('id', id_notif);
            fetch('ajax_marcar_lida.php', { method: 'POST', body: formData });
        }
    </script>
    <?php include 'chat_widget.php'; ?>
</body>
</html>