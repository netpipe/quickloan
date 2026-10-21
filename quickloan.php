<?php
// index.php
// Collateralized borrower-first quickloan prototype with credit rating
// WARNING: Prototype. NOT for production. Replace wallet/gateway stubs and secure private keys.

// ---------------- CONFIG ----------------
$dbFile = __DIR__ . '/quickloan.db';
$siteName = "QuickLoan Collateral Prototype";
$DOGE_BROADCAST_ENDPOINT = ''; // set to your dogechain-style sendrawtx endpoint

// ---------------- BOOT & DB INIT ----------------
if (!file_exists($dbFile)) init_db($dbFile);
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// AUTO-MIGRATION: Add balance column if missing (Fixes "no such column: balance" error)
try {
    $stmt = $db->query("PRAGMA table_info(users)");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1); // 1 corresponds to 'name' column
    if (!in_array('balance', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN balance INTEGER DEFAULT 0");
    }
} catch (Exception $e) {
    // Ignore if table doesn't exist yet (handled by init_db)
}

if (session_status() === PHP_SESSION_NONE) session_start();

// ---------------- UTIL ----------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf($token) {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token ?? '');
}

// ---------------- DB INIT FUNCTION ----------------
function init_db($dbFile) {
    if (!is_dir(dirname($dbFile))) mkdir(dirname($dbFile), 0700, true);
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode = WAL;");
    
    $db->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT UNIQUE,
        password_hash TEXT,
        email TEXT,
        token TEXT,
        credit_score INTEGER DEFAULT 0,
        balance INTEGER DEFAULT 0,
        loans_repaid INTEGER DEFAULT 0,
        loans_defaulted INTEGER DEFAULT 0,
        created_at DATETIME
    );");
    
    $db->exec("CREATE TABLE wallets (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        address TEXT,
        privkey TEXT,
        note TEXT,
        created_at DATETIME
    );");
    
    $db->exec("CREATE TABLE loans (
        id INTEGER PRIMARY KEY,
        borrower_id INTEGER,
        lender_id INTEGER,
        amount INTEGER,                    
        borrower_deposit INTEGER DEFAULT 0,
        borrower_deposit_address TEXT,
        borrower_deposit_txid TEXT,
        lender_contribution INTEGER DEFAULT 0,
        lender_contribution_address TEXT,
        lender_contribution_txid TEXT,
        status TEXT,                       
        created_at DATETIME,
        due_at DATETIME,
        repaid_txid TEXT,
        repaid_at DATETIME
    );");
    
    $db->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, loan_id INTEGER, type TEXT, note TEXT, created_at DATETIME);");

    $pass = password_hash('password', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password_hash, email, created_at) VALUES (?, ?, ?, datetime("now"))');
    $stmt->execute(['alice', $pass, 'alice@example.test']);
    $stmt->execute(['bob', $pass, 'bob@example.test']);
    echo "DB created with sample users (alice, bob). Default password: 'password'.\n";
}

// ---------------- AUTH HELPERS ----------------
function start_session_from_cookie($db) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id']) && !empty($_COOKIE['ql_token'])) {
        $token = $_COOKIE['ql_token'];
        $stmt = $db->prepare('SELECT id FROM users WHERE token = ?');
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
        if ($id) $_SESSION['user_id'] = $id;
    }
}

function current_user($db) {
    start_session_from_cookie($db);
    if (empty($_SESSION['user_id'])) return null;
    $stmt = $db->prepare('SELECT id, username, email, credit_score, loans_repaid, loans_defaulted FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function register_user($db, $username, $password, $email='') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password_hash, email, created_at) VALUES (?, ?, ?, datetime("now"))');
    try { $stmt->execute([$username, $hash, $email]); return true; } catch (Exception $e) { return false; }
}

function login_user($db, $username, $password, $remember=false) {
    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['user_id'] = $row['id'];
        if ($remember) {
            $token = bin2hex(random_bytes(16));
            $stmt = $db->prepare('UPDATE users SET token = ? WHERE id = ?');
            $stmt->execute([$token, $row['id']]);
            setcookie('ql_token', $token, time()+60*60*24*30, '/', '', false, true);
        }
        return true;
    }
    return false;
}

// ---------------- PLUG-IN HOOKS ----------------
function generate_wallet_for_note($note) {
    $addr = 'DTEST' . substr(hash('sha256', $note . random_bytes(6)), 0, 28);
    $priv = bin2hex(random_bytes(32));
    return ['address'=>$addr, 'privkey'=>$priv];
}

