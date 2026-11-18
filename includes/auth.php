<?php
declare(strict_types=1);

/*
 * Autenticação compartilhada. Todas as funções usam o prefixo "auth" para não
 * colidir com as funções do index.php original (h, redirect, postString, requireCsrf).
 */

date_default_timezone_set('America/Sao_Paulo');

const AUTH_ROLES = ['admin' => 'Administrador', 'staff' => 'Funcionário', 'client' => 'Cliente'];
const AUTH_INTERNAL_ROLES = ['admin' => 'Administrador', 'staff' => 'Funcionário'];
const AUTH_MAX_FAILS = 5;
const AUTH_LOCK_MINUTES = 10;

function authStart(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function authE(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Conexão com o MySQL (reaproveita a do bootstrap quando informada). */
function authDb(?PDO $inject = null): PDO
{
    static $pdo = null;
    if ($inject !== null) {
        $pdo = $inject;
    }
    if ($pdo === null) {
        $configPath = dirname(__DIR__) . '/config.php';
        if (!is_file($configPath)) {
            http_response_code(500);
            exit('Arquivo config.php ausente. Copie config.example.php para config.php e informe os dados do MySQL.');
        }
        $cfg = require $configPath;
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['database']),
                $cfg['username'],
                $cfg['password'],
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
    }
    return $pdo;
}

function authEnsureSchema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db = authDb();
    $clientsExist = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'clients'"
    )->fetchColumn() > 0;
    $clientForeignKey = $clientsExist
        ? ', CONSTRAINT fk_users_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT'
        : '';
    $db->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(16) NOT NULL DEFAULT 'staff',
            client_id BIGINT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 0,
            failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            last_login_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_email (email),
            UNIQUE KEY uq_users_client_id (client_id),
            CONSTRAINT chk_users_role CHECK (role IN ('admin', 'staff', 'client'))
            {$clientForeignKey}
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = $db->query('SHOW COLUMNS FROM users')->fetchAll();
    if (!in_array('client_id', array_column($columns, 'Field'), true)) {
        $db->exec('ALTER TABLE users ADD COLUMN client_id BIGINT UNSIGNED NULL');
    }

    $index = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_client_id'"
    );
    $index->execute();
    if ((int) $index->fetchColumn() === 0) {
        $db->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_client_id (client_id)');
    }

    $roleChecks = $db->query(
        "SELECT tc.constraint_name AS constraint_name, cc.check_clause AS check_clause
         FROM information_schema.table_constraints tc
         JOIN information_schema.check_constraints cc
           ON cc.constraint_schema = tc.constraint_schema
          AND cc.constraint_name = tc.constraint_name
         WHERE tc.constraint_schema = DATABASE()
           AND tc.table_name = 'users'
           AND tc.constraint_type = 'CHECK'"
    )->fetchAll();
    $hasClientRoleCheck = false;
    foreach ($roleChecks as $check) {
        $clause = strtolower((string) $check['check_clause']);
        if (!str_contains($clause, 'role')) {
            continue;
        }
        if (str_contains($clause, "'client'")) {
            $hasClientRoleCheck = true;
            continue;
        }
        $constraint = str_replace('`', '``', (string) $check['constraint_name']);
        $db->exec("ALTER TABLE users DROP CHECK `{$constraint}`");
    }
    if (!$hasClientRoleCheck) {
        $db->exec("ALTER TABLE users ADD CONSTRAINT chk_users_role CHECK (role IN ('admin', 'staff', 'client'))");
    }

    if ($clientsExist) {
        $foreignKey = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'client_id'
               AND referenced_table_name = 'clients' AND referenced_column_name = 'id'"
        );
        $foreignKey->execute();
        if ((int) $foreignKey->fetchColumn() === 0) {
            $db->exec(
                'ALTER TABLE users ADD CONSTRAINT fk_users_client
                 FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT'
            );
        }
    }
    $done = true;
}

