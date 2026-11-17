<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

/* ------------------------------------------------------------------ FILTROS */
$from = (string) ($_GET['de'] ?? '');
$to = (string) ($_GET['ate'] ?? '');
if ($from !== '' && !validDate($from)) {
    $from = '';
}
if ($to !== '' && !validDate($to)) {
    $to = '';
}
if ($from !== '' && $to !== '' && $to < $from) {
    [$from, $to] = [$to, $from];
}

$conditions = ['1=1'];
$params = [];
if ($from !== '') {
    $conditions[] = 'p.departure_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $conditions[] = 'p.departure_date <= ?';
    $params[] = $to;
}
$where = implode(' AND ', $conditions);
$queryString = http_build_query(array_filter(['de' => $from, 'ate' => $to]));

/* ------------------------------------------------------------------ DADOS */
$stmt = $db->prepare(bookingFinanceSql($where));
$stmt->execute($params);
$bookingRows = $stmt->fetchAll();

$stmt = $db->prepare(tripOverviewSql($where));
$stmt->execute($params);
$tripRows = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT py.*, c.name AS client_name, p.title AS package_title
     FROM payments py
     JOIN bookings b ON b.id = py.booking_id
     JOIN clients c ON c.id = b.client_id
     JOIN packages p ON p.id = b.package_id
     WHERE {$where}
     ORDER BY py.due_date, py.id"
);
$stmt->execute($params);
$paymentRows = $stmt->fetchAll();

/* Agregações em PHP, sempre em centavos */
$summary = ['expected' => 0, 'received' => 0, 'open' => 0, 'overdue' => 0, 'refund_due' => 0, 'refunded' => 0];
$byStatus = [];
foreach (BOOKING_LABELS as $key => $label) {
    $byStatus[$key] = ['label' => $label, 'bookings' => 0, 'travelers' => 0, 'total' => 0];
}
$byPackage = [];
$byClient = [];
$delinquent = [];

foreach ($tripRows as $t) {
    $byPackage[(int) $t['id']] = [
        'title' => $t['title'], 'destination' => $t['destination'], 'departure' => $t['departure_date'],
        'capacity' => (int) $t['seat_capacity'], 'state' => $t['state'], 'trip' => $t,
        'bookings' => 0, 'travelers' => 0, 'expected' => 0, 'received' => 0,
    ];
}

foreach ($bookingRows as $b) {
    $total = toCents($b['total']);
    $paid = toCents($b['paid']);
    $summary['refunded'] += toCents($b['refunded']);
    $byStatus[$b['status']]['bookings']++;
    $byStatus[$b['status']]['travelers'] += (int) $b['travelers'];
    $byStatus[$b['status']]['total'] += $total;

    if ($b['status'] === 'cancelled') {
        $summary['refund_due'] += $paid;
        continue;
    }
    $summary['expected'] += $total;
    $summary['received'] += $paid;
    $summary['open'] += max(0, $total - $paid);
    $summary['overdue'] += toCents($b['overdue']);

    $pid = (int) $b['package_id'];
    $byPackage[$pid]['bookings']++;
    $byPackage[$pid]['travelers'] += (int) $b['travelers'];
    $byPackage[$pid]['expected'] += $total;
    $byPackage[$pid]['received'] += $paid;

    $cid = (int) $b['client_id'];
    $byClient[$cid] ??= ['name' => $b['client_name'], 'email' => $b['client_email'], 'bookings' => 0, 'total' => 0, 'paid' => 0];
    $byClient[$cid]['bookings']++;
    $byClient[$cid]['total'] += $total;
    $byClient[$cid]['paid'] += $paid;

    if (toCents($b['overdue']) > 0) {
        $delinquent[] = $b;
    }
}

$byDestination = [];
foreach ($byPackage as $p) {
    $d = $p['destination'];
    $byDestination[$d] ??= ['trips' => 0, 'travelers' => 0, 'capacity' => 0, 'expected' => 0, 'received' => 0];
    $byDestination[$d]['trips']++;
    $byDestination[$d]['travelers'] += $p['travelers'];
    if ($p['state'] === 'open') {
        $byDestination[$d]['capacity'] += $p['capacity'];
    }
    $byDestination[$d]['expected'] += $p['expected'];
    $byDestination[$d]['received'] += $p['received'];
}
uasort($byDestination, static fn(array $a, array $b): int => $b['expected'] <=> $a['expected']);

