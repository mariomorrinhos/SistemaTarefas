<?php
// config/database/conexao.php
/*
 * Arquivo de conexão com o banco de dados.
 * Local: /config/database/conexao.php
 */

$host = 'localhost';
$dbname = 'nome_do_seu_banco_de_dados';
$user = 'usuario_do_seu_banco_de_dados';
$pass = 'senha_do_usuario_do_banco_de_dados';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    // Configura o PDO para lançar exceções em caso de erro
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Configura o retorno padrão dos dados como array associativo
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Em produção, evite exibir o erro detalhado diretamente ao usuário
    die("Erro na conexão com o banco de dados. Código: 001");
}
?>
