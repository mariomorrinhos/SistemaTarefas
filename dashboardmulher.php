<?php
// dashboardmulher.php
session_start();
require_once 'config/database/conexao.php';

// 1. SEGURANÇA
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit; }

// Verifica troca de senha
$stmtCheck = $pdo->prepare("SELECT trocar_senha FROM usuarios WHERE id = ?");
$stmtCheck->execute([$_SESSION['usuario_id']]);
if ($stmtCheck->fetchColumn() == 1) { header("Location: nova_senha.php"); exit; }

date_default_timezone_set('America/Sao_Paulo');
setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'portuguese');

$usuario_id = $_SESSION['usuario_id'];
$nomeUsuario  = $_SESSION['usuario_nome'];
$nivelUsuario = $_SESSION['usuario_nivel'];
$primeiroNome = explode(' ', $nomeUsuario)[0];
$cargos = [ 1=>'Super Admin', 2=>'Administrador', 3=>'Secretário', 4=>'Gerente', 5=>'Fiscal', 6=>'Administrativo', 7=>'Público' ];
$cargoNome = $cargos[$nivelUsuario] ?? 'Usuário';

// -------------------------------------------------------------------------
// 2. FILTROS
// -------------------------------------------------------------------------
$anoFiltro = isset($_GET['ano_chart']) ? intval($_GET['ano_chart']) : date('Y');
$anoKpi = isset($_GET['ano_kpi']) ? intval($_GET['ano_kpi']) : date('Y');

$sqlAnos = "SELECT DISTINCT YEAR(prazo) as ano FROM tarefas WHERE usuario_id = ? ORDER BY ano DESC";
$stmtAnos = $pdo->prepare($sqlAnos); $stmtAnos->execute([$usuario_id]);
$anosDisponiveis = $stmtAnos->fetchAll(PDO::FETCH_COLUMN);
if(empty($anosDisponiveis)) $anosDisponiveis = [date('Y')];

// -------------------------------------------------------------------------
// 3. CONSULTAS INICIAIS
// -------------------------------------------------------------------------
$anoInicio = strtotime(date('Y-01-01')); $anoFim = strtotime(date('Y-12-31 23:59:59'));
$progressoAno = round(((time() - $anoInicio) / ($anoFim - $anoInicio)) * 100, 1);
$dataAtualFormatada = date('d/m/Y');

$stmtLogin = $pdo->prepare("SELECT data_login FROM historico_logins WHERE usuario_id = ? ORDER BY data_login DESC LIMIT 1 OFFSET 1");
$stmtLogin->execute([$usuario_id]); $dataUltimoLogin = $stmtLogin->fetchColumn();
$textoLogin = $dataUltimoLogin ? date('d/m \à\s H:i', strtotime($dataUltimoLogin)) : "Primeiro acesso";

// CONSULTA DE KPIS
$mesAtual = date('m');
$anoAtual = date('Y');
$hojeYmd = date('Y-m-d');

// Consulta Estatísticas
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

// CONSULTA PARA AGENDA (Dias com tarefas pendentes no mês atual)
$stmtCalendar = $pdo->prepare("SELECT DISTINCT DAY(prazo) as dia FROM tarefas WHERE usuario_id = ? AND MONTH(prazo) = ? AND YEAR(prazo) = ? AND status != 'concluido'");
$stmtCalendar->execute([$usuario_id, $mesAtual, $anoAtual]);
$diasComTarefas = $stmtCalendar->fetchAll(PDO::FETCH_COLUMN);

// Configurações do Calendário
$numDiasMes = date('t');
$primeiroDiaSemana = date('w', strtotime("$anoAtual-$mesAtual-01")); 

$eficiencia = ($stats['total_geral'] > 0) ? round(($stats['total_concluidas'] / $stats['total_geral']) * 100) : 0;

// GRÁFICO: TAREFAS CRIADAS POR MÊS
$stmtCriadas = $pdo->prepare("SELECT MONTH(criado_em) as mes, COUNT(*) as total FROM tarefas WHERE usuario_id = ? AND YEAR(criado_em) = ? GROUP BY MONTH(criado_em)");
$stmtCriadas->execute([$usuario_id, $anoFiltro]); 
$dadosCriadas = $stmtCriadas->fetchAll(PDO::FETCH_KEY_PAIR);
$dataLineCriadas = array_fill(0, 12, 0); 
foreach($dadosCriadas as $mes => $qtd) { $dataLineCriadas[$mes - 1] = $qtd; }

