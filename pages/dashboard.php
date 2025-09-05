<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth.php';

app_session_start();

$me = auth_user();
if (!$me) {
    // Niet ingelogd → terug naar login (heeft JS/meta fallback als headers al zijn verzonden)
    redirect('index.php?route=login');
    exit;
}

echo function_exists('flash_output') ? flash_output() : '';

// Probeer DB te pakken; toon anders een zachte melding zodat de pagina nooit blanco is
try {
    $pdo = db();
} catch (Throwable $e) {
    $pdo = null;
    echo '<div class="alert alert-warning">Database niet bereikbaar: ' . e($e->getMessage()) . '</div>';
}

// Simpele welkom + minimale tegels (zonder zware joins). Later kun je dit uitbreiden.
?>
<div class="mb-4">
  <h4 class="mb-1">Welkom, <?= e($me['name'] ?? 'gebruiker') ?></h4>
  <div class="text-muted">Dit is je dashboard.</div>
</div>

<div class="row g-3">
  <!-- Actieve SIMs -->
  <div class="col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted">Actieve SIM’s</div>
        <div class="fs-3 fw-bold">
          <?php
          $countActive = 0;
          if ($pdo) {
            try {
              // Veilige, lichte telling: SIMs met status 'active'
              $sql = "SELECT COUNT(*) FROM sims WHERE status = 'active'";
              // Scope voor resellers/sub-resellers: alleen eigen boom of eigen toewijzing
              $role = $me['role'] ?? '';
              if ($role !== 'super_admin') {
                  // Toon alleen SIMs die aan eindklanten in jouw boom zijn toegewezen
                  $ids = build_tree_ids($pdo, (int)$me['id']);
                  if (!$ids) { $ids = [(int)$me['id']]; }
                  $ph = implode(',', array_fill(0, count($ids), '?'));
                  $sql .= " AND assigned_to_user_id IN ($ph)";
                  $st = $pdo->prepare($sql);
                  $st->execute($ids);
              } else {
                  $st = $pdo->query($sql);
              }
              $countActive = (int)$st->fetchColumn();
            } catch (Throwable $e) { /* laat 0 zien */ }
          }
          echo (int)$countActive;
          ?>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0">
        <a class="stretched-link" href="index.php?route=sims_list&status=active">Bekijken</a>
      </div>
    </div>
  </div>

  <!-- SIM’s op voorraad (status != retired én geen order eraan gehangen) -->
  <div class="col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted">SIM’s op voorraad</div>
        <div class="fs-3 fw-bold">
          <?php
          $countStock = 0;
          if ($pdo) {
            try {
              $role = $me['role'] ?? '';
              if ($role === 'super_admin') {
                $sql = "
                  SELECT COUNT(*)
                  FROM sims s
                  WHERE (s.status IS NULL OR s.status <> 'retired')
                    AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.sim_id = s.id)
                ";
                $st = $pdo->query($sql);
              } else {
                $sql = "
                  SELECT COUNT(*)
                  FROM sims s
                  WHERE (s.status IS NULL OR s.status <> 'retired')
                    AND s.assigned_to_user_id = ?
                    AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.sim_id = s.id)
                ";
                $st = $pdo->prepare($sql);
                $st->execute([(int)$me['id']]);
              }
              $countStock = (int)$st->fetchColumn();
            } catch (Throwable $e) { /* laat 0 zien */ }
          }
          echo (int)$countStock;
          ?>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0">
        <?php if (($me['role'] ?? '') === 'super_admin'): ?>
          <a class="stretched-link" href="index.php?route=sims_list&status=stock">Bekijken</a>
        <?php else: ?>
          <a class="stretched-link" href="index.php?route=sims_list&status=stock&owner=me">Bekijken</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Wachten op activatie -->
  <div class="col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted">Wachten op activatie</div>
        <div class="fs-3 fw-bold">
          <?php
          $countAwait = 0;
          if ($pdo) {
            try {
              $role = $me['role'] ?? '';
              if ($role === 'super_admin') {
                $st = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'awaiting_activation'");
              } else {
                $ids = build_tree_ids($pdo, (int)$me['id']);
                if (!$ids) { $ids = [(int)$me['id']]; }
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status='awaiting_activation' AND customer_id IN ($ph)");
                $st->execute($ids);
              }
              $countAwait = (int)$st->fetchColumn();
            } catch (Throwable $e) { /* 0 */ }
          }
          echo (int)$countAwait;
          ?>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0">
        <a class="stretched-link" href="index.php?route=orders_list&status=awaiting_activation">Bekijken</a>
      </div>
    </div>
  </div>

  <!-- Actieve klanten -->
  <div class="col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted">Actieve klanten</div>
        <div class="fs-3 fw-bold">
          <?php
          $countCustomers = 0;
          if ($pdo) {
            try {
              $role = $me['role'] ?? '';
              if ($role === 'super_admin') {
                $st = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND is_active=1");
              } else {
                $ids = build_tree_ids($pdo, (int)$me['id']);
                if (!$ids) { $ids = [(int)$me['id']]; }
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='customer' AND is_active=1 AND id IN ($ph)");
                $st->execute($ids);
              }
              $countCustomers = (int)$st->fetchColumn();
            } catch (Throwable $e) { /* 0 */ }
          }
          echo (int)$countCustomers;
          ?>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0">
        <a class="stretched-link" href="index.php?route=users_list&role=customer&is_active=1">Bekijken</a>
      </div>
    </div>
  </div>
</div>