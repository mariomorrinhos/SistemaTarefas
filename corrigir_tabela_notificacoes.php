<?php
// corrigir_tabela_notificacoes.php
require_once 'config/database/conexao.php';

try {
    echo "<h3>Iniciando atualização da tabela 'notificacoes'...</h3>";

    // 1. Adicionar colunas novas se não existirem
    // Tenta adicionar 'mensagem'
    try {
        $pdo->exec("ALTER TABLE notificacoes ADD COLUMN mensagem VARCHAR(255) NULL AFTER mensagem_id");
        echo "Coluna 'mensagem' adicionada.<br>";
    } catch (Exception $e) { echo "Coluna 'mensagem' já existe ou erro: " . $e->getMessage() . "<br>"; }

    // Tenta adicionar 'link'
    try {
        $pdo->exec("ALTER TABLE notificacoes ADD COLUMN link VARCHAR(255) NULL AFTER mensagem");
        echo "Coluna 'link' adicionada.<br>";
    } catch (Exception $e) { echo "Coluna 'link' já existe ou erro: " . $e->getMessage() . "<br>"; }

    // 2. Tornar 'mensagem_id' opcional (NULLABLE)
    // Isso é crucial, pois notificações de tarefa NÃO têm mensagem_id do mural
    try {
        // Comando para MySQL
        $pdo->exec("ALTER TABLE notificacoes MODIFY COLUMN mensagem_id INT NULL");
        echo "Coluna 'mensagem_id' agora aceita valores nulos (OK).<br>";
    } catch (Exception $e) {
        echo "Erro ao modificar mensagem_id: " . $e->getMessage() . "<br>";
    }

    echo "<hr><h4 style='color:green'>Sucesso! A tabela foi atualizada.</h4>";
    echo "<p>Agora você pode tentar transferir a tarefa novamente.</p>";
    echo "<a href='minhas_tarefas.php'>Voltar para o Sistema</a>";

} catch (PDOException $e) {
    echo "<h4 style='color:red'>Erro Crítico:</h4> " . $e->getMessage();
}
?>