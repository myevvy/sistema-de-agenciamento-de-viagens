<?php
declare(strict_types=1);

/** Abre a página com o mesmo menu lateral/topo do index.php, acrescido dos módulos novos. */
function renderHeader(string $active, string $title, string $crumb): void
{
    global $flash;
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    $user = authCurrentUser();
    $link = static fn(string $key, string $href, string $icon, string $label): string =>
        '<a class="' . ($active === $key ? 'active' : '') . '" href="' . $href . '"><span>' . $icon . '</span> ' . $label . '</a>';
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | VivaMundo</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/extra.css">
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="painel.php"><span class="brand-mark">V</span><span>Viva<span class="brand-light">Mundo</span><small>AGÊNCIA DE VIAGENS</small></span></a>
    <p class="nav-label">MENU PRINCIPAL</p>
    <nav>
        <?= $link('home', 'index.php?page=dashboard', '⌂', 'Visão geral') ?>
        <?= $link('clients', 'index.php?page=clients', '♙', 'Clientes') ?>
        <?= $link('packages', 'index.php?page=packages', '✈', 'Pacotes') ?>
        <?= $link('bookings', 'index.php?page=bookings', '▤', 'Reservas') ?>
    </nav>
    <p class="nav-label nav-label-tight">GESTÃO</p>
    <nav>
        <?= $link('painel', 'painel.php', '◔', 'Painel gerencial') ?>
        <?= $link('pagamentos', 'pagamentos.php', '＄', 'Pagamentos') ?>
        <?= $link('viagens', 'viagens.php', '⚑', 'Situação das viagens') ?>
        <?= $link('relatorios', 'relatorios.php', '☰', 'Relatórios') ?>
        <?= $link('usuarios', 'usuarios.php', '⚙', authIsAdmin($user) ? 'Usuários' : 'Minha conta') ?>
    </nav>
    <div class="sidebar-footer">PAINEL ADMINISTRATIVO <span>v1.1</span></div>
</aside>
<main class="main">
    <header class="topbar"><div class="breadcrumb">VivaMundo <span>/</span> <?= h($crumb) ?></div><?= $user ? authUserChip($user, authCsrfToken()) : '' ?></header>
    <div class="content">
        <?php if ($flash): ?>
            <div class="flash <?= h($flash['type']) ?>" role="status"><?= h($flash['text']) ?></div>
        <?php endif; ?>
<?php
}

function renderClientHeader(string $title): void
{
    $user = authCurrentUser();
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | VivaMundo</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/extra.css">
</head>
<body>
<aside class="sidebar client-sidebar">
    <a class="brand" href="cliente.php"><span class="brand-mark">V</span><span>Viva<span class="brand-light">Mundo</span><small>ÁREA DO VIAJANTE</small></span></a>
    <p class="nav-label">SUA VIAGEM</p>
    <nav>
        <a href="#pacotes"><span>✈</span> Explorar pacotes</a>
        <a href="#reservas"><span>▤</span> Minhas reservas</a>
    </nav>
    <div class="sidebar-footer">ÁREA DO CLIENTE</div>
</aside>
<main class="main">
    <header class="topbar"><div class="breadcrumb">VivaMundo <span>/</span> <?= h($title) ?></div><?= $user ? authUserChip($user, authCsrfToken()) : '' ?></header>
    <div class="content">
        <?php if ($flash = $_SESSION['flash'] ?? null): unset($_SESSION['flash']); ?>
            <div class="flash <?= h($flash['type']) ?>" role="status"><?= h($flash['text']) ?></div>
        <?php endif; ?>
<?php
}

function renderFooter(): void
{
    ?>
        <footer class="footer">VivaMundo <span>·</span> Feito para transformar viagens em boas lembranças.</footer>
    </div>
</main>
</body>
</html>
<?php
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
}

function badge(string $class, string $label): string
{
    return '<span class="badge ' . h($class) . '">' . h($label) . '</span>';
}

function progressBar(int $percent): string
{
    $level = $percent >= 100 ? 'full' : ($percent >= 70 ? 'high' : 'ok');
    return '<div class="progress ' . $level . '" role="progressbar" aria-valuenow="' . $percent . '" aria-valuemin="0" aria-valuemax="100"><span style="width:' . $percent . '%"></span></div>';
}

/** Página pública (login/cadastro): cartão centralizado, sem menu. */
function renderAuthHeader(string $title): void
{
    global $flash;
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | VivaMundo</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/extra.css">
</head>
<body class="auth-body">
<main class="auth-wrap">
    <div class="auth-brand"><span class="brand-mark">V</span><span>Viva<span class="brand-light">Mundo</span><small>AGÊNCIA DE VIAGENS</small></span></div>
    <section class="panel auth-card">
        <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>" role="status"><?= h($flash['text']) ?></div><?php endif; ?>
<?php
}

function renderAuthFooter(): void
{
    ?>
    </section>
    <p class="auth-foot">VivaMundo <span>·</span> Painel administrativo</p>
</main>
</body>
</html>
<?php
}
