<?php
declare(strict_types=1);

/*
 * Bootstrap compartilhado pelos módulos novos (painel, pagamentos, viagens, relatórios).
 * Usa o mesmo config.php, o mesmo banco e a mesma sessão/CSRF do index.php,
 * que permanece intacto.
 */

require __DIR__ . '/auth.php';
authStart();

$configPath = dirname(__DIR__) . '/config.php';
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

/* ---- Tabelas originais (idênticas às do index.php) ---- */
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

/* ---- Tabelas novas (criadas automaticamente, sem alterar as originais) ---- */
$db->exec(
    "CREATE TABLE IF NOT EXISTS payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        method VARCHAR(20) NOT NULL DEFAULT 'pix',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        due_date DATE NOT NULL,
        paid_at DATE NULL,
        notes VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_payments_booking (booking_id),
        CHECK (amount > 0),
        CHECK (status IN ('pending', 'paid', 'refunded')),
        CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS trip_control (
        package_id BIGINT UNSIGNED NOT NULL,
        state VARCHAR(16) NOT NULL DEFAULT 'open',
        original_capacity INT UNSIGNED NULL,
        blocked_capacity INT UNSIGNED NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (package_id),
        CHECK (state IN ('open', 'suspended', 'cancelled')),
        CONSTRAINT fk_trip_control_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$db->exec(
    'CREATE TABLE IF NOT EXISTS trip_notes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        package_id BIGINT UNSIGNED NOT NULL,
        note VARCHAR(500) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_trip_notes_package (package_id),
        CONSTRAINT fk_trip_notes_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

/* ---- Autenticação: toda página exige login, exceto as que definem PUBLIC_PAGE ---- */
authDb($db);
authEnsureSchema();
require_once __DIR__ . '/bookings.php';
if (!defined('PUBLIC_PAGE')) {
    $currentUser = defined('CLIENT_PAGE') ? authRequireClient() : authRequireInternal();
}

/* ---- Constantes de domínio ---- */
const BOOKING_LABELS = ['pending' => 'Pendente', 'confirmed' => 'Confirmada', 'cancelled' => 'Cancelada'];
const PAYMENT_STATUS_LABELS = ['pending' => 'A receber', 'paid' => 'Recebido', 'refunded' => 'Estornado'];
const PAYMENT_METHODS = [
    'pix' => 'Pix',
    'credit_card' => 'Cartão de crédito',
    'debit_card' => 'Cartão de débito',
    'boleto' => 'Boleto',
    'transfer' => 'Transferência',
    'cash' => 'Dinheiro',
];
const TRIP_STATE_LABELS = ['open' => 'Vendas abertas', 'suspended' => 'Vendas suspensas', 'cancelled' => 'Cancelada'];

/* ---- Helpers ---- */
function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postString(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
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

function flash(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function today(): string
{
    return date('Y-m-d');
}

function validDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function brDate(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '—';
}

/** Converte valor monetário (DECIMAL/float) em centavos inteiros. */
function toCents(string|int|float|null $value): int
{
    return (int) round(((float) $value) * 100);
}

function money(string|int|float|null $value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function moneyCents(int $cents): string
{
    return money($cents / 100);
}

/** Soma meses preservando o último dia do mês (31/01 + 1 mês = 28 ou 29/02). */
function addMonths(string $date, int $months): string
{
    $base = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $day = (int) $base->format('j');
    $first = $base->modify('first day of this month')->modify("+{$months} month");
    $last = (int) $first->format('t');
    return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, $last))->format('Y-m-d');
}

/**
 * Reservas com a posição financeira calculada.
 * Total = preço do pacote × viajantes. Recebido = pagamentos "paid".
 * A data de hoje é gerada pelo servidor (não vem do usuário), por isso é segura no SQL.
 */
function bookingFinanceSql(string $where = '1=1', string $order = 'b.created_at DESC, b.id DESC'): string
{
    $today = "'" . today() . "'";
    return "SELECT b.id, b.client_id, b.package_id, b.travelers, b.status, b.created_at,
            c.name AS client_name, c.email AS client_email,
            p.title AS package_title, p.destination, p.departure_date, p.return_date, p.price,
            ROUND(p.price * b.travelers, 2) AS total,
            COALESCE(SUM(CASE WHEN py.status = 'paid' THEN py.amount END), 0) AS paid,
            COALESCE(SUM(CASE WHEN py.status = 'pending' THEN py.amount END), 0) AS scheduled,
            COALESCE(SUM(CASE WHEN py.status = 'pending' AND py.due_date < {$today} THEN py.amount END), 0) AS overdue,
            MIN(CASE WHEN py.status = 'pending' AND py.due_date < {$today} THEN py.due_date END) AS oldest_overdue,
            COALESCE(SUM(CASE WHEN py.status = 'refunded' THEN py.amount END), 0) AS refunded,
            COUNT(py.id) AS payment_count
        FROM bookings b
        JOIN clients c ON c.id = b.client_id
        JOIN packages p ON p.id = b.package_id
        LEFT JOIN payments py ON py.booking_id = b.id
        WHERE {$where}
        GROUP BY b.id, b.client_id, b.package_id, b.travelers, b.status, b.created_at,
                 c.name, c.email, p.title, p.destination, p.departure_date, p.return_date, p.price
        ORDER BY {$order}";
}

/** Situação de pagamento de uma reserva (linha de bookingFinanceSql). */
function paymentSituation(array $b): array
{
    $total = toCents($b['total']);
    $paid = toCents($b['paid']);
    if ($b['status'] === 'cancelled') {
        return $paid > 0 ? ['refund', 'Estorno pendente'] : ['cancelled', 'Cancelada'];
    }
    if (toCents($b['overdue']) > 0) {
        return ['overdue', 'Em atraso'];
    }
    if ($total > 0 && $paid >= $total) {
        return ['paid', 'Quitado'];
    }
    if ($paid > 0) {
        return ['partial', 'Parcial'];
    }
    return ['open', 'Em aberto'];
}

/** Situação calculada de uma viagem (linha de tripOverviewSql). */
function tripSituation(array $t): array
{
    $now = today();
    if (($t['state'] ?? 'open') === 'cancelled') {
        return ['cancelled', 'Cancelada'];
    }
    if ($t['return_date'] < $now) {
        return ['done', 'Concluída'];
    }
    if ($t['departure_date'] <= $now) {
        return ['running', 'Em andamento'];
    }
    if (($t['state'] ?? 'open') === 'suspended') {
        return ['suspended', 'Vendas suspensas'];
    }
    if ((int) $t['remaining_seats'] < 1) {
        return ['full', 'Lotada'];
    }
    return ['sale', 'Em venda'];
}

/** Viagens (pacotes) com ocupação, financeiro e estado de controle. */
function tripOverviewSql(string $where = '1=1', string $order = 'p.departure_date, p.id'): string
{
    return "SELECT p.*, COALESCE(tc.state, 'open') AS state, tc.original_capacity, tc.blocked_capacity,
            COALESCE(bk.reserved, 0) AS reserved,
            p.seat_capacity - COALESCE(bk.reserved, 0) AS remaining_seats,
            COALESCE(bk.confirmed_bookings, 0) AS confirmed_bookings,
            COALESCE(bk.pending_bookings, 0) AS pending_bookings
        FROM packages p
        LEFT JOIN trip_control tc ON tc.package_id = p.id
        LEFT JOIN (
            SELECT package_id,
                   SUM(travelers) AS reserved,
                   SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
                   SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_bookings
            FROM bookings WHERE status <> 'cancelled' GROUP BY package_id
        ) bk ON bk.package_id = p.id
        WHERE {$where}
        ORDER BY {$order}";
}

/** Recebido por pacote: [package_id => centavos]. */
function receivedByPackage(PDO $db): array
{
    $rows = $db->query(
        "SELECT b.package_id, COALESCE(SUM(py.amount), 0) AS received
         FROM payments py JOIN bookings b ON b.id = py.booking_id
         WHERE py.status = 'paid' GROUP BY b.package_id"
    )->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['package_id']] = toCents($row['received']);
    }
    return $map;
}

function occupationPercent(int $reserved, int $capacity): int
{
    return $capacity > 0 ? (int) min(100, round($reserved / $capacity * 100)) : 0;
}

/** Mensagem amigável para falhas de banco. */
function dbErrorMessage(PDOException $exception): string
{
    $m = strtolower($exception->getMessage());
    if (str_contains($m, 'foreign key')) {
        return 'Não é possível concluir: existem registros vinculados (por exemplo, pagamentos da reserva).';
    }
    return 'Não foi possível salvar os dados. Confira as informações e tente novamente.';
}
