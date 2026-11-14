<?php
declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Arquivo config.php ausente. Copie config.example.php para config.php e informe os dados do MySQL.');
}

$databaseConfig = require $configPath;
try {
    $db = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $databaseConfig['host'],
            $databaseConfig['port'],
            $databaseConfig['database']
        ),
        $databaseConfig['username'],
        $databaseConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    error_log('Falha ao conectar ao MySQL: ' . $exception->getMessage());
    http_response_code(500);
    exit('Não foi possível conectar ao MySQL. Confira o serviço, a extensão pdo_mysql e os dados em config.php.');
}

$db->exec(
    "CREATE TABLE IF NOT EXISTS clients (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(40) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_clients_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$db->exec(
    'CREATE TABLE IF NOT EXISTS packages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(120) NOT NULL,
        destination VARCHAR(120) NOT NULL,
        departure_date DATE NOT NULL,
        return_date DATE NOT NULL,
        price DECIMAL(10, 2) NOT NULL CHECK (price >= 0),
        seat_capacity INT UNSIGNED NOT NULL,
        description TEXT NOT NULL,
        PRIMARY KEY (id),
        CHECK (seat_capacity >= 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS bookings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT UNSIGNED NOT NULL,
        package_id BIGINT UNSIGNED NOT NULL,
        travelers INT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        CHECK (travelers > 0),
        CHECK (status IN ('pending', 'confirmed', 'cancelled')),
        CONSTRAINT fk_bookings_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
        CONSTRAINT fk_bookings_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $page): never
{
    header('Location: ?page=' . rawurlencode($page));
    exit;
}

function postString(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function requireCsrf(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        throw new RuntimeException('Sua sessão expirou. Atualize a página e tente novamente.');
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page = (string) ($_GET['page'] ?? 'dashboard');
$validPages = ['dashboard', 'clients', 'packages', 'bookings'];
if (!in_array($page, $validPages, true)) {
    $page = 'dashboard';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postString('action');
    $redirectPage = match ($action) {
        'save_client', 'delete_client' => 'clients',
        'save_package', 'delete_package' => 'packages',
        'save_booking', 'update_booking', 'delete_booking' => 'bookings',
        default => 'dashboard',
    };

    try {
        requireCsrf();

        switch ($action) {
            case 'save_client':
                $id = (int) ($_POST['id'] ?? 0);
                $name = postString('name');
                $email = postString('email');
                $phone = postString('phone');
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Informe o nome e um e-mail válido.');
                }
                if ($id > 0) {
                    $stmt = $db->prepare('UPDATE clients SET name = ?, email = ?, phone = ? WHERE id = ?');
                    $stmt->execute([$name, $email, $phone, $id]);
                } else {
                    $stmt = $db->prepare('INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)');
                    $stmt->execute([$name, $email, $phone]);
                }
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Cliente salvo com sucesso.'];
                break;

            case 'save_package':
                $id = (int) ($_POST['id'] ?? 0);
                $title = postString('title');
                $destination = postString('destination');
                $departure = postString('departure_date');
                $return = postString('return_date');
                $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
                $seats = filter_var($_POST['seat_capacity'] ?? null, FILTER_VALIDATE_INT);
                $description = postString('description');
                $validDate = static function (string $date): bool {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        return false;
                    }
                    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
                };

                if (
                    $title === '' || $destination === '' || !$validDate($departure)
                    || !$validDate($return) || $return < $departure || $price === false
                    || $price < 0 || $seats === false || $seats < 0
                ) {
                    throw new RuntimeException('Confira os campos do pacote. A volta deve ser na data de partida ou depois.');
                }
                if ($id > 0) {
                    $stmt = $db->prepare(
                        "SELECT COALESCE(SUM(travelers), 0) FROM bookings WHERE package_id = ? AND status <> 'cancelled'"
                    );
                    $stmt->execute([$id]);
                    if ($seats < (int) $stmt->fetchColumn()) {
                        throw new RuntimeException('A capacidade não pode ser menor que o total de viajantes já reservados.');
                    }
                    $stmt = $db->prepare(
                        'UPDATE packages SET title = ?, destination = ?, departure_date = ?, return_date = ?, price = ?, seat_capacity = ?, description = ? WHERE id = ?'
                    );
                    $stmt->execute([$title, $destination, $departure, $return, $price, $seats, $description, $id]);
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO packages (title, destination, departure_date, return_date, price, seat_capacity, description) VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$title, $destination, $departure, $return, $price, $seats, $description]);
                }
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Pacote salvo com sucesso.'];
                break;

            case 'save_booking':
                $clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT);
                $packageId = filter_var($_POST['package_id'] ?? null, FILTER_VALIDATE_INT);
                $travelers = filter_var($_POST['travelers'] ?? null, FILTER_VALIDATE_INT);
                if (!$clientId || !$packageId || !$travelers || $travelers < 1) {
                    throw new RuntimeException('Selecione um cliente, um pacote e a quantidade de viajantes.');
                }
                $stmt = $db->prepare('SELECT seat_capacity FROM packages WHERE id = ?');
                $stmt->execute([$packageId]);
                $package = $stmt->fetch();
                if (!$package) {
                    throw new RuntimeException('O pacote selecionado não foi encontrado.');
                }
                $stmt = $db->prepare(
                    "SELECT COALESCE(SUM(travelers), 0) FROM bookings WHERE package_id = ? AND status <> 'cancelled'"
                );
                $stmt->execute([$packageId]);
                $reservedSeats = (int) $stmt->fetchColumn();
                if ($reservedSeats + $travelers > (int) $package['seat_capacity']) {
                    throw new RuntimeException('Não há vagas disponíveis suficientes para essa reserva.');
                }
                $stmt = $db->prepare('INSERT INTO bookings (client_id, package_id, travelers) VALUES (?, ?, ?)');
                $stmt->execute([$clientId, $packageId, $travelers]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Reserva criada como pendente.'];
                break;

            case 'update_booking':
                $bookingId = (int) ($_POST['id'] ?? 0);
                $status = postString('status');
                if (!in_array($status, ['pending', 'confirmed', 'cancelled'], true)) {
                    throw new RuntimeException('Estado de reserva inválido.');
                }
                $stmt = $db->prepare('SELECT package_id, travelers, status FROM bookings WHERE id = ?');
                $stmt->execute([$bookingId]);
                $booking = $stmt->fetch();
                if (!$booking) {
                    throw new RuntimeException('Reserva não encontrada.');
                }
                if ($status !== 'cancelled' && $booking['status'] === 'cancelled') {
                    $stmt = $db->prepare(
                        'SELECT seat_capacity FROM packages WHERE id = ?'
                    );
                    $stmt->execute([$booking['package_id']]);
                    $capacity = (int) $stmt->fetchColumn();
                    $stmt = $db->prepare(
                        "SELECT COALESCE(SUM(travelers), 0) FROM bookings WHERE package_id = ? AND status <> 'cancelled'"
                    );
                    $stmt->execute([$booking['package_id']]);
                    if ((int) $stmt->fetchColumn() + (int) $booking['travelers'] > $capacity) {
                        throw new RuntimeException('Não há vagas suficientes para reativar esta reserva.');
                    }
                }
                $stmt = $db->prepare('UPDATE bookings SET status = ? WHERE id = ?');
                $stmt->execute([$status, $bookingId]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Estado da reserva atualizado.'];
                break;

            case 'delete_client':
                $stmt = $db->prepare('DELETE FROM clients WHERE id = ?');
                $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Cliente removido.'];
                break;

            case 'delete_package':
                $stmt = $db->prepare('DELETE FROM packages WHERE id = ?');
                $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Pacote removido.'];
                break;

            case 'delete_booking':
                $stmt = $db->prepare('DELETE FROM bookings WHERE id = ?');
                $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Reserva removida.'];
                break;

            default:
                throw new RuntimeException('Ação inválida.');
        }
    } catch (PDOException $exception) {
        $exceptionMessage = strtolower($exception->getMessage());
        $message = str_contains($exceptionMessage, 'unique')
            || str_contains($exceptionMessage, 'duplicate entry')
            ? 'Este e-mail já está cadastrado.'
            : (str_contains($exceptionMessage, 'foreign key')
                ? 'Não é possível remover este registro porque ele está vinculado a reservas.'
                : 'Não foi possível salvar os dados. Confira as informações e tente novamente.');
        $_SESSION['flash'] = ['type' => 'error', 'text' => $message];
    } catch (RuntimeException $exception) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => $exception->getMessage()];
    }

    redirect($redirectPage);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = h($_SESSION['csrf_token']);
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$editing = null;
if ($editId > 0 && in_array($page, ['clients', 'packages'], true)) {
    $table = $page === 'clients' ? 'clients' : 'packages';
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$clients = $db->query('SELECT * FROM clients ORDER BY name')->fetchAll();
$packages = $db->query(
    "SELECT p.*, p.seat_capacity - COALESCE(SUM(CASE WHEN b.status <> 'cancelled' THEN b.travelers ELSE 0 END), 0) AS remaining_seats
     FROM packages p LEFT JOIN bookings b ON b.package_id = p.id
     GROUP BY p.id ORDER BY p.departure_date"
)->fetchAll();
$bookings = $db->query(
    'SELECT b.*, c.name AS client_name, p.title AS package_title, p.destination
     FROM bookings b
     JOIN clients c ON c.id = b.client_id
     JOIN packages p ON p.id = b.package_id
     ORDER BY b.created_at DESC, b.id DESC'
)->fetchAll();
$counts = [
    'clients' => (int) $db->query('SELECT COUNT(*) FROM clients')->fetchColumn(),
    'packages' => (int) $db->query('SELECT COUNT(*) FROM packages')->fetchColumn(),
    'bookings' => (int) $db->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
    'confirmed' => (int) $db->query("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed'")->fetchColumn(),
];
$statusLabels = ['pending' => 'Pendente', 'confirmed' => 'Confirmada', 'cancelled' => 'Cancelada'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VivaMundo | Agência de viagens</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="?page=dashboard"><span class="brand-mark">V</span><span>Viva<span class="brand-light">Mundo</span><small>AGÊNCIA DE VIAGENS</small></span></a>
    <p class="nav-label">MENU PRINCIPAL</p>
    <nav>
        <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard"><span>⌂</span> Visão geral</a>
        <a class="<?= $page === 'clients' ? 'active' : '' ?>" href="?page=clients"><span>♙</span> Clientes</a>
        <a class="<?= $page === 'packages' ? 'active' : '' ?>" href="?page=packages"><span>✈</span> Pacotes</a>
        <a class="<?= $page === 'bookings' ? 'active' : '' ?>" href="?page=bookings"><span>▤</span> Reservas</a>
    </nav>
    <div class="sidebar-note"><span>✦</span><strong>O mundo espera por você.</strong><p>Organize viagens inesquecíveis em um só lugar.</p></div>
    <div class="sidebar-footer">PAINEL ADMINISTRATIVO <span>v1.0</span></div>
</aside>
<main class="main">
    <header class="topbar"><div class="breadcrumb">VivaMundo <span>/</span> <?= h(match ($page) { 'clients' => 'Clientes', 'packages' => 'Pacotes', 'bookings' => 'Reservas', default => 'Visão geral' }) ?></div><div class="user-chip"><span class="avatar">A</span><span>Administrador<small>Agência VivaMundo</small></span></div></header>
    <div class="content">
        <?php if ($flash): ?>
            <div class="flash <?= h($flash['type']) ?>" role="status"><?= h($flash['text']) ?></div>
        <?php endif; ?>

        <?php if ($page === 'dashboard'): ?>
            <section class="page-heading"><div><p class="eyebrow">SEJA BEM-VINDO(A) 👋</p><h1>Seu próximo destino começa aqui.</h1><p>Acompanhe sua agência e deixe cada viagem mais especial.</p></div><a class="button primary" href="?page=bookings">＋ Nova reserva</a></section>
            <section class="stats">
                <article class="stat-card"><span class="stat-icon blue">♙</span><div><p>Clientes cadastrados</p><strong><?= $counts['clients'] ?></strong></div><small>na sua agência</small></article>
                <article class="stat-card"><span class="stat-icon violet">✈</span><div><p>Pacotes disponíveis</p><strong><?= $counts['packages'] ?></strong></div><small>viagens cadastradas</small></article>
                <article class="stat-card"><span class="stat-icon orange">▤</span><div><p>Total de reservas</p><strong><?= $counts['bookings'] ?></strong></div><small><?= $counts['confirmed'] ?> confirmadas</small></article>
            </section>
            <section class="panel"><div class="panel-heading"><div><h2>Reservas recentes</h2><p>Veja as últimas movimentações da sua agência.</p></div><a class="text-link" href="?page=bookings">Ver todas <span>→</span></a></div>
                <?php if (!$bookings): ?><div class="empty-state"><span>✈</span><strong>Nenhuma reserva por aqui ainda</strong><p>Cadastre pacotes e clientes para criar sua primeira reserva.</p><a class="button secondary" href="?page=bookings">Criar reserva</a></div>
                <?php else: ?><div class="table-wrap"><table><thead><tr><th>CLIENTE</th><th>PACOTE</th><th>VIAJANTES</th><th>STATUS</th></tr></thead><tbody>
                    <?php foreach (array_slice($bookings, 0, 5) as $booking): ?><tr><td><strong><?= h($booking['client_name']) ?></strong></td><td><?= h($booking['package_title']) ?><small class="cell-sub"><?= h($booking['destination']) ?></small></td><td><?= (int) $booking['travelers'] ?> pessoa(s)</td><td><span class="badge <?= h($booking['status']) ?>"><?= h($statusLabels[$booking['status']] ?? $booking['status']) ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </section>

        <?php elseif ($page === 'clients'): ?>
            <section class="page-heading"><div><p class="eyebrow">RELACIONAMENTO</p><h1>Clientes</h1><p>Gerencie as informações dos viajantes da sua agência.</p></div></section>
            <div class="management-grid">
                <section class="panel form-panel"><div class="panel-heading"><div><h2><?= $editing ? 'Editar cliente' : 'Novo cliente' ?></h2><p>Preencha os dados do viajante.</p></div></div>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_client"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                        <label>Nome completo<input name="name" required maxlength="120" placeholder="Ex.: Ana Silva" value="<?= h($editing['name'] ?? '') ?>"></label>
                        <label>E-mail<input name="email" type="email" required maxlength="190" placeholder="ana@email.com" value="<?= h($editing['email'] ?? '') ?>"></label>
                        <label>Telefone <span class="optional">(opcional)</span><input name="phone" maxlength="40" placeholder="+55 (11) 99999-9999" value="<?= h($editing['phone'] ?? '') ?>"></label>
                        <div class="form-actions"><button class="button primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Cadastrar cliente' ?></button><?php if ($editing): ?><a class="button secondary" href="?page=clients">Cancelar</a><?php endif; ?></div>
                    </form>
                </section>
                <section class="panel list-panel"><div class="panel-heading"><div><h2>Lista de clientes</h2><p><?= count($clients) ?> cadastrado(s)</p></div></div>
                    <?php if (!$clients): ?><div class="empty-state compact"><span>♙</span><strong>Nenhum cliente cadastrado</strong><p>Adicione o primeiro cliente usando o formulário.</p></div><?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>CLIENTE</th><th>TELEFONE</th><th>AÇÕES</th></tr></thead><tbody>
                        <?php foreach ($clients as $client): ?><tr><td><strong><?= h($client['name']) ?></strong><small class="cell-sub"><?= h($client['email']) ?></small></td><td><?= h($client['phone'] ?: '—') ?></td><td class="actions"><a class="icon-button" href="?page=clients&amp;edit=<?= (int) $client['id'] ?>" aria-label="Editar cliente">✎</a><form method="post" onsubmit="return confirm('Remover este cliente?')"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_client"><input type="hidden" name="id" value="<?= (int) $client['id'] ?>"><button class="icon-button danger" aria-label="Remover cliente">×</button></form></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
                </section>
            </div>

        <?php elseif ($page === 'packages'): ?>
            <section class="page-heading"><div><p class="eyebrow">DESTINOS E EXPERIÊNCIAS</p><h1>Pacotes de viagem</h1><p>Crie e organize as próximas aventuras dos seus clientes.</p></div></section>
            <div class="management-grid">
                <section class="panel form-panel"><div class="panel-heading"><div><h2><?= $editing ? 'Editar pacote' : 'Novo pacote' ?></h2><p>Adicione os detalhes da viagem.</p></div></div>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_package"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                        <label>Nome do pacote<input name="title" required maxlength="120" placeholder="Ex.: Férias em Lisboa" value="<?= h($editing['title'] ?? '') ?>"></label>
                        <label>Destino<input name="destination" required maxlength="120" placeholder="Ex.: Lisboa, Portugal" value="<?= h($editing['destination'] ?? '') ?>"></label>
                        <div class="field-row"><label>Data de ida<input name="departure_date" type="date" required value="<?= h($editing['departure_date'] ?? '') ?>"></label><label>Data de volta<input name="return_date" type="date" required value="<?= h($editing['return_date'] ?? '') ?>"></label></div>
                        <div class="field-row"><label>Preço por pessoa (R$)<input name="price" type="number" min="0" step="0.01" required placeholder="0,00" value="<?= h($editing['price'] ?? '') ?>"></label><label>Capacidade total (vagas)<input name="seat_capacity" type="number" min="0" step="1" required value="<?= h($editing['seat_capacity'] ?? '10') ?>"></label></div>
                        <label>Descrição <span class="optional">(opcional)</span><textarea name="description" rows="3" maxlength="1000" placeholder="Conte um pouco sobre essa experiência..."><?= h($editing['description'] ?? '') ?></textarea></label>
                        <div class="form-actions"><button class="button primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Cadastrar pacote' ?></button><?php if ($editing): ?><a class="button secondary" href="?page=packages">Cancelar</a><?php endif; ?></div>
                    </form>
                </section>
                <section class="panel list-panel"><div class="panel-heading"><div><h2>Pacotes cadastrados</h2><p><?= count($packages) ?> pacote(s)</p></div></div>
                    <?php if (!$packages): ?><div class="empty-state compact"><span>✈</span><strong>Nenhum pacote cadastrado</strong><p>Cadastre um pacote para começar a receber reservas.</p></div><?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>PACOTE</th><th>PERÍODO</th><th>PREÇO</th><th>VAGAS</th><th>AÇÕES</th></tr></thead><tbody>
                        <?php foreach ($packages as $package): ?><tr><td><strong><?= h($package['title']) ?></strong><small class="cell-sub"><?= h($package['destination']) ?></small></td><td><?= h(date('d/m/Y', strtotime($package['departure_date']))) ?><small class="cell-sub">até <?= h(date('d/m/Y', strtotime($package['return_date']))) ?></small></td><td>R$ <?= h(number_format((float) $package['price'], 2, ',', '.')) ?></td><td><?= (int) $package['remaining_seats'] ?> de <?= (int) $package['seat_capacity'] ?></td><td class="actions"><a class="icon-button" href="?page=packages&amp;edit=<?= (int) $package['id'] ?>" aria-label="Editar pacote">✎</a><form method="post" onsubmit="return confirm('Remover este pacote?')"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_package"><input type="hidden" name="id" value="<?= (int) $package['id'] ?>"><button class="icon-button danger" aria-label="Remover pacote">×</button></form></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
                </section>
            </div>

        <?php else: ?>
            <section class="page-heading"><div><p class="eyebrow">VENDAS E ATENDIMENTO</p><h1>Reservas</h1><p>Registre e acompanhe as viagens dos seus clientes.</p></div></section>
            <div class="management-grid">
                <section class="panel form-panel"><div class="panel-heading"><div><h2>Nova reserva</h2><p>Selecione um cliente e um pacote.</p></div></div>
                    <?php if (!$clients || !$packages): ?><div class="notice">Para criar uma reserva, cadastre pelo menos um cliente e um pacote. <a href="?page=clients">Clientes</a> · <a href="?page=packages">Pacotes</a></div><?php else: ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_booking">
                        <label>Cliente<select name="client_id" required><option value="">Selecione um cliente</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= h($client['name']) ?> — <?= h($client['email']) ?></option><?php endforeach; ?></select></label>
                        <label>Pacote<select name="package_id" required><option value="">Selecione um pacote</option><?php foreach ($packages as $package): ?><option value="<?= (int) $package['id'] ?>" <?= (int) $package['remaining_seats'] < 1 ? 'disabled' : '' ?>><?= h($package['title']) ?> — <?= h($package['destination']) ?> (<?= (int) $package['remaining_seats'] ?> vagas, R$ <?= h(number_format((float) $package['price'], 2, ',', '.')) ?>)</option><?php endforeach; ?></select></label>
                        <label>Quantidade de viajantes<input name="travelers" type="number" min="1" step="1" value="1" required></label>
                        <div class="form-actions"><button class="button primary" type="submit">Criar reserva</button></div>
                    </form><?php endif; ?>
                </section>
                <section class="panel list-panel"><div class="panel-heading"><div><h2>Reservas cadastradas</h2><p><?= count($bookings) ?> reserva(s)</p></div></div>
                    <?php if (!$bookings): ?><div class="empty-state compact"><span>▤</span><strong>Nenhuma reserva cadastrada</strong><p>As novas reservas aparecerão nesta lista.</p></div><?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>CLIENTE</th><th>VIAGEM</th><th>PESSOAS</th><th>STATUS</th><th>AÇÕES</th></tr></thead><tbody>
                        <?php foreach ($bookings as $booking): ?><tr><td><strong><?= h($booking['client_name']) ?></strong></td><td><?= h($booking['package_title']) ?><small class="cell-sub"><?= h($booking['destination']) ?></small></td><td><?= (int) $booking['travelers'] ?></td><td><form method="post" class="status-form"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_booking"><input type="hidden" name="id" value="<?= (int) $booking['id'] ?>"><select name="status" aria-label="Status da reserva" onchange="this.form.submit()" class="status-select <?= h($booking['status']) ?>"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= h($value) ?>" <?= $booking['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></form></td><td class="actions"><form method="post" onsubmit="return confirm('Remover esta reserva?')"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_booking"><input type="hidden" name="id" value="<?= (int) $booking['id'] ?>"><button class="icon-button danger" aria-label="Remover reserva">×</button></form></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
        <footer class="footer">VivaMundo <span>·</span> Feito para transformar viagens em boas lembranças.</footer>
    </div>
</main>
</body>
</html>
