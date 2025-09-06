<?php
require __DIR__.'/db.php';
require __DIR__.'/lib/auth.php';
require __DIR__.'/lib/helpers.php';

verify_csrf();

$page = $_GET['page'] ?? 'dashboard';

if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$_POST['email']]);
        $user = $stmt->fetch();
        if ($user && password_verify($_POST['password'], $user['password_hash'])) {
            $_SESSION['user'] = $user;
            set_flash('success', 'Welkom terug!');
            redirect('/index.php');
        } else {
            set_flash('danger', 'Ongeldige inloggegevens');
        }
    }
    $users = [];
    include __DIR__.'/views/login.php';
    exit;
}

if ($page === 'logout') {
    session_destroy();
    redirect('/index.php?page=login');
}

require_login();

if ($page === 'dashboard') {
    include __DIR__.'/views/dashboard.php';
    exit;
}

if ($page === 'users') {
    $u = current_user();
    if (is_super()) {
        $stmt = $pdo->query("SELECT id,email,role,is_active,company_name FROM users ORDER BY id DESC");
    } else {
        $stmt = $pdo->prepare("
            SELECT id,email,role,is_active,company_name FROM users
            WHERE id = :me OR parent_id = :me OR parent_id IN (SELECT id FROM users WHERE parent_id = :me)
            ORDER BY id DESC
        ");
        $stmt->execute([':me'=>$u['id']]);
    }
    $users = $stmt->fetchAll();
    include __DIR__.'/views/users_list.php';
    exit;
}

if ($page === 'user_edit') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) { set_flash('danger','Gebruiker niet gevonden'); redirect('/index.php?page=users'); }
        if (!is_super() && !belongs_in_tree($pdo, current_user()['id'], (int)$user['id'])) {
            http_response_code(403); echo 'Geen toegang'; exit;
        }
    }
    include __DIR__.'/views/user_edit.php';
    exit;
}

if ($page === 'user_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id = $_POST['id'] ?? null;
    $fields = ['email','role','parent_id','is_active','company_name','address','postal_code','city'];
    $data = [];
    foreach($fields as $f){ $data[$f] = $_POST[$f] ?? null; }
    if (!$id && empty($_POST['password'])) { set_flash('danger','Wachtwoord vereist'); redirect('/index.php?page=user_edit'); }
    if (!empty($_POST['password'])) { $data['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT); }
    if ($id) {
        if (!is_super() && !belongs_in_tree($pdo, current_user()['id'], (int)$id)) { http_response_code(403); echo 'Geen toegang'; exit; }
        $sets = [];
        foreach($data as $k=>$v){ $sets[] = "$k = :$k"; }
        $data['id'] = $id;
        $sql = "UPDATE users SET ".implode(',', $sets)." WHERE id = :id";
        $stmt = $pdo->prepare($sql); $stmt->execute($data);
        set_flash('success','Gebruiker bijgewerkt');
    } else {
        $cols = implode(',', array_keys($data));
        $place = ':'.implode(',:', array_keys($data));
        $stmt = $pdo->prepare("INSERT INTO users ($cols) VALUES ($place)");
        $stmt->execute($data);
        set_flash('success','Gebruiker aangemaakt');
    }
    redirect('/index.php?page=users');
}

if ($page === 'user_delete') {
    require_role('super_admin');
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$_GET['id'] ?? 0]);
    set_flash('success','Gebruiker verwijderd');
    redirect('/index.php?page=users');
}

if ($page === 'simcards') {
    if (is_super()) {
        $stmt = $pdo->query("SELECT s.*, u.email as assigned_to FROM sim_cards s LEFT JOIN users u ON u.id = s.assigned_to ORDER BY s.id DESC");
    } else {
        $me = current_user()['id'];
        $sql = "SELECT s.*, u.email as assigned_to FROM sim_cards s
            LEFT JOIN users u ON u.id = s.assigned_to
            WHERE s.assigned_to = :me
            OR s.assigned_to IN (SELECT id FROM users WHERE parent_id = :me)
            OR s.assigned_to IN (SELECT id FROM users WHERE parent_id IN (SELECT id FROM users WHERE parent_id = :me))
            ORDER BY s.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':me'=>$me]);
    }
    $simcards = $stmt->fetchAll();
    include __DIR__.'/views/simcards_list.php';
    exit;
}

if ($page === 'simcard_edit') {
    if (!is_super()) {
        $id = $_GET['id'] ?? null;
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM sim_cards WHERE id = ?");
            $stmt->execute([$id]);
            $sim = $stmt->fetch();
            if (!$sim) { set_flash('danger','Simkaart niet gevonden'); redirect('/index.php?page=simcards'); }
            if (!is_super() && !belongs_in_tree($pdo, current_user()['id'], (int)($sim['assigned_to'] ?? 0))) {
                http_response_code(403); echo 'Geen toegang'; exit;
            }
        } else {
            http_response_code(403); echo 'Alleen Super-admin kan nieuwe simkaarten toevoegen'; exit;
        }
    } else {
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM sim_cards WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $sim = $stmt->fetch();
        }
    }
    include __DIR__.'/views/simcard_edit.php';
    exit;
}