function send_raw_tx($rawhex) {
    global $DOGE_BROADCAST_ENDPOINT;
    if (empty($DOGE_BROADCAST_ENDPOINT)) return ['success'=>false,'error'=>'Broadcast endpoint not configured'];
    $ch = curl_init($DOGE_BROADCAST_ENDPOINT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $payload = json_encode(['rawtx'=>$rawhex]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $res = curl_exec($ch);
    if ($res === false) { $err = curl_error($ch); curl_close($ch); return ['success'=>false,'error'=>$err]; }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($res, true);
    if ($code >= 200 && $code < 300) {
        $txid = $decoded['txid'] ?? ($decoded['result'] ?? null);
        return ['success'=>true, 'txid'=>$txid, 'raw_response'=>$decoded];
    } else {
        return ['success'=>false, 'raw_response'=>$decoded, 'http_code'=>$code];
    }
}

// ---------------- BUSINESS LOGIC ----------------
function get_user_balance($db, $user_id) {
    $stmt = $db->prepare('SELECT balance FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return (int)$stmt->fetchColumn();
}

function deposit_investor_funds($db, $user_id, $amount, $txid) {
    if ($amount <= 0) return ['error' => 'Invalid amount'];
    $stmt = $db->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
    $stmt->execute([$amount, $user_id]);
    return ['success' => true, 'new_balance' => get_user_balance($db, $user_id)];
}

function invest_from_balance($db, $loan_id, $user_id) {
    $stmt = $db->prepare('SELECT status, amount, borrower_id FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error'=>'Loan not found'];
    if ($l['status'] !== 'waiting_lender') return ['error'=>'Loan not waiting for lender'];
    if ($l['borrower_id'] == $user_id) return ['error'=>'Cannot invest in your own loan'];
    
    $contribution = intdiv((int)$l['amount'], 2);
    $balance = get_user_balance($db, $user_id);
    
    if ($balance < $contribution) return ['error' => 'Insufficient balance'];
    
    $stmt = $db->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
    $stmt->execute([$contribution, $user_id]);
    
    $stmt = $db->prepare('UPDATE loans SET lender_id = ?, lender_contribution = ?, status = "active" WHERE id = ?');
    $stmt->execute([$user_id, $contribution, $loan_id]);
    
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "invested", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Investor $user_id invested $contribution from balance."]);
    
    return ['success' => true, 'contribution' => $contribution];
}

function create_job_request($db, $borrower_id, $amount) {
    if ($amount <= 0) return ['error'=>'Invalid amount'];
    $stmt = $db->prepare('SELECT credit_score FROM users WHERE id = ?');
    $stmt->execute([$borrower_id]);
    $score = (int)$stmt->fetchColumn();
    $base_limit = 200; 
    $extra = max(0, $score) * 10; 
    $max_allowed = $base_limit + $extra;
    if ($amount > $max_allowed) return ['error'=>"Requested amount exceeds your limit ($max_allowed units)."];

    $w = generate_wallet_for_note('borrower_loan_'.$borrower_id.'_'.time());
    $stmt = $db->prepare('INSERT INTO wallets (user_id,address,privkey,note,created_at) VALUES (?, ?, ?, ?, datetime("now"))');
    $stmt->execute([$borrower_id, $w['address'], $w['privkey'], 'loan_borrower_deposit']);
    $deposit_address = $w['address'];

    $stmt = $db->prepare('INSERT INTO loans (borrower_id, amount, borrower_deposit, borrower_deposit_address, status, created_at, due_at) VALUES (?, ?, ?, ?, "waiting_deposit", datetime("now"), datetime("now", "+48 hours"))');
    $stmt->execute([$borrower_id, $amount, 0, $deposit_address]);
    $loan_id = $db->lastInsertId();
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "created", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Job request created; deposit_address=$deposit_address; amount=$amount"]);
    return ['loan_id'=>$loan_id, 'deposit_address'=>$deposit_address, 'amount'=>$amount];
}

function borrower_mark_deposit($db, $loan_id, $txid) {
    $stmt = $db->prepare('SELECT status, borrower_deposit_address, amount FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error'=>'Loan not found'];
    if ($l['status'] !== 'waiting_deposit') return ['error'=>'Loan not in waiting_deposit'];
    
    $stmt = $db->prepare('UPDATE loans SET borrower_deposit = ?, borrower_deposit_txid = ?, status = "waiting_lender" WHERE id = ?');
    $stmt->execute([$l['amount'], $txid, $loan_id]);
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "deposit", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Borrower deposit recorded: $txid"]);
    return ['success'=>true];
}

function create_lender_contribution_address($db, $loan_id, $lender_id) {
    $stmt = $db->prepare('SELECT status, amount, borrower_deposit FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error'=>'Loan not found'];
    if ($l['status'] !== 'waiting_lender') return ['error'=>'Loan not waiting for lender'];
    
    $contribution = intdiv((int)$l['amount'], 2);
    $w = generate_wallet_for_note('lender_loan_'.$loan_id.'_'.time());
    $stmt = $db->prepare('INSERT INTO wallets (user_id,address,privkey,note,created_at) VALUES (?, ?, ?, ?, datetime("now"))');
    $stmt->execute([$lender_id, $w['address'], $w['privkey'], 'loan_lender_contribution']);
    $contrib_address = $w['address'];
    
    $stmt = $db->prepare('UPDATE loans SET lender_id = ?, lender_contribution_address = ?, lender_contribution = 0 WHERE id = ?');
    $stmt->execute([$lender_id, $contrib_address, $loan_id]);
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "lender_join", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Lender $lender_id assigned contribution_address=$contrib_address; expected_contribution=$contribution"]);
    return ['contribution_address'=>$contrib_address, 'expected_contribution'=>$contribution];
}

function lender_mark_contribution($db, $loan_id, $txid) {
    $stmt = $db->prepare('SELECT status, lender_contribution_address, amount FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error'=>'Loan not found'];
    if ($l['status'] !== 'waiting_lender') return ['error'=>'Loan not waiting for lender'];
    $contribution = intdiv((int)$l['amount'], 2);
    
    $stmt = $db->prepare('UPDATE loans SET lender_contribution = ?, lender_contribution_txid = ?, status = "active" WHERE id = ?');
    $stmt->execute([$contribution, $txid, $loan_id]);
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "lender_funded", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Lender contribution recorded: $txid (amount=$contribution)"]);
    return ['success'=>true];
}

function borrower_mark_repayment($db, $loan_id, $txid) {
    $stmt = $db->prepare('SELECT status, amount, borrower_id, lender_id, lender_contribution FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error'=>'Loan not found'];
    if ($l['status'] !== 'active') return ['error'=>'Loan not active'];

    $stmt = $db->prepare('UPDATE loans SET repaid_txid = ?, repaid_at = datetime("now"), status = "repaid" WHERE id = ?');
    $stmt->execute([$txid, $loan_id]);

    // Credit Investor: Principal + 10% Yield
    if ($l['lender_id']) {
        $reward = (int)($l['lender_contribution'] * 0.10); 
        $total_return = $l['lender_contribution'] + $reward;
        $stmt = $db->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
        $stmt->execute([$total_return, $l['lender_id']]);
    }

    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "repaid", "Loan repaid. Investor credited with yield.", datetime("now"))');
    $stmt->execute([$loan_id]);
    
    update_credit_on_repay($db, $l['borrower_id'], $l['lender_id']);
    return ['success'=>true];
}

function update_credit_on_repay($db, $borrower_id, $lender_id) {
    if ($borrower_id) {
        $stmt = $db->prepare('UPDATE users SET credit_score = credit_score + 10, loans_repaid = loans_repaid + 1 WHERE id = ?');
        $stmt->execute([$borrower_id]);
    }
    if ($lender_id) {
        $stmt = $db->prepare('UPDATE users SET credit_score = credit_score + 2 WHERE id = ?');
        $stmt->execute([$lender_id]);
    }
}

function handle_loan_default($db, $loan_id) {
    $stmt = $db->prepare('SELECT borrower_id, lender_id, status, amount FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return;
    if ($l['status'] !== 'active') return;
    
    $stmt = $db->prepare('UPDATE loans SET status = "defaulted" WHERE id = ?');
    $stmt->execute([$loan_id]);
    
    if ($l['borrower_id']) {
        $stmt = $db->prepare('UPDATE users SET credit_score = credit_score - 20, loans_defaulted = loans_defaulted + 1 WHERE id = ?');
        $stmt->execute([$l['borrower_id']]);
    }
    
    // Credit investor with borrower's collateral
    if ($l['lender_id']) {
        $stmt = $db->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
        $stmt->execute([$l['amount'], $l['lender_id']]);
    }
    
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "defaulted", "Loan defaulted. Investor claimed collateral.", datetime("now"))');
    $stmt->execute([$loan_id]);
}

function check_and_default_overdue($db) {
    $stmt = $db->prepare('SELECT id FROM loans WHERE status = "active" AND datetime("now") > due_at');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $loan_id) handle_loan_default($db, $loan_id);
}

// ---------------- ROUTING + UI ----------------
start_session_from_cookie($db);
check_and_default_overdue($db);
$user = current_user($db);
$action = $_POST['action'] ?? null;
$page = $_GET['page'] ?? 'home';
$flash = '';

if ($action === 'register') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    $u = trim($_POST['username'] ?? ''); $p = $_POST['password'] ?? ''; $e = trim($_POST['email'] ?? '');
    if ($u === '' || $p === '') $flash = 'Username and password required';
    else { if (register_user($db,$u,$p,$e)) { $flash = 'Registered — please login'; $page='login'; } else $flash = 'Registration failed'; }
}
if ($action === 'login') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    $u = $_POST['username'] ?? ''; $p = $_POST['password'] ?? ''; $remember = !empty($_POST['remember']);
    if (login_user($db,$u,$p,$remember)) { header('Location: index.php'); exit; } else $flash = 'Login failed';
}
if ($action === 'logout') {
    session_destroy(); setcookie('ql_token','', time()-3600, '/'); header('Location: index.php'); exit;
}
if ($action === 'create_job') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $amount = intval($_POST['amount'] ?? 0);
    $res = create_job_request($db, $user['id'], $amount);
    if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
    else { $flash = "Job created. Deposit address: ".$res['deposit_address']." — send {$res['amount']} units and then click 'I funded'"; $page='loan_details'; $_GET['loan_id'] = $res['loan_id']; }
}
if ($action === 'mark_borrower_deposit') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $loan_id = intval($_POST['loan_id'] ?? 0); $txid = trim($_POST['txid'] ?? '');
    if ($txid === '') $flash = 'TXID required';
    else {
        $res = borrower_mark_deposit($db, $loan_id, $txid);
        if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
        else { $flash = 'Deposit recorded. Now waiting for lender.'; $page='loan_details'; $_GET['loan_id']=$loan_id; }
    }
}
if ($action === 'request_lend') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $loan_id = intval($_POST['loan_id'] ?? 0);
    $res = create_lender_contribution_address($db, $loan_id, $user['id']);
    if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
    else { $flash = "Please send contribution: {$res['expected_contribution']} units to address {$res['contribution_address']} and then click 'I funded'"; $page='loan_details'; $_GET['loan_id'] = $loan_id; }
}
if ($action === 'mark_lender_contribution') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $loan_id = intval($_POST['loan_id'] ?? 0); $txid = trim($_POST['txid'] ?? '');
    if ($txid === '') $flash = 'TXID required';
    else {
        $res = lender_mark_contribution($db, $loan_id, $txid);
        if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
        else { $flash = 'Contribution recorded. Loan is now active.'; $page='loan_details'; $_GET['loan_id']=$loan_id; }
    }
}
if ($action === 'mark_repay') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $loan_id = intval($_POST['loan_id'] ?? 0); $txid = trim($_POST['txid'] ?? '');
    if ($txid === '') $flash = 'TXID required';
    else {
        $res = borrower_mark_repayment($db, $loan_id, $txid);
        if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
        else { $flash = 'Repayment recorded. Investor credited!'; $page='loan_details'; $_GET['loan_id']=$loan_id; }
    }
}
if ($action === 'deposit_funds') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $amount = intval($_POST['amount'] ?? 0);
    $txid = trim($_POST['txid'] ?? 'manual_deposit');
    $res = deposit_investor_funds($db, $user['id'], $amount, $txid);
    if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
    else $flash = 'Funds added! New balance: '.$res['new_balance'];
}
if ($action === 'invest_from_balance') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $loan_id = intval($_POST['loan_id'] ?? 0);
    $res = invest_from_balance($db, $loan_id, $user['id']);
    if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
    else { $flash = "Successfully invested {$res['contribution']} units!"; $page='loan_details'; $_GET['loan_id']=$loan_id; }
}