usort($delinquent, static fn(array $a, array $b): int => strcmp((string) $a['oldest_overdue'], (string) $b['oldest_overdue']));
uasort($byClient, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
$topClients = array_slice($byClient, 0, 10, true);

$byMethod = [];
foreach ($paymentRows as $py) {
    if ($py['status'] !== 'paid') {
        continue;
    }
    $m = $py['method'];
    $byMethod[$m] ??= ['count' => 0, 'total' => 0];
    $byMethod[$m]['count']++;
    $byMethod[$m]['total'] += toCents($py['amount']);
}
uasort($byMethod, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);

/* ------------------------------------------------------------------ EXPORTAÇÃO CSV */
function csvOut(string $filename, array $header, array $lines): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel reconhecer UTF-8
    $clean = static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    fputcsv($out, $header, ';', '"', '');
    foreach ($lines as $line) {
        fputcsv($out, array_map($clean, $line), ';', '"', '');
    }
    fclose($out);
    exit;
}
$dec = static fn(int $cents): string => number_format($cents / 100, 2, ',', '');

$export = (string) ($_GET['export'] ?? '');
if ($export !== '') {
    $stamp = date('Ymd');
    switch ($export) {
        case 'pacotes':
            csvOut("relatorio-pacotes-{$stamp}.csv",
                ['Pacote', 'Destino', 'Partida', 'Situação', 'Capacidade', 'Viajantes', 'Reservas ativas', 'Previsto (R$)', 'Recebido (R$)', 'A receber (R$)'],
                array_map(static fn(array $p): array => [
                    $p['title'], $p['destination'], brDate($p['departure']), tripSituation($p['trip'])[1], $p['capacity'], $p['travelers'],
                    $p['bookings'], $dec($p['expected']), $dec($p['received']), $dec(max(0, $p['expected'] - $p['received'])),
                ], array_values($byPackage)));
        case 'reservas':
            csvOut("relatorio-reservas-{$stamp}.csv",
                ['Reserva', 'Cliente', 'E-mail', 'Pacote', 'Destino', 'Partida', 'Viajantes', 'Status', 'Total (R$)', 'Recebido (R$)', 'Em atraso (R$)', 'Situação de pagamento'],
                array_map(static fn(array $b): array => [
                    $b['id'], $b['client_name'], $b['client_email'], $b['package_title'], $b['destination'], brDate($b['departure_date']),
                    $b['travelers'], BOOKING_LABELS[$b['status']] ?? $b['status'], $dec(toCents($b['total'])), $dec(toCents($b['paid'])),
                    $dec(toCents($b['overdue'])), paymentSituation($b)[1],
                ], $bookingRows));
        case 'inadimplencia':
            csvOut("relatorio-inadimplencia-{$stamp}.csv",
                ['Reserva', 'Cliente', 'E-mail', 'Pacote', 'Valor em atraso (R$)', 'Vencida desde', 'Dias de atraso'],
                array_map(static fn(array $b): array => [
                    $b['id'], $b['client_name'], $b['client_email'], $b['package_title'], $dec(toCents($b['overdue'])),
                    brDate($b['oldest_overdue']), (int) (new DateTimeImmutable($b['oldest_overdue']))->diff(new DateTimeImmutable(today()))->format('%a'),
                ], $delinquent));
        case 'pagamentos':
            csvOut("relatorio-pagamentos-{$stamp}.csv",
                ['Lançamento', 'Reserva', 'Cliente', 'Pacote', 'Forma', 'Vencimento', 'Pago em', 'Valor (R$)', 'Situação', 'Observação'],
                array_map(static fn(array $py): array => [
                    $py['id'], $py['booking_id'], $py['client_name'], $py['package_title'], PAYMENT_METHODS[$py['method']] ?? $py['method'],
                    brDate($py['due_date']), $py['paid_at'] ? brDate($py['paid_at']) : '', $dec(toCents($py['amount'])),
                    ($py['status'] === 'pending' && $py['due_date'] < today()) ? 'Em atraso' : (PAYMENT_STATUS_LABELS[$py['status']] ?? $py['status']),
                    $py['notes'],
                ], $paymentRows));
        default:
            flash('error', 'Exportação inválida.');
            redirectTo('relatorios.php');
    }
}

/* ------------------------------------------------------------------ VIEW */
$periodText = ($from !== '' || $to !== '')
    ? 'Viagens com partida ' . ($from !== '' ? 'a partir de ' . brDate($from) : '') . ($from !== '' && $to !== '' ? ' ' : '') . ($to !== '' ? 'até ' . brDate($to) : '')
    : 'Todo o período';
$exportLink = static fn(string $type): string => 'relatorios.php?' . http_build_query(array_filter(['de' => $from, 'ate' => $to, 'export' => $type]));

