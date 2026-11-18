<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$views = ['all' => 'Todos', 'pending' => 'A receber', 'overdue' => 'Em atraso', 'paid' => 'Recebidos', 'refunded' => 'Estornados'];
$view = (string) ($_GET['view'] ?? 'all');
if (!isset($views[$view])) {
    $view = 'all';
}

/* ------------------------------------------------------------------ POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $backView = (string) ($_POST['view'] ?? 'all');
    $back = 'pagamentos.php' . (isset($views[$backView]) && $backView !== 'all' ? '?view=' . $backView : '');

    try {
        requireCsrf();
        $action = postString('action');

        switch ($action) {
            case 'create_payments':
                $bookingId = (int) ($_POST['booking_id'] ?? 0);
                $method = postString('method');
                $amount = filter_var(str_replace(',', '.', postString('amount')), FILTER_VALIDATE_FLOAT);
                $installments = filter_var($_POST['installments'] ?? 1, FILTER_VALIDATE_INT);
                $firstDue = postString('due_date');
                $firstPaid = isset($_POST['first_paid']);

                if ($bookingId < 1 || !isset(PAYMENT_METHODS[$method]) || $amount === false || $amount <= 0
                    || $installments === false || $installments < 1 || $installments > 12 || !validDate($firstDue)) {
                    throw new RuntimeException('Confira a reserva, a forma de pagamento, o valor, as parcelas e o vencimento.');
                }

                $db->beginTransaction();
                try {
                    $lock = $db->prepare('SELECT status FROM bookings WHERE id = ? FOR UPDATE');
                    $lock->execute([$bookingId]);
                    $bookingStatus = $lock->fetchColumn();
                    if ($bookingStatus === false) {
                        throw new RuntimeException('Reserva não encontrada.');
                    }
                    if ($bookingStatus === 'cancelled') {
                        throw new RuntimeException('Não é possível lançar pagamentos em reserva cancelada.');
                    }

                    $stmt = $db->prepare(bookingFinanceSql('b.id = ?'));
                    $stmt->execute([$bookingId]);
                    $booking = $stmt->fetch();
                    if (!$booking) {
                        throw new RuntimeException('Reserva não encontrada.');
                    }
                    $amountCents = toCents($amount);
                    $openCents = toCents($booking['total']) - toCents($booking['paid']) - toCents($booking['scheduled']);
                    if ($amountCents > $openCents) {
                        throw new RuntimeException('O valor excede o saldo ainda não lançado desta reserva (' . moneyCents(max(0, $openCents)) . ').');
                    }
                    if ($amountCents < $installments) {
                        throw new RuntimeException('O valor é pequeno demais para essa quantidade de parcelas.');
                    }

                    $base = intdiv($amountCents, $installments);
                    $remainder = $amountCents - $base * $installments;
                    $insert = $db->prepare(
                        'INSERT INTO payments (booking_id, amount, method, status, due_date, paid_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    for ($i = 0; $i < $installments; $i++) {
                        $cents = $base + ($i === $installments - 1 ? $remainder : 0);
                        $isPaid = $i === 0 && $firstPaid;
                        $insert->execute([
                            $bookingId,
                            number_format($cents / 100, 2, '.', ''),
                            $method,
                            $isPaid ? 'paid' : 'pending',
                            addMonths($firstDue, $i),
                            $isPaid ? today() : null,
                            $installments > 1 ? 'Parcela ' . ($i + 1) . '/' . $installments : '',
                        ]);
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
                flash('success', $installments > 1 ? "{$installments} parcelas lançadas com sucesso." : 'Pagamento lançado com sucesso.');
                break;

            case 'mark_paid':
                $stmt = $db->prepare(
                    "UPDATE payments py JOIN bookings b ON b.id = py.booking_id
                     SET py.status = 'paid', py.paid_at = ?
                     WHERE py.id = ? AND py.status = 'pending' AND b.status <> 'cancelled'"
                );
                $stmt->execute([today(), (int) ($_POST['id'] ?? 0)]);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('Não foi possível baixar este pagamento (já recebido ou reserva cancelada).');
                }
                flash('success', 'Pagamento marcado como recebido.');
                break;

            case 'mark_pending':
                $stmt = $db->prepare("UPDATE payments SET status = 'pending', paid_at = NULL WHERE id = ? AND status = 'paid'");
                $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                flash('success', 'Pagamento voltou para "a receber".');
                break;

            case 'refund_payment':
                $stmt = $db->prepare("UPDATE payments SET status = 'refunded' WHERE id = ? AND status = 'paid'");
                $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('Somente pagamentos recebidos podem ser estornados.');
                }
                flash('success', 'Pagamento estornado.');
                break;

            case 'delete_payment':
                $stmt = $db->prepare("DELETE FROM payments WHERE id = ? AND status IN ('pending', 'refunded')");
                $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('Pagamentos recebidos não podem ser removidos; estorne-os primeiro.');
                }
                flash('success', 'Lançamento removido.');
                break;

            default:
                throw new RuntimeException('Ação inválida.');
        }
    } catch (PDOException $exception) {
        flash('error', dbErrorMessage($exception));
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
    }
    redirectTo($back);
}

/* ------------------------------------------------------------------ DADOS */
$todayLiteral = "'" . today() . "'";
$totals = $db->query(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END), 0) AS received,
        COALESCE(SUM(CASE WHEN status = 'pending' AND due_date >= {$todayLiteral} THEN amount END), 0) AS upcoming,
        COALESCE(SUM(CASE WHEN status = 'pending' AND due_date < {$todayLiteral} THEN amount END), 0) AS overdue,
        COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount END), 0) AS refunded
     FROM payments"
)->fetch();