render:

function top($title='') {
    global $siteName;
    echo "<!doctype html><html><head><meta charset='utf-8'><title>".h($siteName)." - ".h($title)."</title></head><body style='font-family: system-ui, Arial; max-width:1000px;margin:auto;padding:12px;'>";
    echo "<h1>".h($siteName)."</h1>";
}
function foot() {
    echo "<hr><small>Prototype. Replace wallet & broadcast hooks; do not use as-is for real funds.</small></body></html>";
}

if ($page === 'register') {
    top('Register');
    echo !empty($flash) ? "<p style='color:green'>".h($flash)."</p>" : '';
    echo '<form method="post"><input type="hidden" name="action" value="register"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
    echo 'Username: <input name="username"><br>Email: <input name="email"><br>Password: <input type="password" name="password"><br>';
    echo '<button>Register</button></form>';
    echo '<p><a href="index.php">Home</a></p>';
    foot(); exit;
}

if ($page === 'login') {
    top('Login');
    echo !empty($flash) ? "<p style='color:red'>".h($flash)."</p>" : '';
    echo '<form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
    echo 'Username: <input name="username"><br>Password: <input type="password" name="password"><br>Remember: <input type="checkbox" name="remember"><br>';
    echo '<button>Login</button></form>';
    echo '<p><a href="index.php?page=register">Register</a></p>';
    foot(); exit;
}