// GRÁFICO: TAREFAS CONCLUÍDAS POR MÊS
$stmtConcluidas = $pdo->prepare("
    SELECT MONTH(COALESCE((SELECT MAX(data_acao) FROM historico_tarefas WHERE tarefa_id = t.id AND acao = 'status' AND descricao LIKE '%Concluiu%'), t.prazo)) as mes, 
           COUNT(*) as total 
    FROM tarefas t 
    WHERE t.usuario_id = ? AND t.status = 'concluido' AND YEAR(COALESCE((SELECT MAX(data_acao) FROM historico_tarefas WHERE tarefa_id = t.id AND acao = 'status' AND descricao LIKE '%Concluiu%'), t.prazo)) = ? 
    GROUP BY mes
");
$stmtConcluidas->execute([$usuario_id, $anoFiltro]); 
$dadosConcluidas = $stmtConcluidas->fetchAll(PDO::FETCH_KEY_PAIR);
$dataLineConcluidas = array_fill(0, 12, 0); 
foreach($dadosConcluidas as $mes => $qtd) { 
    if($mes >= 1 && $mes <= 12) {
        $dataLineConcluidas[$mes - 1] = $qtd; 
    }
}

// Meses em Português
$labelsLine = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0");
$stmt->execute([$usuario_id]); $qtdNotificacoes = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT id, titulo, prazo, prioridade FROM tarefas WHERE usuario_id = ? AND status != 'concluido' AND prazo <= DATE_ADD(NOW(), INTERVAL 7 DAY) ORDER BY prazo ASC LIMIT 6");
$stmt->execute([$usuario_id]); $agendaItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jsonEquipe = "[]";
if ($nivelUsuario <= 4) {
    $equipe = $pdo->query("SELECT id, nome, email FROM usuarios WHERE nivel < 7 AND ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
    $jsonEquipe = json_encode($equipe);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Dia da Mulher</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,800;1,400&display=swap'); /* Fonte elegante para a homenagem */
        
        /* Paleta temática Dia da Mulher */
        :root { 
            --atlas-primary: #8b5cf6; 
            --mulher-grad-1: #c026d3;
            --mulher-grad-2: #db2777;
        }
        
        body { background-color: #fcf8ff; font-family: 'Inter', sans-serif; min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden;}

        /* ========================================================
           TELA DE ANIMAÇÃO INICIAL (SPLASH SCREEN)
           ======================================================== */
        #splash-screen {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(135deg, #fdf4ff 0%, #fae8ff 100%);
            z-index: 9999; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            transition: opacity 1s ease-out, visibility 1s ease-out;
        }
        .splash-icon {
            font-size: 5rem; color: var(--mulher-grad-2); margin-bottom: 20px;
            animation: pulseHeart 2s infinite;
        }
        .splash-text {
            font-family: 'Playfair Display', serif; font-size: 2.5rem; font-weight: 800;
            color: #831843; text-align: center; max-width: 800px; padding: 0 20px;
            animation: slideUpFade 1.5s ease-out;
        }
        .splash-sub {
            font-family: 'Inter', sans-serif; color: #be185d; margin-top: 15px; font-weight: 600; letter-spacing: 1px;
            animation: slideUpFade 2s ease-out;
        }

        @keyframes pulseHeart {
            0% { transform: scale(1); opacity: 0.8; }
            50% { transform: scale(1.15); opacity: 1; text-shadow: 0 0 20px rgba(219, 39, 119, 0.5); }
            100% { transform: scale(1); opacity: 0.8; }
        }
        @keyframes slideUpFade {
            0% { opacity: 0; transform: translateY(30px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* Ocultar conteúdo principal até o splash terminar */
        #main-content { opacity: 0; transition: opacity 1s ease-in; }

        /* ======================================================== */

        .navbar-glass { background: rgba(255,255,255,0.9); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(219, 39, 119, 0.1); }
        .navbar-brand { font-weight: 800; color: var(--mulher-grad-2) !important; letter-spacing: -0.5px; }

        /* Card de Boas Vindas Temático */
        .welcome-section {
            background: linear-gradient(135deg, var(--mulher-grad-1), var(--mulher-grad-2)); 
            color: white; border-radius: 16px; padding: 1.5rem 2rem; margin-bottom: 2rem; 
            box-shadow: 0 10px 25px rgba(219, 39, 119, 0.25);
            display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden;
        }
        /* Efeito de flor abstrata de fundo */
        .welcome-section::after {
            content: '\f004'; font-family: "Font Awesome 6 Free"; font-weight: 900;
            position: absolute; right: -20px; bottom: -50px; font-size: 15rem; color: rgba(255,255,255,0.05); transform: rotate(-15deg);
        }
        
        .welcome-text h5 { font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 800; margin-bottom: 5px; }
        .welcome-text p { margin: 0; font-size: 0.85rem; opacity: 0.9; }
        .welcome-stat { text-align: right; border-left: 1px solid rgba(255,255,255,0.3); padding-left: 1.5rem; z-index: 2;}
        .welcome-stat h4 { margin: 0; font-weight: 800; }
        .welcome-stat small { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; }

        .kpi-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 3rem; }
        .kpi-card { background: white; border-radius: 20px; padding: 1.2rem; height: 100%; box-shadow: 0 4px 20px rgba(0,0,0,0.03); display: flex; align-items: center; justify-content: space-between; min-height: 100px; transition: transform 0.2s; position: relative; border: 1px solid rgba(219, 39, 119, 0.05); }
        .kpi-card:hover { transform: translateY(-5px); border-color: rgba(219, 39, 119, 0.2); }
        .kpi-icon { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; } 
        .kpi-info h3 { font-weight: 800; margin: 0; color: #1f2937; font-size: 1.5rem; transition: color 0.3s; }
        .kpi-info p { margin: 0; color: #6b7280; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
        
        .kpi-blue { background: #fdf4ff; color: #c026d3; } /* Adaptado para tons de mulher */
        .kpi-cyan { background: #fdf2f8; color: #db2777; } 
        .kpi-green { background: #f0fdf4; color: #10b981; } 
        .kpi-red { background: #fff1f2; color: #e11d48; } 
        .kpi-orange { background: #fff7ed; color: #ea580c; }
        .kpi-purple { background: #f5f3ff; color: #7c3aed; }
        
        .kpi-calendar-card { display: block !important; padding: 10px !important; }
        .mini-cal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.75rem; font-weight: 800; color: var(--mulher-grad-2); text-transform: uppercase; }
        .mini-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; text-align: center; }
        .mini-cal-day-head { font-size: 0.6rem; color: #94a3b8; font-weight: 700; margin-bottom: 2px; }
        .mini-cal-day { font-size: 0.7rem; color: #475569; padding: 4px 0; border-radius: 6px; position: relative; cursor: pointer; text-decoration: none; transition: background 0.2s; font-weight: 600; }
        .mini-cal-day:hover { background-color: #fce7f3; color: #be185d; }
        .mini-cal-today { background: linear-gradient(135deg, var(--mulher-grad-1), var(--mulher-grad-2)) !important; color: white !important; }
        .mini-cal-task::after { content: ''; width: 4px; height: 4px; background-color: #db2777; border-radius: 50%; position: absolute; bottom: 2px; left: 50%; transform: translateX(-50%); }
        .mini-cal-today.mini-cal-task::after { background-color: white; }

        .kpi-clock-card { display: block !important; }

        .menu-btn { 
            background: white; border: 1px solid rgba(219, 39, 119, 0.05); border-radius: 16px; padding: 1rem; 
            display: flex; flex-direction: column; align-items: center; justify-content: center; 
            text-decoration: none; color: #4b5563; transition: all 0.3s; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.02); height: 100px; width: 100%; position: relative; 
        }
        .menu-btn:hover { transform: translateY(-4px); box-shadow: 0 10px 25px rgba(219, 39, 119, 0.1); }
        .menu-btn i { font-size: 1.6rem; margin-bottom: 8px; } 
        .menu-btn span { font-weight: 700; font-size: 0.8rem; }
        .btn-disabled { opacity: 0.5; cursor: not-allowed !important; }
        .btn-disabled:hover { transform: none; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border-bottom: none !important; }
        
        .btn-tarefas:hover { border-bottom: 3px solid #db2777; color: #db2777; } 
        .btn-criar:hover { border-bottom: 3px solid #c026d3; color: #c026d3;} 
        .btn-agenda:hover { border-bottom: 3px solid #7c3aed; color: #7c3aed;} 
        .btn-real:hover { border-bottom: 3px solid #e11d48; color: #e11d48;} 
        .btn-equipe:hover { border-bottom: 3px solid #9d174d; color: #9d174d;} 
        .btn-admin:hover { border-bottom: 3px solid #be185d; color: #be185d;}
        .btn-aviso-normal:hover { border-bottom: 3px solid #ea580c; } 
        .btn-aviso-ativo { color: #dc2626; background: #fff1f2; border: 1px solid #ffe4e6; }

        .badge-notify { position: absolute; top: 8px; right: 8px; background: #e11d48; color: white; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: bold; border: 2px solid white; animation: pulse 2s infinite; transition: all 0.3s; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.7); } 100% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0); } }

        .chart-wrapper { background: white; border-radius: 24px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.03); margin-bottom: 1.5rem; position: relative; overflow: hidden; border: 1px solid rgba(219, 39, 119, 0.05); }
        .chart-header { margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .chart-header h5 { font-weight: 700; color: #831843; font-size: 1rem; margin: 0; }

        .card-colored { border: none; border-radius: 24px; box-shadow: 0 10px 30px rgba(219, 39, 119, 0.05); margin-bottom: 1.5rem; overflow: hidden; background: white; }
        .card-header-theme-1 { background: linear-gradient(135deg, #c026d3 0%, #db2777 100%); color: white; padding: 1rem 1.5rem; border: none; }
        .card-header-theme-2 { background: linear-gradient(135deg, #be185d 0%, #9f1239 100%); color: white; padding: 1rem 1.5rem; border: none; }

        .agenda-item { display: flex; align-items: center; padding: 12px; border-radius: 12px; margin-bottom: 8px; transition: background 0.2s; text-decoration: none; color: inherit; border: 1px solid transparent; }
        .agenda-item:hover { background: #fdf2f8; border-color: #fbcfe8; }
        .agenda-date { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 50px; height: 50px; border-radius: 10px; margin-right: 15px; font-weight: bold; }
        .agenda-overdue .agenda-date { background: #fee2e2; color: #dc2626; } .agenda-today .agenda-date { background: #fce7f3; color: #db2777; } .agenda-future .agenda-date { background: #f3e8ff; color: #7c3aed; }
        .agenda-title { font-weight: 600; font-size: 0.9rem; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        #suggestions-list { position: absolute; width: 100%; z-index: 1000; background: white; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto; margin-top: 5px; }
        .suggestion-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; }
        .suggestion-item:hover { background: #fdf2f8; color: #db2777; font-weight: bold; }
        .suggestion-item:last-child { border-bottom: none; }

        /* Rodapé Thematico */
        .footer-custom { background-color: #ffffff; border-top: 4px solid #db2777; padding: 1.5rem 0; margin-top: auto; }
        .footer-dev-label { font-size: 0.7rem; font-weight: 800; color: #831843; text-transform: uppercase; margin-bottom: 0.2rem; letter-spacing: 0.5px; }
        .footer-dev-name { font-size: 1.1rem; font-weight: 800; color: #db2777; text-decoration: none; }
        .footer-dev-name:hover { color: #9d174d; }
        .footer-social-btn { width: 45px; height: 45px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; text-decoration: none; transition: transform 0.2s; margin: 0 0.5rem; }
        .footer-social-btn:hover { transform: scale(1.1); color: white; }
        .footer-whatsapp { background-color: #25D366; box-shadow: 0 4px 15px rgba(37, 211, 102, 0.4); }
        .footer-instagram { background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); box-shadow: 0 4px 15px rgba(220, 39, 67, 0.4); }
        .footer-version { background-color: #831843; color: white; font-weight: 700; font-size: 0.85rem; padding: 6px 16px; border-radius: 50px; display: inline-block; }
    </style>
</head>
<body>

    <div id="splash-screen">
        <i class="fa-solid fa-heart splash-icon"></i>
        <div class="splash-text">"Que a sua força inspire o mundo e a sua delicadeza transforme vidas."</div>
        <div class="splash-sub">Feliz Dia Internacional da Mulher</div>
    </div>

    <div id="main-content">
        
        <nav class="navbar navbar-expand-lg navbar-glass fixed-top">
            <div class="container">
                <a class="navbar-brand" href="#"><i class="fa-solid fa-layer-group me-2"></i>HabitaNet Tarefas</a>
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                        <div class="text-white rounded-circle d-flex align-items-center justify-content-center me-2 fw-bold" style="width: 38px; height: 38px; background: #db2777;">
                            <?= strtoupper(substr($primeiroNome, 0, 1)) ?>
                        </div>
                        <div class="d-none d-sm-block text-dark ms-2">
                            <small class="d-block fw-bold" style="line-height:1;"><?= $primeiroNome ?></small>
                            <small class="text-muted" style="font-size: 0.7rem;"><?= $cargoNome ?></small>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2 rounded-4">
                        <li><a class="dropdown-item rounded-3" href="perfil.php"><i class="fa-regular fa-user me-2"></i>Perfil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item rounded-3 text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Sair</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <div style="margin-top: 90px;"></div>

        <div class="container">
            
            <div class="welcome-section">
                <div class="welcome-text">
                    <h5>Olá, <?= $primeiroNome ?>. Um feliz dia das Mulheres!</h5>
                    <p><i class="fa-solid fa-seedling me-1"></i> Que seu dia seja produtivo e inspirador.</p>
                </div>
                <div class="welcome-stat">
                    <h4><?= $eficiencia ?>%</h4>
                    <small>Sua Eficiência</small>
                </div>
            </div>

            <div class="kpi-container">
                <div class="kpi-card"><div class="kpi-info"><h3 id="val-abertas"><?= $stats['total_abertas'] ?></h3><p>Abertas</p></div><div class="kpi-icon kpi-blue"><i class="fa-regular fa-folder-open"></i></div></div>
                <div class="kpi-card"><div class="kpi-info"><h3 id="val-pendentes-mes"><?= $stats['pendentes_mes'] ?></h3><p>Mês Atual</p></div><div class="kpi-icon kpi-cyan"><i class="fa-regular fa-calendar"></i></div></div>
                <div class="kpi-card"><div class="kpi-info"><h3 id="val-concluidas"><?= $stats['total_concluidas'] ?></h3><p>Feitas (Ano)</p></div><div class="kpi-icon kpi-green"><i class="fa-solid fa-check"></i></div></div>
                <div class="kpi-card"><div class="kpi-info"><h3 id="val-atrasadas"><?= $stats['total_atrasadas'] ?></h3><p>Atrasadas</p></div><div class="kpi-icon kpi-red"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
                <div class="kpi-card" id="card-avisos" style="<?= $qtdNotificacoes > 0 ? 'border-bottom: 4px solid #db2777;' : '' ?>"><div class="kpi-info"><h3 id="val-avisos" class="<?= $qtdNotificacoes > 0 ? 'text-danger' : '' ?>"><?= $qtdNotificacoes ?></h3><p id="label-avisos" class="<?= $qtdNotificacoes > 0 ? 'text-danger' : '' ?>">Avisos</p></div><div class="kpi-icon kpi-orange"><i class="fa-solid fa-bullhorn"></i></div></div>
                
                <div class="kpi-card kpi-calendar-card">
                    <div class="mini-cal-header">
                        <span><?= strftime('%B %Y', strtotime('today')) ?></span>
                        <i class="fa-solid fa-calendar-alt text-muted"></i>
                    </div>
                    <div class="mini-cal-grid">
                        <div class="mini-cal-day-head">D</div><div class="mini-cal-day-head">S</div><div class="mini-cal-day-head">T</div><div class="mini-cal-day-head">Q</div><div class="mini-cal-day-head">Q</div><div class="mini-cal-day-head">S</div><div class="mini-cal-day-head">S</div>
                        <?php
                        for($k = 0; $k < $primeiroDiaSemana; $k++) { echo '<div></div>'; }
                        for($dia = 1; $dia <= $numDiasMes; $dia++) {
                            $ehHoje = ($dia == date('d') && $mesAtual == date('m') && $anoAtual == date('Y'));
                            $temTarefa = in_array($dia, $diasComTarefas);
                            $classeHoje = $ehHoje ? 'mini-cal-today' : '';
                            $classeTarefa = $temTarefa ? 'mini-cal-task' : '';
                            $dataFiltro = sprintf("%s-%s-%02d", $anoAtual, $mesAtual, $dia);
                            echo "<a href='minhas_tarefas.php?venc_ini=$dataFiltro&venc_fim=$dataFiltro' class='mini-cal-day $classeHoje $classeTarefa'>$dia</a>";
                        }
                        ?>
                    </div>
                </div>

                <div class="kpi-card kpi-clock-card">
                    <div class="d-flex justify-content-between align-items-center mb-2 w-100">
                        <div class="kpi-info"><h3 id="relogio-kpi" class="mb-0 text-dark">--:--</h3><p class="mb-0 fw-bold" style="font-size: 0.7rem;"><?= $dataAtualFormatada ?></p></div>
                        <div class="kpi-icon kpi-purple"><i class="fa-regular fa-clock"></i></div>
                    </div>
                    <div class="w-100 mb-2">
                        <div class="d-flex justify-content-between small text-muted mb-1" style="font-size: 0.65rem;">
                            <span>Ano <?= date('Y') ?></span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-1" style="font-size: 0.65rem;">
                             <span>Concluído: <?= $progressoAno ?>%</span>
                        </div>
                        <div class="progress" style="height: 4px; background-color: #fdf2f8;"><div class="progress-bar" role="progressbar" style="width: <?= $progressoAno ?>%; background-color: #db2777; border-radius: 4px;"></div></div>
                    </div>
                    <form method="GET" class="w-100 mt-2">
                        <input type="hidden" name="ano_chart" value="<?= $anoFiltro ?>">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-transparent border-0 ps-0 text-muted small"><i class="fa-solid fa-filter me-1"></i></span>
                            <select name="ano_kpi" class="form-select form-select-sm rounded-pill bg-light border-0 fw-bold text-primary" onchange="this.form.submit()" style="font-size: 0.75rem;">
                                <?php foreach($anosDisponiveis as $a): ?><option value="<?= $a ?>" <?= $a == $anoKpi ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <h6 class="text-uppercase fw-bold mb-3 small ps-2" style="color: #db2777;">Menu Principal</h6>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-4 col-md-3"><a href="minhas_tarefas.php" class="menu-btn btn-tarefas" style="color:#c026d3;"><i class="fa-solid fa-clipboard-check"></i><span>Tarefas</span></a></div>
                        <div class="col-4 col-md-3"><a href="criar_tarefa.php" class="menu-btn btn-criar" style="color:#db2777;"><i class="fa-solid fa-circle-plus"></i><span>Criar</span></a></div>
                        
                        <div class="col-4 col-md-3">
                            <a href="mensagens.php" id="btn-avisos" class="menu-btn <?= $qtdNotificacoes > 0 ? 'btn-aviso-ativo' : 'btn-aviso-normal' ?>" style="color:<?= $qtdNotificacoes>0?'#dc2626':'#ea580c' ?>">
                                <div id="badge-avisos-menu" class="badge-notify" style="display: <?= $qtdNotificacoes > 0 ? 'flex' : 'none' ?>;"><?= $qtdNotificacoes ?></div>
                                <i class="fa-solid fa-bullhorn"></i><span>Avisos</span>
                            </a>
                        </div>

                        <div class="col-4 col-md-3"><a href="agenda.php" class="menu-btn btn-agenda" style="color:#7c3aed;"><i class="fa-regular fa-calendar-check"></i><span>Agenda</span></a></div>
                        
                        <?php if ($nivelUsuario <= 4): ?><div class="col-4 col-md-3"><a href="temporeal.php" class="menu-btn btn-real" style="color:#e11d48;"><i class="fa-solid fa-chart-line"></i><span>Tempo Real</span></a></div><?php endif; ?>
                        <?php if($nivelUsuario <= 2): ?><div class="col-4 col-md-3"><a href="usuarios.php" class="menu-btn btn-equipe" style="color:#9d174d;"><i class="fa-solid fa-people-group"></i><span>Equipe</span></a></div><?php endif; ?>
                        
                        <div class="col-4 col-md-3">
                            <a href="#" class="menu-btn btn-disabled" style="color: #94a3b8;" onclick="alert('Módulo de Processos em desenvolvimento!'); return false;">
                                <i class="fa-solid fa-network-wired"></i>
                                <span>Processos</span>
                            </a>
                        </div>

                        <?php if($nivelUsuario <= 4): ?>
                            <div class="col-4 col-md-3"><a href="admin/index.php" class="menu-btn btn-admin" style="color:#be185d;"><i class="fa-solid fa-user-shield"></i><span>Admin</span></a></div>
                        <?php endif; ?>
                    </div>

                    <div class="chart-wrapper" style="min-height: 480px;">
                        <div class="chart-header">
                            <h5><i class="fa-solid fa-chart-line me-2" style="color: #db2777;"></i>Fluxo de Atividades (Mensal)</h5>
                            <form method="GET" class="d-flex align-items-center gap-2">
                                <input type="hidden" name="ano_kpi" value="<?= $anoKpi ?>">
                                <select name="ano_chart" class="form-select form-select-sm rounded-pill border-0 bg-light fw-bold text-secondary" style="width: auto;" onchange="this.form.submit()">
                                    <?php foreach($anosDisponiveis as $a): ?><option value="<?= $a ?>" <?= $a == $anoFiltro ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <div style="height: 350px;"><canvas id="chartCarga"></canvas></div>
                    </div>
                </div>

                <div class="col-lg-4">
                    
                    <?php if($nivelUsuario <= 4): ?>
                    <div class="card card-colored">
                        <div class="card-header-theme-1 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold"><i class="fa-solid fa-users-viewfinder me-2"></i>Supervisão</h6>
                        </div>
                        <div class="card-body p-3">
                            <form action="supervisao.php" method="GET" id="formSuper">
                                <label class="small text-muted fw-bold mb-2">Digite para buscar (min. 3 letras):</label>
                                
                                <div class="position-relative">
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                                        <input type="text" id="searchUser" class="form-control border-start-0" placeholder="Nome do colaborador..." autocomplete="off">
                                    </div>
                                    <input type="hidden" name="id" id="userId" required>
                                    <div id="suggestions-list" style="display:none;"></div>
                                </div>

                                <button type="submit" id="btnVer" class="btn text-white w-100 mt-3 fw-bold shadow-sm" style="background: #c026d3;" disabled>
                                    Acessar Painel
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card card-colored">
                        <div class="card-header-theme-2 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold"><i class="fa-regular fa-calendar-days me-2"></i>Agenda (7 Dias)</h6>
                            <?php if(count($agendaItems) > 0): ?><span class="badge bg-white text-danger rounded-pill border border-danger border-opacity-25"><?= count($agendaItems) ?></span><?php endif; ?>
                        </div>
                        <div class="card-body p-3">
                            <?php if(count($agendaItems) == 0): ?><div class="text-center py-4 text-muted small"><i class="fa-regular fa-calendar-check fa-2x mb-2 opacity-25"></i><p class="mb-0">Sem pendências próximas.</p></div><?php else: ?>
                                <?php foreach($agendaItems as $item): 
                                    $dt = new DateTime($item['prazo']); $hojeDt = new DateTime(); $hojeDt->setTime(0,0,0); $dt->setTime(0,0,0); $diff = $hojeDt->diff($dt); $days = (int)$diff->format("%r%a");
                                    if ($days < 0) { $classeAgenda = 'agenda-overdue'; $textoStatus = 'Vencida'; } elseif ($days == 0) { $classeAgenda = 'agenda-today'; $textoStatus = 'Hoje'; } else { $classeAgenda = 'agenda-future'; $textoStatus = $dt->format('d/m'); }
                                ?>
                                <a href="detalhes_tarefa.php?id=<?= $item['id'] ?>" class="agenda-item <?= $classeAgenda ?>"><div class="agenda-date"><span class="day"><?= $dt->format('d') ?></span><span class="month"><?= substr(strtoupper(date('M', $dt->getTimestamp())), 0, 3) ?></span></div><div class="agenda-content"><div class="agenda-title"><?= htmlspecialchars($item['titulo']) ?></div><span class="badge bg-white text-muted border"><?= $textoStatus ?></span></div><i class="fa-solid fa-chevron-right text-muted small"></i></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="chart-wrapper">
                        <div class="chart-header"><h5><i class="fa-solid fa-chart-pie me-2" style="color: #c026d3;"></i>Status Geral</h5></div>
                        <div style="height: 220px; display: flex; align-items: center; justify-content: center;"><canvas id="chartStatus"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="footer-custom">
            <div class="container">
                <div class="row align-items-center text-center text-md-start">
                    
                    <div class="col-md-4 mb-3 mb-md-0">
                        <div class="footer-dev-label">Sistemas desenvolvidos e cedidos por</div>
                        <a href="https://www.mhos.com.br" target="_blank" class="footer-dev-name">Mário Henrique Inácio de Paula</a>
                    </div>

                    <div class="col-md-4 text-center mb-3 mb-md-0">
                        <a href="https://wa.me/5564992238703" target="_blank" class="footer-social-btn footer-whatsapp" title="WhatsApp">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                        <a href="https://instagram.com/mariomorrinhos" target="_blank" class="footer-social-btn footer-instagram" title="Instagram">
                            <i class="fa-brands fa-instagram"></i>
                        </a>
                    </div>

                    <div class="col-md-4 text-center text-md-end">
                        <div class="footer-version">Versão 10.2</div>
                    </div>

                </div>
            </div>
        </footer>
    </div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // CONTROLE DA ANIMAÇÃO INICIAL
        window.addEventListener('load', function() {
            setTimeout(function() {
                const splash = document.getElementById('splash-screen');
                const main = document.getElementById('main-content');
                
                splash.style.opacity = '0';
                splash.style.visibility = 'hidden';
                
                setTimeout(() => {
                    splash.style.display = 'none';
                    main.style.opacity = '1';
                }, 1000); // Espera o fadeout do splash para mostrar o main
            }, 4000); // Tela fica por 4 segundos
        });

        const usersData = <?= $jsonEquipe ?>;
        const anoKpiAtual = <?= $anoKpi ?>;

        // AUTOCOMPLETE DE USUÁRIOS
        const input = document.getElementById('searchUser');
        const list = document.getElementById('suggestions-list');
        const hiddenId = document.getElementById('userId');
        const btnVer = document.getElementById('btnVer');

        if(input) {
            input.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                list.innerHTML = '';
                hiddenId.value = '';
                btnVer.disabled = true;

                if (term.length < 3) {
                    list.style.display = 'none';
                    return;
                }

                const matches = usersData.filter(u => u.nome.toLowerCase().includes(term));

                if (matches.length > 0) {
                    list.style.display = 'block';
                    matches.forEach(u => {
                        const div = document.createElement('div');
                        div.className = 'suggestion-item';
                        div.innerHTML = `<i class="fa-solid fa-user me-2 text-muted"></i>${u.nome}`;
                        div.onclick = () => {
                            input.value = u.nome;
                            hiddenId.value = u.id;
                            list.style.display = 'none';
                            btnVer.disabled = false;
                        };
                        list.appendChild(div);
                    });
                } else {
                    list.style.display = 'none';
                }
            });

            document.addEventListener('click', function(e) {
                if (e.target !== input && e.target !== list) {
                    list.style.display = 'none';
                }
            });
        }

        // GRÁFICOS E RELÓGIO
        setInterval(() => { document.getElementById('relogio-kpi').innerText = new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'}); }, 1000);

        new Chart(document.getElementById('chartStatus'), {
            type: 'doughnut',
            data: {
                labels: ['Concluídas', 'Em Aberto', 'Atrasadas'],
                datasets: [{
                    data: [<?= $stats['total_concluidas'] ?>, <?= $stats['total_abertas'] ?>, <?= $stats['total_atrasadas'] ?>],
                    backgroundColor: ['#db2777', '#c026d3', '#e11d48'], borderWidth: 0, hoverOffset: 10
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
        });

        new Chart(document.getElementById('chartCarga'), {
            type: 'line',
            data: {
                labels: <?= json_encode($labelsLine) ?>,
                datasets: [
                    {
                        label: 'Tarefas Criadas',
                        data: <?= json_encode($dataLineCriadas) ?>,
                        borderColor: '#db2777', 
                        backgroundColor: (context) => {
                            const ctx = context.chart.ctx;
                            const gradient = ctx.createLinearGradient(0, 0, 0, 350);
                            gradient.addColorStop(0, 'rgba(219, 39, 119, 0.2)');
                            gradient.addColorStop(1, 'rgba(219, 39, 119, 0)');
                            return gradient;
                        },
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2
                    },
                    {
                        label: 'Tarefas Concluídas',
                        data: <?= json_encode($dataLineConcluidas) ?>,
                        borderColor: '#c026d3', 
                        backgroundColor: (context) => {
                            const ctx = context.chart.ctx;
                            const gradient = ctx.createLinearGradient(0, 0, 0, 350);
                            gradient.addColorStop(0, 'rgba(192, 38, 211, 0.2)');
                            gradient.addColorStop(1, 'rgba(192, 38, 211, 0)');
                            return gradient;
                        },
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, grid: { borderDash: [5, 5] }, ticks: { stepSize: 1 } },
                    x: { grid: { display: false } }
                },
                plugins: { 
                    legend: { 
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                }
            }
        });

        // ATUALIZAÇÃO AUTOMÁTICA
        setInterval(() => {
            fetch(`ajax_dashboard_data.php?ano=${anoKpiAtual}`)
                .then(response => response.json())
                .then(data => {
                    if (!data) return;

                    document.getElementById('val-abertas').innerText = data.abertas;
                    document.getElementById('val-pendentes-mes').innerText = data.pendentes_mes;
                    document.getElementById('val-concluidas').innerText = data.concluidas;
                    document.getElementById('val-atrasadas').innerText = data.atrasadas;
                    
                    const valAvisos = document.getElementById('val-avisos');
                    const labelAvisos = document.getElementById('label-avisos');
                    const cardAvisos = document.getElementById('card-avisos');
                    
                    valAvisos.innerText = data.avisos;
                    if (data.avisos > 0) {
                        valAvisos.classList.add('text-danger');
                        labelAvisos.classList.add('text-danger');
                        cardAvisos.style.borderBottom = '4px solid #db2777';
                    } else {
                        valAvisos.classList.remove('text-danger');
                        labelAvisos.classList.remove('text-danger');
                        cardAvisos.style.borderBottom = '1px solid rgba(219, 39, 119, 0.05)';
                    }

                    const btnAvisos = document.getElementById('btn-avisos');
                    const badgeAvisos = document.getElementById('badge-avisos-menu');
                    badgeAvisos.innerText = data.avisos;
                    if (data.avisos > 0) {
                        badgeAvisos.style.display = 'flex';
                        btnAvisos.classList.remove('btn-aviso-normal');
                        btnAvisos.classList.add('btn-aviso-ativo');
                    } else {
                        badgeAvisos.style.display = 'none';
                        btnAvisos.classList.remove('btn-aviso-ativo');
                        btnAvisos.classList.add('btn-aviso-normal');
                    }
                })
                .catch(err => console.error("Erro ao atualizar dashboard:", err));
        }, 60000);
    </script>
    <?php include 'chat_widget.php'; ?>
</body>
</html>