$viewWhere = match ($view) {
    'pending' => "py.status = 'pending'",
    'overdue' => "py.status = 'pending' AND py.due_date < {$todayLiteral}",
    'paid' => "py.status = 'paid'",
    'refunded' => "py.status = 'refunded'",
    default => '1=1',
};
$payments = $db->query(
    "SELECT py.*, c.name AS client_name, p.title AS package_title, b.status AS booking_status
     FROM payments py
     JOIN bookings b ON b.id = py.booking_id
     JOIN clients c ON c.id = b.client_id
     JOIN packages p ON p.id = b.package_id
     WHERE {$viewWhere}
     ORDER BY CASE WHEN py.status = 'pending' THEN 0 ELSE 1 END,
              CASE WHEN py.status = 'pending' THEN py.due_date END ASC,
              py.id DESC
     LIMIT 300"
)->fetchAll();

$finance = $db->query(bookingFinanceSql())->fetchAll();
$payable = array_values(array_filter($finance, static function (array $b): bool {
    return $b['status'] !== 'cancelled'
        && toCents($b['total']) - toCents($b['paid']) - toCents($b['scheduled']) > 0;
}));

/* ------------------------------------------------------------------ VIEW */
renderHeader('pagamentos', 'Pagamentos', 'Pagamentos');
?>
<section class="page-heading"><div><p class="eyebrow">FINANCEIRO</p><h1>Controle de pagamentos</h1><p>Lance entradas e parcelas, baixe recebimentos e acompanhe a inadimplência.</p></div><a class="button secondary" href="relatorios.php">☰ Relatórios</a></section>

<section class="stats four">
    <article class="stat-card"><span class="stat-icon green">＄</span><div><p>Recebido</p><strong class="money"><?= h(money($totals['received'])) ?></strong></div><small>pagamentos baixados</small></article>
    <article class="stat-card"><span class="stat-icon blue">◷</span><div><p>A vencer</p><strong class="money"><?= h(money($totals['upcoming'])) ?></strong></div><small>parcelas no prazo</small></article>
    <article class="stat-card"><span class="stat-icon red">!</span><div><p>Em atraso</p><strong class="money"><?= h(money($totals['overdue'])) ?></strong></div><small>vencidas e não pagas</small></article>
    <article class="stat-card"><span class="stat-icon violet">↺</span><div><p>Estornado</p><strong class="money"><?= h(money($totals['refunded'])) ?></strong></div><small>devolvido a clientes</small></article>