if ($page === 'simcard_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id = $_POST['id'] ?? null;
    $data = ['iccid'=>$_POST['iccid'], 'status'=>$_POST['status'], 'assigned_to'=>($_POST['assigned_to'] ?: null)];
    if ($id) {
        $stmt = $pdo->prepare("UPDATE sim_cards SET iccid=:iccid, status=:status, assigned_to=:assigned_to WHERE id=:id");
        $data['id'] = $id;
        $stmt->execute($data);
        set_flash('success','Simkaart bijgewerkt');
    } else {
        require_role('super_admin');
        $stmt = $pdo->prepare("INSERT INTO sim_cards (iccid,status,assigned_to) VALUES (:iccid,:status,:assigned_to)");
        $stmt->execute($data);
        set_flash('success','Simkaart aangemaakt');
    }
    redirect('/index.php?page=simcards');
}

if ($page === 'simcard_delete') {
    require_role('super_admin');
    $stmt = $pdo->prepare("DELETE FROM sim_cards WHERE id = ?");
    $stmt->execute([$_GET['id'] ?? 0]);
    set_flash('success','Simkaart verwijderd');
    redirect('/index.php?page=simcards');
}

if ($page === 'plans') {
    require_role('super_admin');
    $plans = $pdo->query("SELECT * FROM plans ORDER BY id DESC")->fetchAll();
    include __DIR__.'/views/plans_list.php'; exit;
}
if ($page === 'plan_edit') {
    require_role('super_admin');
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?"); $stmt->execute([$_GET['id']]); $plan = $stmt->fetch();
    }
    include __DIR__.'/views/plan_edit.php'; exit;
}
if ($page === 'plan_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_role('super_admin');
    $id = $_POST['id'] ?? null;
    $data = ['name'=>$_POST['name'],'description'=>$_POST['description'],'monthly_price'=>$_POST['monthly_price']];
    if ($id) {
        $stmt = $pdo->prepare("UPDATE plans SET name=:name, description=:description, monthly_price=:monthly_price WHERE id=:id");
        $data['id']=$id; $stmt->execute($data); set_flash('success','Plan bijgewerkt');
    } else {
        $stmt = $pdo->prepare("INSERT INTO plans (name,description,monthly_price) VALUES (:name,:description,:monthly_price)");
        $stmt->execute($data); set_flash('success','Plan aangemaakt');
    }
    redirect('/index.php?page=plans');
}
if ($page === 'plan_delete') {
    require_role('super_admin');
    $stmt = $pdo->prepare("DELETE FROM plans WHERE id = ?"); $stmt->execute([$_GET['id'] ?? 0]); set_flash('success','Plan verwijderd'); redirect('/index.php?page=plans');
}

if ($page === 'suppliers') {
    require_role('super_admin');
    $suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY id DESC")->fetchAll();
    include __DIR__.'/views/suppliers_list.php'; exit;
}
if ($page === 'supplier_edit') {
    require_role('super_admin');
    if (isset($_GET['id'])) { $stmt=$pdo->prepare("SELECT * FROM suppliers WHERE id = ?"); $stmt->execute([$_GET['id']]); $supplier=$stmt->fetch(); }
    include __DIR__.'/views/supplier_edit.php'; exit;
}
if ($page === 'supplier_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_role('super_admin');
    $id = $_POST['id'] ?? null;
    $data = ['name'=>$_POST['name'],'api_base_url'=>$_POST['api_base_url'],'notes'=>$_POST['notes']];
    if ($id) {
        $stmt = $pdo->prepare("UPDATE suppliers SET name=:name, api_base_url=:api_base_url, notes=:notes WHERE id=:id");
        $data['id']=$id; $stmt->execute($data); set_flash('success','Leverancier bijgewerkt');
    } else {
        $stmt = $pdo->prepare("INSERT INTO suppliers (name, api_base_url, notes) VALUES (:name,:api_base_url,:notes)");
        $stmt->execute($data); set_flash('success','Leverancier aangemaakt');
    }
    redirect('/index.php?page=suppliers');
}
if ($page === 'supplier_delete') {
    require_role('super_admin');
    $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?"); $stmt->execute([$_GET['id'] ?? 0]); set_flash('success','Leverancier verwijderd'); redirect('/index.php?page=suppliers');
}

