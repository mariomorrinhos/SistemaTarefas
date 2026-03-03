<?php
// admin/atrasadas.php
session_start();
require_once '../config/database/conexao.php';

// 1. SEGURANÇA (Apenas níveis 1 a 4)
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] > 4) {
    header("Location: ../dashboard.php");
    exit;
}

// 2. BUSCAR TAREFAS ATRASADAS DE TODA A ORGANIZAÇÃO
$sql = "SELECT t.*, u.nome as responsavel, c.nome as criador, cat.nome as categoria_nome, cat.cor as categoria_cor,
        DATEDIFF(NOW(), t.prazo) as dias_atraso
        FROM tarefas t 
        JOIN usuarios u ON t.usuario_id = u.id 
        JOIN usuarios c ON t.criado_por = c.id 
        LEFT JOIN categorias cat ON t.categoria_id = cat.id 
        WHERE t.status != 'concluido' AND t.status != 'arquivado' AND t.prazo < NOW() AND u.nivel < 7
        ORDER BY dias_atraso DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$tarefasAtrasadas = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarefas Atrasadas - Centro Administrativo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; min-height: 100vh; }
        .navbar-admin { background: linear-gradient(90deg, #1a2a6c, #b21f1f); box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        .navbar-brand { font-weight: 800; letter-spacing: 1px; color: white !important; }
        
        .table-container { background: white; border-radius: 20px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .table thead th { border: none; text-transform: uppercase; font-size: 0.75rem; color: #a0aec0; font-weight: 700; padding-bottom: 1rem; }
        .table tbody td { vertical-align: middle; color: #4a5568; font-size: 0.9rem; border-top: 1px solid #f7fafc; padding: 1rem 0.5rem; }
        
        .clickable-row { cursor: pointer; transition: background-color 0.2s; }
        .clickable-row:hover td { background-color: #fff5f5; } /* Hover vermelho leve para indicar perigo/atraso */

        .badge-cat { font-size: 0.65rem; padding: 3px 8px; border-radius: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display: inline-block; color: white; }
        .dias-badge { font-size: 0.8rem; font-weight: 800; padding: 5px 10px; border-radius: 8px; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark navbar-admin fixed-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="index.php"><i class="fa-solid fa-building-shield me-2"></i>CENTRO ADMINISTRATIVO</a>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-sm btn-outline-light rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> Voltar ao Painel
                </a>
            </div>
        </div>
    </nav>

    <div style="margin-top: 100px;"></div>

    <div class="container pb-5">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold text-dark m-0"><i class="fa-solid fa-circle-exclamation text-danger me-2"></i> Tarefas em Atraso</h4>
                <p class="text-muted mb-0 small">Relatório global de todas as tarefas que ultrapassaram o prazo estipulado.</p>
            </div>
            <span class="badge bg-danger fs-6 rounded-pill px-3 py-2 shadow-sm">Total: <?= count($tarefasAtrasadas) ?></span>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Protocolo</th>
                            <th>Tarefa</th>
                            <th>Responsável</th>
                            <th>Prazo Original</th>
                            <th class="text-center">Tempo de Atraso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($tarefasAtrasadas) == 0): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fa-solid fa-check-double fa-2x mb-2 text-success opacity-50 d-block"></i>Excelente! Nenhuma tarefa atrasada na organização.</td></tr>
                        <?php else: ?>
                            <?php foreach($tarefasAtrasadas as $t): 
                                $prazoObj = new DateTime($t['prazo']);
                                $protocolo = !empty($t['protocolo']) ? $t['protocolo'] : $t['id'];
                                
                                // Definir a severidade do atraso pelas cores
                                $corAtraso = 'bg-warning text-dark'; // Até 2 dias
                                if($t['dias_atraso'] > 2 && $t['dias_atraso'] <= 7) $corAtraso = 'bg-orange text-white'; // Precisa de CSS inline para laranja se não houver classe no BS
                                if($t['dias_atraso'] > 7) $corAtraso = 'bg-danger text-white shadow-sm';
                            ?>
                            <tr class="clickable-row" onclick="window.location.href='../detalhes_tarefa.php?id=<?= $t['id'] ?>'" title="Clique para abrir esta tarefa">
                                <td><span class="badge bg-light text-dark border">#<?= $protocolo ?></span></td>
                                <td>
                                    <?php if($t['categoria_nome']): ?>
                                        <span class="badge-cat" style="background-color: <?= $t['categoria_cor'] ?>"><?= $t['categoria_nome'] ?></span><br>
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
                                <td>
                                    <span class="text-danger fw-bold"><i class="fa-regular fa-calendar-xmark me-1"></i> <?= $prazoObj->format('d/m/Y H:i') ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="dias-badge <?= $corAtraso ?>" style="<?= ($t['dias_atraso'] > 2 && $t['dias_atraso'] <= 7) ? 'background-color: #fd7e14; color: white;' : '' ?>">
                                        <?= $t['dias_atraso'] ?> <?= $t['dias_atraso'] == 1 ? 'dia' : 'dias' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>