</section>

<div class="stack">
<div class="management-grid">
    <section class="panel form-panel"><div class="panel-heading"><div><h2>Lançar pagamento</h2><p>Entrada, pagamento único ou parcelamento.</p></div></div>
        <?php if (!$payable): ?>
            <div class="notice">Nenhuma reserva ativa com saldo a lançar. Crie uma reserva ou verifique se todas já estão totalmente lançadas. <a href="index.php?page=bookings">Reservas</a></div>
        <?php else: ?>
        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create_payments"><input type="hidden" name="view" value="<?= h($view) ?>">
            <label>Reserva
                <select name="booking_id" id="booking_id" required>
                    <option value="">Selecione uma reserva</option>
                    <?php foreach ($payable as $b): $open = (toCents($b['total']) - toCents($b['paid']) - toCents($b['scheduled'])) / 100; ?>
                        <option value="<?= (int) $b['id'] ?>" data-open="<?= h(number_format($open, 2, '.', '')) ?>">#<?= (int) $b['id'] ?> · <?= h($b['client_name']) ?> — <?= h($b['package_title']) ?> (saldo <?= h(money($open)) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field-row"><label>Valor a lançar (R$)<input name="amount" id="amount" type="number" min="0.01" step="0.01" required placeholder="0,00"></label>
                <label>Parcelas<input name="installments" type="number" min="1" max="12" step="1" value="1" required></label></div>
            <div class="field-row"><label>Forma de pagamento<select name="method"><?php foreach (PAYMENT_METHODS as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
                <label>1º vencimento<input name="due_date" type="date" required value="<?= h(today()) ?>"><small class="hint">As demais parcelas vencem de mês em mês.</small></label></div>
            <label class="check"><input type="checkbox" name="first_paid" value="1"> A primeira parcela já foi recebida (entrada)</label>
            <div class="form-actions"><button class="button primary" type="submit">Lançar pagamento</button></div>
        </form>
        <script>
            (function () {
                var select = document.getElementById('booking_id');
                var amount = document.getElementById('amount');
                select.addEventListener('change', function () {
                    var option = select.options[select.selectedIndex];
                    amount.value = option && option.dataset.open ? option.dataset.open : '';
                    if (amount.value) { amount.max = amount.value; }
                });
            })();
        </script>
        <?php endif; ?>
    </section>

    <section class="panel list-panel"><div class="panel-heading"><div><h2>Lançamentos</h2><p><?= count($payments) ?> registro(s)<?= count($payments) === 300 ? ' (exibindo os 300 primeiros)' : '' ?></p></div></div>
        <div class="tabs"><?php foreach ($views as $key => $label): ?><a class="<?= $view === $key ? 'active' : '' ?>" href="pagamentos.php<?= $key === 'all' ? '' : '?view=' . h($key) ?>"><?= h($label) ?></a><?php endforeach; ?></div>
        <?php if (!$payments): ?>
            <div class="empty-state compact"><span>＄</span><strong>Nenhum lançamento nesta visão</strong><p>Os pagamentos lançados aparecerão aqui.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>CLIENTE / VIAGEM</th><th>FORMA</th><th>VENCIMENTO</th><th class="num">VALOR</th><th>SITUAÇÃO</th><th>AÇÕES</th></tr></thead><tbody>
            <?php foreach ($payments as $py):
                $isOverdue = $py['status'] === 'pending' && $py['due_date'] < today();
                [$cls, $label] = $isOverdue ? ['overdue', 'Em atraso'] : match ($py['status']) {
                    'paid' => ['paid', 'Recebido'], 'refunded' => ['refunded', 'Estornado'], default => ['open', 'A receber'],
                };
            ?>
            <tr>
                <td><strong><?= h($py['client_name']) ?></strong><small class="cell-sub">#<?= (int) $py['booking_id'] ?> · <?= h($py['package_title']) ?><?= $py['notes'] !== '' ? ' · ' . h($py['notes']) : '' ?></small></td>
                <td><?= h(PAYMENT_METHODS[$py['method']] ?? $py['method']) ?></td>
                <td><?= h(brDate($py['due_date'])) ?><?php if ($py['paid_at']): ?><small class="cell-sub">pago em <?= h(brDate($py['paid_at'])) ?></small><?php endif; ?></td>
                <td class="num"><?= h(money($py['amount'])) ?></td>
                <td><?= badge($cls, $label) ?></td>
                <td><div class="actions-wrap">
                    <?php if ($py['status'] === 'pending' && $py['booking_status'] !== 'cancelled'): ?>
                        <form method="post" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="view" value="<?= h($view) ?>"><input type="hidden" name="id" value="<?= (int) $py['id'] ?>"><button class="button success small" type="submit">Dar baixa</button></form>
                    <?php endif; ?>
                    <?php if ($py['status'] === 'paid'): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('Estornar este pagamento?')"><?= csrfField() ?><input type="hidden" name="action" value="refund_payment"><input type="hidden" name="view" value="<?= h($view) ?>"><input type="hidden" name="id" value="<?= (int) $py['id'] ?>"><button class="button secondary small" type="submit">Estornar</button></form>
                        <form method="post" class="inline-form" onsubmit="return confirm('Desfazer a baixa deste pagamento?')"><?= csrfField() ?><input type="hidden" name="action" value="mark_pending"><input type="hidden" name="view" value="<?= h($view) ?>"><input type="hidden" name="id" value="<?= (int) $py['id'] ?>"><button class="button secondary small" type="submit">Desfazer</button></form>
                    <?php else: ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('Remover este lançamento?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_payment"><input type="hidden" name="view" value="<?= h($view) ?>"><input type="hidden" name="id" value="<?= (int) $py['id'] ?>"><button class="icon-button danger" aria-label="Remover lançamento">×</button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </section>
</div>

<section class="panel list-panel"><div class="panel-heading"><div><h2>Posição financeira por reserva</h2><p>Total = preço do pacote × viajantes. Reservas canceladas com valores recebidos aparecem como estorno pendente.</p></div></div>
    <?php if (!$finance): ?>
        <div class="empty-state compact"><span>▤</span><strong>Nenhuma reserva cadastrada</strong><p>Crie reservas para controlar os pagamentos.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>RESERVA</th><th>VIAGEM</th><th class="num">TOTAL</th><th class="num">RECEBIDO</th><th class="num">A VENCER / ATRASO</th><th class="num">SALDO</th><th>SITUAÇÃO</th></tr></thead><tbody>
        <?php foreach ($finance as $b):
            [$cls, $label] = paymentSituation($b);
            $balance = $b['status'] === 'cancelled' ? 0 : max(0, toCents($b['total']) - toCents($b['paid']));
        ?>
        <tr>
            <td><strong><?= h($b['client_name']) ?></strong><small class="cell-sub">#<?= (int) $b['id'] ?> · <?= (int) $b['travelers'] ?> pessoa(s) · <?= h(BOOKING_LABELS[$b['status']] ?? $b['status']) ?></small></td>
            <td><?= h($b['package_title']) ?><small class="cell-sub"><?= h($b['destination']) ?> · <?= h(brDate($b['departure_date'])) ?></small></td>
            <td class="num"><?= h(money($b['total'])) ?></td>
            <td class="num pos"><?= h(money($b['paid'])) ?></td>
            <td class="num"><?= h(money((toCents($b['scheduled'])) / 100)) ?><?php if (toCents($b['overdue']) > 0): ?><small class="cell-sub neg"><?= h(money($b['overdue'])) ?> em atraso</small><?php endif; ?></td>
            <td class="num <?= $balance > 0 ? 'neg' : '' ?>"><?= h(moneyCents($balance)) ?></td>
            <td><?= badge($cls, $label) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
</div>
<?php renderFooter();
