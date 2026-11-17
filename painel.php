<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$todayLiteral = "'" . today() . "'";
$in7 = "'" . date('Y-m-d', strtotime('+7 days')) . "'";

/* Indicadores financeiros (reservas ativas) */
$bookings = $db->query(bookingFinanceSql("b.status <> 'cancelled'"))->fetchAll();
$expected = $received = $open = $overdue = 0;
foreach ($bookings as $b) {
    $total = toCents($b['total']);
    $paid = toCents($b['paid']);
    $expected += $total;
    $received += $paid;
    $open += max(0, $total - $paid);
    $overdue += toCents($b['overdue']);
}
$refundDue = (int) toCents($db->query(
    "SELECT COALESCE(SUM(py.amount), 0) FROM payments py JOIN bookings b ON b.id = py.booking_id
     WHERE b.status = 'cancelled' AND py.status = 'paid'"
)->fetchColumn());

/* Viagens */
$trips = $db->query(tripOverviewSql())->fetchAll();
$upcoming = [];
$activeTrips = 0;
$seatsSold = $seatsTotal = 0;
foreach ($trips as $t) {
    [$cls, $label] = tripSituation($t);
    $t['_cls'] = $cls;
    $t['_label'] = $label;
    if (in_array($cls, ['sale', 'full', 'suspended'], true)) {
        $activeTrips++;
        $upcoming[] = $t;
        if ($cls !== 'suspended') {
            $seatsSold += (int) $t['reserved'];
            $seatsTotal += (int) $t['seat_capacity'];
        }
    }
}
$upcoming = array_slice($upcoming, 0, 5);

/* Pagamentos que exigem atenção */
$attention = $db->query(
    "SELECT py.*, c.name AS client_name, p.title AS package_title
     FROM payments py
     JOIN bookings b ON b.id = py.booking_id
     JOIN clients c ON c.id = b.client_id
     JOIN packages p ON p.id = b.package_id
     WHERE py.status = 'pending' AND b.status <> 'cancelled' AND py.due_date <= {$in7}
     ORDER BY py.due_date, py.id LIMIT 8"
)->fetchAll();

/* Reservas ainda sem nenhum pagamento lançado */
$unbilled = $db->query(
    "SELECT b.id, c.name AS client_name, p.title AS package_title, ROUND(p.price * b.travelers, 2) AS total
     FROM bookings b JOIN clients c ON c.id = b.client_id JOIN packages p ON p.id = b.package_id
     WHERE b.status <> 'cancelled' AND NOT EXISTS (SELECT 1 FROM payments py WHERE py.booking_id = b.id)
     ORDER BY b.created_at DESC, b.id DESC LIMIT 5"
)->fetchAll();

$clientsCount = (int) $db->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$bookingsCount = (int) $db->query('SELECT COUNT(*) FROM bookings')->fetchColumn();

renderHeader('painel', 'Painel gerencial', 'Painel gerencial');
?>
<section class="page-heading"><div><p class="eyebrow">VISÃO DA AGÊNCIA</p><h1>Painel gerencial</h1><p>Financeiro, ocupação e pendências em um só lugar.</p></div><a class="button primary" href="index.php?page=bookings">＋ Nova reserva</a></section>

<section class="stats four">
    <article class="stat-card"><span class="stat-icon orange">＄</span><div><p>Receita prevista</p><strong class="money"><?= h(moneyCents($expected)) ?></strong></div><small><?= $bookingsCount ?> reserva(s) no total</small></article>
    <article class="stat-card"><span class="stat-icon green">✓</span><div><p>Recebido</p><strong class="money"><?= h(moneyCents($received)) ?></strong></div><small>a receber: <?= h(moneyCents($open)) ?></small></article>
    <article class="stat-card"><span class="stat-icon red">!</span><div><p>Em atraso</p><strong class="money"><?= h(moneyCents($overdue)) ?></strong></div><small><?= $refundDue > 0 ? 'estornos pendentes: ' . h(moneyCents($refundDue)) : 'parcelas vencidas' ?></small></article>
    <article class="stat-card"><span class="stat-icon blue">✈</span><div><p>Ocupação das viagens</p><strong><?= occupationPercent($seatsSold, $seatsTotal) ?>%</strong></div><small><?= $activeTrips ?> viagem(ns) ativa(s) · <?= $clientsCount ?> cliente(s)</small></article>