if ($page === 'orders') {
    $u = current_user();
    if (is_super()) {
        $sql = "SELECT o.*, p.name as plan_name, s.iccid, ec.email as end_customer_email, cu.email as created_by_email
                FROM orders o
                LEFT JOIN plans p ON p.id = o.plan_id
                LEFT JOIN sim_cards s ON s.id = o.sim_card_id
                LEFT JOIN users ec ON ec.id = o.end_customer_id
                LEFT JOIN users cu ON cu.id = o.created_by
                ORDER BY o.id DESC";
        $orders = $pdo->query($sql)->fetchAll();
    } else {
        $me = $u['id'];
        $sql = "SELECT o.*, p.name as plan_name, s.iccid, ec.email as end_customer_email, cu.email as created_by_email
                FROM orders o
                LEFT JOIN plans p ON p.id = o.plan_id
                LEFT JOIN sim_cards s ON s.id = o.sim_card_id
                LEFT JOIN users ec ON ec.id = o.end_customer_id
                LEFT JOIN users cu ON cu.id = o.created_by
                WHERE o.created_by = :me
                   OR o.end_customer_id = :me
                   OR o.end_customer_id IN (SELECT id FROM users WHERE parent_id = :me)
                   OR o.end_customer_id IN (SELECT id FROM users WHERE parent_id IN (SELECT id FROM users WHERE parent_id = :me))
                ORDER BY o.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute([':me'=>$me]); $orders = $stmt->fetchAll();
    }
    include __DIR__.'/views/orders_list.php'; exit;
}

if ($page === 'order_edit') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?"); $stmt->execute([$id]); $order = $stmt->fetch();
        if (!$order) { set_flash('danger','Bestelling niet gevonden'); redirect('/index.php?page=orders'); }
        if (!is_super() && $order['status'] !== 'concept') { set_flash('warning','Definitieve bestelling kan niet meer worden bewerkt'); }
    }
    $plans = $pdo->query("SELECT * FROM plans ORDER BY name ASC")->fetchAll();
    $me = current_user()['id'];
    $sql = "SELECT * FROM sim_cards WHERE assigned_to = :me
            OR assigned_to IN (SELECT id FROM users WHERE parent_id = :me)
            OR assigned_to IN (SELECT id FROM users WHERE parent_id IN (SELECT id FROM users WHERE parent_id = :me))";
    $stmt = $pdo->prepare($sql); $stmt->execute([':me'=>$me]); $sims = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT id,email FROM users WHERE role='end_customer' AND (id=:me OR parent_id=:me OR parent_id IN (SELECT id FROM users WHERE parent_id=:me))");
    $stmt->execute([':me'=>$me]); $end_customers = $stmt->fetchAll();
    include __DIR__.'/views/order_edit.php'; exit;
}

if ($page === 'order_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id = $_POST['id'] ?? null;
    $data = [
        'plan_id' => $_POST['plan_id'],
        'sim_card_id' => $_POST['sim_card_id'],
        'end_customer_id' => $_POST['end_customer_id'],
    ];
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id=?"); $stmt->execute([$id]); $existing = $stmt->fetch();
        if (!$existing) { set_flash('danger','Bestelling niet gevonden'); redirect('/index.php?page=orders'); }
        if (!is_super() && $existing['status'] !== 'concept') { set_flash('warning','Definitieve bestelling kan niet worden bewerkt'); redirect('/index.php?page=orders'); }
        $stmt = $pdo->prepare("UPDATE orders SET plan_id=:plan_id, sim_card_id=:sim_card_id, end_customer_id=:end_customer_id, updated_at=:ts WHERE id=:id");
        $data['ts'] = now(); $data['id'] = $id; $stmt->execute($data);
        set_flash('success','Bestelling bijgewerkt');
    } else {
        $status = isset($_POST['finalize']) ? 'wachten_op_activatie' : 'concept';
        $stmt = $pdo->prepare("INSERT INTO orders (status, plan_id, sim_card_id, end_customer_id, created_by, created_at, updated_at)
                               VALUES (:status, :plan_id, :sim_card_id, :end_customer_id, :created_by, :ts, :ts)");
        $stmt->execute(['status'=>$status]+$data+['created_by'=>current_user()['id'],'ts'=>now()]);
        set_flash('success','Bestelling opgeslagen');
    }
    redirect('/index.php?page=orders');
}

if ($page === 'order_status') {
    require_role('super_admin');
    $id = $_GET['id'] ?? 0;
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $stmt = $pdo->prepare("UPDATE orders SET status=:status, updated_at=:ts WHERE id=:id");
        $stmt->execute([':status'=>$_POST['status'], ':ts'=>now(), ':id'=>$id]);
        set_flash('success','Status bijgewerkt');
        redirect('/index.php?page=orders');
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id=?"); $stmt->execute([$id]); $order=$stmt->fetch();
        include __DIR__.'/views/order_status.php'; exit;
    }
}

if ($page === 'admin') { include __DIR__.'/views/admin.php'; exit; }

http_response_code(404);
echo "<div class='container mt-5'><h3>Pagina niet gevonden</h3></div>";