if ($page === 'loan_details') {
    top('Loan Details');
    $loan_id = intval($_GET['loan_id'] ?? 0);
    $stmt = $db->prepare('SELECT l.*, bu.username as borrower_name, lu.username as lender_name FROM loans l LEFT JOIN users bu ON l.borrower_id=bu.id LEFT JOIN users lu ON l.lender_id=lu.id WHERE l.id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) { echo "<p>Loan not found.</p>"; foot(); exit; }
    
    echo '<p>Loan #'.h($l['id']).' borrower: '.h($l['borrower_name']).' lender: '.h($l['lender_name']).'</p>';
    echo '<p>Amount: '.h($l['amount']).' status: '.h($l['status']).' due: '.h($l['due_at']).'</p>';
    echo '<p>Borrower deposit address: '.h($l['borrower_deposit_address'] ?: 'N/A').' txid: '.h($l['borrower_deposit_txid'] ?: 'N/A').'</p>';
    echo '<p>Lender contribution address: '.h($l['lender_contribution_address'] ?: 'Platform Balance').' txid: '.h($l['lender_contribution_txid'] ?: 'N/A').'</p>';

    if ($user && $user['id'] == $l['borrower_id']) {
        if ($l['status'] === 'waiting_deposit') {
            echo '<h3>Mark deposit funded</h3>';
            echo '<form method="post"><input type="hidden" name="action" value="mark_borrower_deposit"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
            echo '<input type="hidden" name="loan_id" value="'.h($loan_id).'">TXID: <input name="txid"><button>Mark funded</button></form>';
        } elseif ($l['status'] === 'active') {
            echo '<h3>Mark repayment (borrower)</h3>';
            echo '<form method="post"><input type="hidden" name="action" value="mark_repay"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
            echo '<input type="hidden" name="loan_id" value="'.h($loan_id).'">Repay TXID: <input name="txid"><button>Mark repaid</button></form>';
        }
    }

    if ($user && $l['status'] === 'waiting_lender' && ($user['id'] != $l['borrower_id'])) {
        $expected = intdiv((int)$l['amount'], 2);
        $balance = get_user_balance($db, $user['id']);
        echo '<h3>Join as Investor</h3>';
        echo '<p>Investment required: <strong>'.h($expected).'</strong> units. Your balance: <strong>'.h($balance).'</strong> units.</p>';
        if ($balance >= $expected) {
            echo '<form method="post" style="margin-bottom:10px;"><input type="hidden" name="action" value="invest_from_balance"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
            echo '<input type="hidden" name="loan_id" value="'.h($loan_id).'"><button style="background-color: #28a745; color: white; padding:8px;">Invest from Balance (1-Click)</button></form>';
        } else {
            echo '<p style="color:red;">Insufficient balance. Please add funds on the dashboard.</p>';
        }
        echo '<hr><small>Or use the manual TXID method:</small>';
        echo '<form method="post"><input type="hidden" name="action" value="request_lend"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
        echo '<input type="hidden" name="loan_id" value="'.h($loan_id).'"><button>Request manual contribution address</button></form>';
    }
    
    if ($user && $user['id'] == $l['lender_id'] && $l['status'] === 'waiting_lender') {
        echo '<h3>Mark contribution funded</h3>';
        echo '<form method="post"><input type="hidden" name="action" value="mark_lender_contribution"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
        echo '<input type="hidden" name="loan_id" value="'.h($loan_id).'">TXID: <input name="txid"><button>Mark funded</button></form>';
    }

    echo '<p><a href="index.php">Back</a></p>';
    foot(); exit;
}

top('Dashboard');
echo !empty($flash) ? "<p style='color:green'>".h($flash)."</p>" : '';
if (!$user) {
    echo '<p><a href="index.php?page=login">Login</a> or <a href="index.php?page=register">Register</a></p>';
    foot(); exit;
}

echo '<p>Logged in as <strong>'.h($user['username']).'</strong> — Credit score: <strong>'.h($user['credit_score']).'</strong> (repaid: '.h($user['loans_repaid']).' defaulted: '.h($user['loans_defaulted']).')</p>';
echo '<p>Platform Balance: <strong>'.h(get_user_balance($db, $user['id'])).'</strong> units</p>';
echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="logout"><button>Logout</button></form>';

echo '<h2>🏦 Investor Portal</h2>';
echo '<form method="post" style="margin-bottom:15px;"><input type="hidden" name="action" value="deposit_funds"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
echo 'Deposit Amount: <input name="amount" type="number" min="1"> TXID (optional): <input name="txid"> <button>Add Funds</button></form>';

echo '<h2>Create Job Request (Borrower)</h2>';
echo '<form method="post"><input type="hidden" name="action" value="create_job"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
echo 'Amount (integer units): <input name="amount" type="number" min="1"> <button>Create job & get deposit address</button></form>';

echo '<h3>Available Jobs to Invest (Marketplace)</h3>';
$stmt = $db->prepare('SELECT l.*, bu.username as borrower_name FROM loans l LEFT JOIN users bu ON l.borrower_id=bu.id WHERE l.status = "waiting_lender" ORDER BY l.created_at DESC');
$stmt->execute();
$open_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$open_loans) echo '<p>No jobs currently looking for investors.</p>';
else {
    echo '<table border=1 cellpadding=6><tr><th>ID</th><th>Borrower</th><th>Job Amount</th><th>Investment Needed</th><th>Action</th></tr>';
    foreach ($open_loans as $l) {
        $expected = intdiv((int)$l['amount'], 2);
        echo '<tr>';
        echo '<td>'.h($l['id']).'</td>';
        echo '<td>'.h($l['borrower_name']).'</td>';
        echo '<td>'.h($l['amount']).'</td>';
        echo '<td>'.h($expected).'</td>';
        echo '<td><a href="index.php?page=loan_details&loan_id='.urlencode($l['id']).'">View & Invest</a></td>';
        echo '</tr>';
    }
    echo '</table>';
}

