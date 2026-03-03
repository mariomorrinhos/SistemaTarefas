<?php
// login.php

// Define cabeçalhos para impedir o cache do navegador
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Data no passado

// Redirecionamento instantâneo para o diretório pai
header("Location: ../tarefas");

// Interrompe a execução do script para garantir o redirecionamento
exit;