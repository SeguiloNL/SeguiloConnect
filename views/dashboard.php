<?php require __DIR__.'/partials/header.php'; ?>
<div class="row g-4">
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <i class="bi bi-bag display-6 me-3"></i>
          <div>
            <div class="fw-bold">Bestellingen</div>
            <div class="text-muted">Overzicht en beheer</div>
          </div>
        </div>
        <a href="/index.php?page=orders" class="btn btn-outline-primary mt-3">Ga naar bestellingen</a>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <i class="bi bi-sim display-6 me-3"></i>
          <div>
            <div class="fw-bold">Simkaarten</div>
            <div class="text-muted">Toewijzen & voorraad</div>
          </div>
        </div>
        <a href="/index.php?page=simcards" class="btn btn-outline-primary mt-3">Ga naar simkaarten</a>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <i class="bi bi-people display-6 me-3"></i>
          <div>
            <div class="fw-bold">Gebruikers</div>
            <div class="text-muted">Resellers & klanten</div>
          </div>
        </div>
        <a href="/index.php?page=users" class="btn btn-outline-primary mt-3">Ga naar gebruikers</a>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>