function authCsrfToken(): string
{
    authStart();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function authUserCount(): int
{
    authEnsureSchema();
    return (int) authDb()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

/** Usuário logado e ativo, ou null. */
function authCurrentUser(): ?array
{
    authStart();
    authEnsureSchema();
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id < 1) {
        return null;
    }
    $stmt = authDb()->prepare('SELECT id, name, email, role, client_id, active FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['active'] !== 1) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

/** Exige login; sem sessão válida redireciona para o login (ou cadastro, se não há usuários). */
function authRequireLogin(): array
{
    $user = authCurrentUser();
    if ($user) {
        return $user;
    }
    header('Location: ' . (authUserCount() === 0 ? 'setup.php' : 'login.php'));
    exit;
}

function authHomeUrl(?array $user): string
{
    return $user !== null && $user['role'] === 'client' ? 'cliente.php' : 'painel.php';
}

function authRequireInternal(): array
{
    $user = authRequireLogin();
    if (!isset(AUTH_INTERNAL_ROLES[$user['role']])) {
        header('Location: cliente.php');
        exit;
    }
    return $user;
}

function authRequireClient(): array
{
    $user = authRequireLogin();
    if ($user['role'] !== 'client') {
        header('Location: ' . authHomeUrl($user));
        exit;
    }
    if ((int) ($user['client_id'] ?? 0) < 1) {
        http_response_code(403);
        exit('Esta conta de cliente não está vinculada a um cadastro válido.');
    }
    return $user;
}

function authIsAdmin(?array $user): bool
{
    return $user !== null && $user['role'] === 'admin';
}

function authValidateNewUser(string $name, string $email, string $password, string $confirm): ?string
{
    $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($len < 2 || $len > 120) {
        return 'Informe o nome completo (2 a 120 caracteres).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        return 'Informe um e-mail válido.';
    }
    return authValidatePassword($password, $confirm);
}

function authValidatePassword(string $password, string $confirm): ?string
{
    if (strlen($password) < 8) {
        return 'A senha precisa ter pelo menos 8 caracteres.';
    }
    if (strlen($password) > 72) {
        return 'A senha pode ter no máximo 72 caracteres.';
    }
    if ($password !== $confirm) {
        return 'A confirmação de senha não confere.';
    }
    return null;
}

/** @return array{ok: bool, message: string} */
function authLogin(string $email, string $password): array
{
    authStart();
    $db = authDb();
    $generic = 'E-mail ou senha incorretos.';
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([strtolower($email)]);
    $user = $stmt->fetch();

    if (!$user) {
        password_verify($password, '$2y$10$usesomesillystringforsaltOabcdefghijklmnopqrstuvwxyz12'); // tempo similar
        return ['ok' => false, 'message' => $generic];
    }
    if ($user['locked_until'] !== null && $user['locked_until'] > date('Y-m-d H:i:s')) {
        return ['ok' => false, 'message' => 'Muitas tentativas incorretas. Aguarde alguns minutos e tente novamente.'];
    }
    if (!password_verify($password, $user['password_hash'])) {
        $fails = (int) $user['failed_attempts'] + 1;
        $lock = $fails >= AUTH_MAX_FAILS ? date('Y-m-d H:i:s', time() + AUTH_LOCK_MINUTES * 60) : null;
        $db->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
            ->execute([$lock !== null ? 0 : $fails, $lock, $user['id']]);
        return ['ok' => false, 'message' => $generic];
    }
    if ((int) $user['active'] !== 1) {
        $message = $user['role'] === 'client'
            ? 'Sua conta foi desativada. Entre em contato com a agência.'
            : 'Sua conta ainda está aguardando aprovação de um administrador.';
        return ['ok' => false, 'message' => $message];
    }

    $db->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $user['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return ['ok' => true, 'message' => ''];
}

function authLogout(): void
{
    authStart();
    $_SESSION = [];
    session_regenerate_id(true);
}

function authRegisterInitialAdmin(string $name, string $email, string $password): int
{
    $db = authDb();
    $database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'agency_initial_admin_' . substr(hash('sha256', $database), 0, 24);
    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Não foi possível iniciar a configuração. Tente novamente.');
    }

    try {
        $db->beginTransaction();
        if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
            throw new RuntimeException('A configuração inicial já foi concluída.');
        }
        $db->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)')
            ->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT), 'admin']);
        $id = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
    return $id;
}