echo '<h2>Your loans (borrower or lender)</h2>';
$stmt = $db->prepare('SELECT l.*, bu.username as borrower_name, lu.username as lender_name FROM loans l LEFT JOIN users bu ON l.borrower_id=bu.id LEFT JOIN users lu ON l.lender_id=lu.id WHERE l.borrower_id = ? OR l.lender_id = ? ORDER BY l.created_at DESC');
$stmt->execute([$user['id'],$user['id']]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$loans) echo '<p>No loans yet.</p>';
else {
    echo '<table border=1 cellpadding=6><tr><th>ID</th><th>Borrower</th><th>Lender</th><th>Amount</th><th>Status</th><th>Due At</th><th>Actions</th></tr>';
    foreach ($loans as $l) {
        echo '<tr>';
        echo '<td>'.h($l['id']).'</td>';
        echo '<td>'.h($l['borrower_name']).'</td>';
        echo '<td>'.h($l['lender_name']).'</td>';
        echo '<td>'.h($l['amount']).'</td>';
        echo '<td>'.h($l['status']).'</td>';
        echo '<td>'.h($l['due_at']).'</td>';
        echo '<td><a href="index.php?page=loan_details&loan_id='.urlencode($l['id']).'">Details</a></td>';
        echo '</tr>';
    }
    echo '</table>';
}

foot();
exit;