</section>

<div class="stack">
<div class="two-cols">
    <section class="panel list-panel"><div class="panel-heading"><div><h2>Próximas viagens</h2><p>Pacotes em venda, lotados ou suspensos.</p></div><a class="text-link" href="viagens.php">Ver todas <span>→</span></a></div>
        <?php if (!$upcoming): ?><div class="empty-state compact"><span>✈</span><strong>Nenhuma viagem ativa</strong><p>Cadastre um pacote para começar.</p><a class="button secondary" href="index.php?page=packages">Novo pacote</a></div>
        <?php else: ?><div class="table-wrap"><table><thead><tr><th>VIAGEM</th><th>PARTIDA</th><th>OCUPAÇÃO</th><th>SITUAÇÃO</th></tr></thead><tbody>
            <?php foreach ($upcoming as $t): $isOpen = $t['state'] === 'open'; ?>
            <tr><td><a href="viagens.php?id=<?= (int) $t['id'] ?>"><strong><?= h($t['title']) ?></strong></a><small class="cell-sub"><?= h($t['destination']) ?></small></td><td><?= h(brDate($t['departure_date'])) ?></td>
                <td><?= $isOpen ? progressBar(occupationPercent((int) $t['reserved'], (int) $t['seat_capacity'])) : '' ?><span class="progress-label"><?= (int) $t['reserved'] ?><?= $isOpen ? ' de ' . (int) $t['seat_capacity'] : '' ?> viajante(s)</span></td>
                <td><?= badge($t['_cls'], $t['_label']) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>

    <section class="panel list-panel"><div class="panel-heading"><div><h2>Pagamentos para acompanhar</h2><p>Vencidos ou com vencimento nos próximos 7 dias.</p></div><a class="text-link" href="pagamentos.php?view=pending">Ver todos <span>→</span></a></div>
        <?php if (!$attention): ?><div class="empty-state compact"><span>✓</span><strong>Nada urgente</strong><p>Nenhum vencimento nos próximos dias.</p></div>
        <?php else: ?><div class="table-wrap"><table><thead><tr><th>CLIENTE</th><th>VENCIMENTO</th><th class="num">VALOR</th><th>SITUAÇÃO</th></tr></thead><tbody>
            <?php foreach ($attention as $py): $late = $py['due_date'] < today(); ?>
            <tr><td><strong><?= h($py['client_name']) ?></strong><small class="cell-sub"><?= h($py['package_title']) ?></small></td><td><?= h(brDate($py['due_date'])) ?></td><td class="num"><?= h(money($py['amount'])) ?></td><td><?= $late ? badge('overdue', 'Em atraso') : badge('partial', 'Vence logo') ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
</div>

<section class="panel list-panel"><div class="panel-heading"><div><h2>Reservas sem cobrança lançada</h2><p>Reservas ativas que ainda não têm nenhum pagamento registrado.</p></div><a class="text-link" href="pagamentos.php">Lançar pagamentos <span>→</span></a></div>
    <?php if (!$unbilled): ?><div class="empty-state compact"><span>✓</span><strong>Todas as reservas têm cobrança</strong></div>
    <?php else: ?><div class="table-wrap"><table><thead><tr><th>RESERVA</th><th>PACOTE</th><th class="num">TOTAL</th></tr></thead><tbody>
        <?php foreach ($unbilled as $u): ?><tr><td><strong><?= h($u['client_name']) ?></strong><small class="cell-sub">#<?= (int) $u['id'] ?></small></td><td><?= h($u['package_title']) ?></td><td class="num"><?= h(money($u['total'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
</div>
<?php renderFooter();