function authRegisterClient(string $name, string $email, string $phone, string $password): int
{
    $db = authDb();
    $db->beginTransaction();
    try {
        if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            throw new RuntimeException('A configuração inicial da agência ainda não foi concluída.');
        }
        $existingUser = $db->prepare('SELECT id FROM users WHERE email = ? FOR UPDATE');
        $existingUser->execute([strtolower($email)]);
        if ($existingUser->fetchColumn()) {
            throw new RuntimeException('Este e-mail já possui uma conta. Faça login para continuar.');
        }

        $existingClient = $db->prepare('SELECT id FROM clients WHERE email = ? FOR UPDATE');
        $existingClient->execute([strtolower($email)]);
        if ($existingClient->fetchColumn()) {
            throw new RuntimeException('Este e-mail já consta no cadastro da agência. Entre em contato para vincular sua conta.');
        }

        $db->prepare('INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)')
            ->execute([$name, strtolower($email), $phone]);
        $clientId = (int) $db->lastInsertId();
        $db->prepare(
            "INSERT INTO users (name, email, password_hash, role, client_id, active)
             VALUES (?, ?, ?, 'client', ?, 1)"
        )->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT), $clientId]);
        $id = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return $id;
}

function authInitial(string $name): string
{
    $first = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
}

/** Bloco "usuário + Sair" usado no topo das páginas novas e injetado nas páginas originais. */
function authUserChip(array $user, string $csrf): string
{
    return '<div class="topbar-user"><div class="user-chip"><span class="avatar">' . authE(authInitial((string) $user['name'])) . '</span><span>'
        . authE($user['name']) . '<small>' . authE(AUTH_ROLES[$user['role']] ?? $user['role']) . '</small></span></div>'
        . '<form method="post" action="logout.php" class="logout-form"><input type="hidden" name="csrf_token" value="' . authE($csrf) . '">'
        . '<button class="button secondary small" type="submit">Sair</button></form></div>';
}

/**
 * Acrescenta ao HTML do index.php original (sem editá-lo) o menu de gestão, o usuário logado e o botão Sair.
 * Se o marcador esperado não existir, a parte correspondente é simplesmente ignorada.
 */
function authDecorateLegacyHtml(string $html, array $user, string $csrf): string
{
    if (!str_contains($html, '<aside class="sidebar">')) {
        return $html;
    }
    $link = static fn(string $href, string $icon, string $label): string => '<a href="' . $href . '"><span>' . $icon . '</span> ' . $label . '</a>';
    $menu = '</nav><p class="nav-label nav-label-tight">GESTÃO</p><nav>'
        . $link('painel.php', '◔', 'Painel gerencial')
        . $link('pagamentos.php', '＄', 'Pagamentos')
        . $link('viagens.php', '⚑', 'Situação das viagens')
        . $link('relatorios.php', '☰', 'Relatórios')
        . $link('usuarios.php', '⚙', authIsAdmin($user) ? 'Usuários' : 'Minha conta')
        . '</nav>';
    $pos = strpos($html, '</nav>');
    if ($pos !== false) {
        $html = substr_replace($html, $menu, $pos, strlen('</nav>'));
    }
    $html = str_replace(
        '<link rel="stylesheet" href="assets/style.css">',
        '<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/extra.css">',
        $html
    );
    return str_replace(
        '<div class="user-chip"><span class="avatar">A</span><span>Administrador<small>Agência VivaMundo</small></span></div>',
        authUserChip($user, $csrf),
        $html
    );
}