renderHeader('relatorios', 'Relatórios', 'Relatórios');
?>
<section class="page-heading"><div><p class="eyebrow">GESTÃO</p><h1>Relatórios gerenciais</h1><p><?= h($periodText) ?> · gerado em <?= h(date('d/m/Y H:i')) ?></p></div><button class="button primary no-print" type="button" onclick="window.print()">⎙ Imprimir / salvar PDF</button></section>

<section class="panel no-print" style="margin-bottom:19px"><div class="panel-heading"><div><h2>Filtrar por período</h2><p>Considera a data de partida das viagens.</p></div></div>
    <form method="get" class="filters">
        <label>Partida de<input type="date" name="de" value="<?= h($from) ?>"></label>
        <label>Partida até<input type="date" name="ate" value="<?= h($to) ?>"></label>
        <div class="form-actions"><button class="button primary" type="submit">Aplicar</button><a class="button secondary" href="relatorios.php">Limpar</a></div>
    </form>
</section>

<div class="stack">
<section class="panel"><div class="panel-heading"><div><h2>Resumo financeiro</h2><p>Considera reservas pendentes e confirmadas (canceladas ficam de fora).</p></div></div>
    <div class="kpi-list">
        <div class="kpi"><span>Receita prevista</span><strong><?= h(moneyCents($summary['expected'])) ?></strong></div>
        <div class="kpi"><span>Recebido</span><strong class="pos"><?= h(moneyCents($summary['received'])) ?></strong></div>
        <div class="kpi"><span>A receber</span><strong><?= h(moneyCents($summary['open'])) ?></strong></div>
        <div class="kpi"><span>Em atraso</span><strong class="neg"><?= h(moneyCents($summary['overdue'])) ?></strong></div>
        <div class="kpi"><span>Estorno pendente</span><strong><?= h(moneyCents($summary['refund_due'])) ?></strong></div>
        <div class="kpi"><span>Já estornado</span><strong><?= h(moneyCents($summary['refunded'])) ?></strong></div>
    </div>
</section>

<div class="two-cols">
    <section class="panel list-panel"><div class="panel-heading"><div><h2>Reservas por status</h2><p>Quantidade e valor de cada situação.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>STATUS</th><th class="num">RESERVAS</th><th class="num">VIAJANTES</th><th class="num">VALOR</th></tr></thead><tbody>
            <?php foreach ($byStatus as $key => $s): ?><tr><td><span class="badge <?= h($key) ?>"><?= h($s['label']) ?></span></td><td class="num"><?= (int) $s['bookings'] ?></td><td class="num"><?= (int) $s['travelers'] ?></td><td class="num"><?= h(moneyCents($s['total'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="panel list-panel"><div class="panel-heading"><div><h2>Recebimentos por forma de pagamento</h2><p>Apenas pagamentos já baixados.</p></div></div>
        <?php if (!$byMethod): ?><div class="empty-state compact"><span>＄</span><strong>Sem recebimentos no período</strong></div>
        <?php else: ?><div class="table-wrap"><table><thead><tr><th>FORMA</th><th class="num">LANÇAMENTOS</th><th class="num">TOTAL</th></tr></thead><tbody>
            <?php foreach ($byMethod as $m => $row): ?><tr><td><?= h(PAYMENT_METHODS[$m] ?? $m) ?></td><td class="num"><?= (int) $row['count'] ?></td><td class="num"><?= h(moneyCents($row['total'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
</div>

<section class="panel list-panel"><div class="panel-heading"><div><h2>Desempenho por pacote</h2><p>Ocupação e receita de cada viagem.</p></div><a class="button secondary small no-print" href="<?= h($exportLink('pacotes')) ?>">↓ CSV</a></div>
    <?php if (!$byPackage): ?><div class="empty-state compact"><span>✈</span><strong>Nenhum pacote no período</strong></div>
    <?php else: $tv = $te = $tr = 0; ?>
    <div class="table-wrap"><table><thead><tr><th>PACOTE</th><th>PARTIDA</th><th>SITUAÇÃO</th><th class="num">VIAJANTES / VAGAS</th><th class="num">PREVISTO</th><th class="num">RECEBIDO</th><th class="num">A RECEBER</th></tr></thead><tbody>
        <?php foreach ($byPackage as $p): [$cls, $label] = tripSituation($p['trip']); $tv += $p['travelers']; $te += $p['expected']; $tr += $p['received']; ?>
        <tr><td><strong><?= h($p['title']) ?></strong><small class="cell-sub"><?= h($p['destination']) ?></small></td><td><?= h(brDate($p['departure'])) ?></td><td><?= badge($cls, $label) ?></td>
            <td class="num"><?= (int) $p['travelers'] ?><?= $p['state'] === 'open' ? ' / ' . (int) $p['capacity'] . ' (' . occupationPercent($p['travelers'], $p['capacity']) . '%)' : '' ?></td>
            <td class="num"><?= h(moneyCents($p['expected'])) ?></td><td class="num pos"><?= h(moneyCents($p['received'])) ?></td><td class="num"><?= h(moneyCents(max(0, $p['expected'] - $p['received']))) ?></td></tr>
        <?php endforeach; ?>
    </tbody><tfoot><tr><td colspan="3">Total</td><td class="num"><?= (int) $tv ?></td><td class="num"><?= h(moneyCents($te)) ?></td><td class="num"><?= h(moneyCents($tr)) ?></td><td class="num"><?= h(moneyCents(max(0, $te - $tr))) ?></td></tr></tfoot></table></div>
    <?php endif; ?>
</section>

<section class="panel list-panel"><div class="panel-heading"><div><h2>Receita por destino</h2><p>Destinos ordenados pela receita prevista.</p></div></div>
    <?php if (!$byDestination): ?><div class="empty-state compact"><span>✈</span><strong>Nenhum destino no período</strong></div>
    <?php else: ?><div class="table-wrap"><table><thead><tr><th>DESTINO</th><th class="num">PACOTES</th><th class="num">VIAJANTES</th><th class="num">PREVISTO</th><th class="num">RECEBIDO</th></tr></thead><tbody>
        <?php foreach ($byDestination as $dest => $d): ?><tr><td><strong><?= h($dest) ?></strong></td><td class="num"><?= (int) $d['trips'] ?></td><td class="num"><?= (int) $d['travelers'] ?></td><td class="num"><?= h(moneyCents($d['expected'])) ?></td><td class="num pos"><?= h(moneyCents($d['received'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<section class="panel list-panel"><div class="panel-heading"><div><h2>Inadimplência</h2><p>Reservas ativas com parcelas vencidas e não pagas.</p></div><a class="button secondary small no-print" href="<?= h($exportLink('inadimplencia')) ?>">↓ CSV</a></div>
    <?php if (!$delinquent): ?><div class="empty-state compact"><span>✓</span><strong>Nenhuma parcela em atraso</strong><p>Tudo em dia no período selecionado.</p></div>
    <?php else: ?><div class="table-wrap"><table><thead><tr><th>CLIENTE</th><th>PACOTE</th><th>VENCIDA DESDE</th><th class="num">DIAS</th><th class="num">EM ATRASO</th></tr></thead><tbody>
        <?php foreach ($delinquent as $b): $late = (int) (new DateTimeImmutable($b['oldest_overdue']))->diff(new DateTimeImmutable(today()))->format('%a'); ?>
        <tr><td><strong><?= h($b['client_name']) ?></strong><small class="cell-sub">#<?= (int) $b['id'] ?> · <?= h($b['client_email']) ?></small></td><td><?= h($b['package_title']) ?></td><td><?= h(brDate($b['oldest_overdue'])) ?></td><td class="num"><?= $late ?></td><td class="num neg"><?= h(money($b['overdue'])) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<section class="panel list-panel"><div class="panel-heading"><div><h2>Melhores clientes</h2><p>Top 10 por valor reservado (reservas ativas).</p></div><a class="button secondary small no-print" href="<?= h($exportLink('reservas')) ?>">↓ CSV de reservas</a></div>
    <?php if (!$topClients): ?><div class="empty-state compact"><span>♙</span><strong>Nenhuma reserva ativa no período</strong></div>
    <?php else: ?><div class="table-wrap"><table><thead><tr><th>CLIENTE</th><th class="num">RESERVAS</th><th class="num">TOTAL RESERVADO</th><th class="num">PAGO</th></tr></thead><tbody>
        <?php foreach ($topClients as $c): ?><tr><td><strong><?= h($c['name']) ?></strong><small class="cell-sub"><?= h($c['email']) ?></small></td><td class="num"><?= (int) $c['bookings'] ?></td><td class="num"><?= h(moneyCents($c['total'])) ?></td><td class="num pos"><?= h(moneyCents($c['paid'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<section class="panel no-print"><div class="panel-heading"><div><h2>Outras exportações</h2><p>Arquivos CSV (separados por ponto e vírgula, abrem direto no Excel).</p></div></div>
    <div class="panel-body"><div class="actions-wrap"><a class="button secondary" href="<?= h($exportLink('pagamentos')) ?>">↓ Pagamentos</a><a class="button secondary" href="<?= h($exportLink('reservas')) ?>">↓ Reservas</a><a class="button secondary" href="<?= h($exportLink('pacotes')) ?>">↓ Pacotes</a><a class="button secondary" href="<?= h($exportLink('inadimplencia')) ?>">↓ Inadimplência</a></div></div>
</section>
</div>
<?php renderFooter();
