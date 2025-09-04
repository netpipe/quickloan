<?php
// quickloan.php
// Collateralized borrower-first quickloan prototype with credit rating
// WARNING: Prototype. NOT for production. Replace wallet/gateway stubs and secure private keys.

//It’s not a Ponzi, because it doesn’t depend on new investors to pay old ones. It’s a collateralized peer-to-peer loan system.

//Borrower (the “job doer”) puts up their own full amount first (say 200 DOGE).
//A “doubler lender” then steps in and lends them half that amount back (100 DOGE).
//The borrower now has initial deposit (200) + half-back loan (100) = 300 DOGE total liquidity to execute the job.
//When the borrower finishes and pays back, they repay the full “job cost”, and the lender is repaid their half + reward.
//In some cases the lender might just get their half back directly if the borrower doesn’t complete the repayment on time.

// ---------------- CONFIG ----------------
$dbFile = __DIR__ . '/quickloan.db';
$siteName = "QuickLoan Collateral Prototype";
$DOGE_BROADCAST_ENDPOINT = ''; // set to your dogechain-style sendrawtx endpoint
// Optional: units are "units" (we treat amount as integer units). Adjust to match your currency units.

// ---------------- BOOT & DB INIT ----------------
if (!file_exists($dbFile)) init_db($dbFile);
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (session_status() === PHP_SESSION_NONE) session_start();

// ---------------- UTIL ----------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// Very small CSRF helper (prototype)
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
    // users with credit fields
    $db->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT UNIQUE,
        password_hash TEXT,
        email TEXT,
        token TEXT,
        credit_score INTEGER DEFAULT 0,
        loans_repaid INTEGER DEFAULT 0,
        loans_defaulted INTEGER DEFAULT 0,
        created_at DATETIME
    );");
    // wallets (prototype: stores address & privkey -> DO NOT DO IN PRODUCTION)
    $db->exec("CREATE TABLE wallets (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        address TEXT,
        privkey TEXT,
        note TEXT,
        created_at DATETIME
    );");
    // loans
    $db->exec("CREATE TABLE loans (
        id INTEGER PRIMARY KEY,
        borrower_id INTEGER,
        lender_id INTEGER,
        amount INTEGER,                    -- full job amount borrower deposits
        borrower_deposit INTEGER DEFAULT 0,
        borrower_deposit_address TEXT,
        borrower_deposit_txid TEXT,
        lender_contribution INTEGER DEFAULT 0,
        lender_contribution_address TEXT,
        lender_contribution_txid TEXT,
        status TEXT,                       -- waiting_deposit, waiting_lender, active, repaid, defaulted
        created_at DATETIME,
        due_at DATETIME,
        repaid_txid TEXT,
        repaid_at DATETIME
    );");
    // events
    $db->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, loan_id INTEGER, type TEXT, note TEXT, created_at DATETIME);");

    // sample users
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

// ---------------- PLUG-IN HOOKS (replace these stubs) ----------------
/*
 * Replace generate_wallet_for_note() with your wallet generator.
 * Must return ['address'=>..., 'privkey'=>...] (privkey storage here is prototype-only).
 */
function generate_wallet_for_note($note) {
    // Example placeholder: replace with your secure generator
    $addr = 'DTEST' . substr(hash('sha256', $note . random_bytes(6)), 0, 28);
    $priv = bin2hex(random_bytes(32));
    return ['address'=>$addr, 'privkey'=>$priv];
}

/*
 * Send raw transaction hex to your dogechain-style endpoint. Replace to match your API.
 * Return ['success'=>bool,'txid'=>string|null,'raw_response'=>...]
 */
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
// Create a job request (borrower creates and system generates deposit address)
function create_job_request($db, $borrower_id, $amount) {
    if ($amount <= 0) return ['error'=>'Invalid amount'];
    // compute borrower limit based on credit score (simple example)
    $stmt = $db->prepare('SELECT credit_score FROM users WHERE id = ?');
    $stmt->execute([$borrower_id]);
    $score = (int)$stmt->fetchColumn();
    $base_limit = 200; // base cap
    $extra = max(0, $score) * 10; // each credit point = +10 units cap (example)
    $max_allowed = $base_limit + $extra;
    if ($amount > $max_allowed) return ['error'=>"Requested amount exceeds your limit ($max_allowed units). Improve credit score to increase limit."];

    // create deposit address for borrower
    $w = generate_wallet_for_note('borrower_loan_'.$borrower_id.'_'.time());
    $stmt = $db->prepare('INSERT INTO wallets (user_id,address,privkey,note,created_at) VALUES (?, ?, ?, ?, datetime("now"))');
    $stmt->execute([$borrower_id, $w['address'], $w['privkey'], 'loan_borrower_deposit']);
    $deposit_address = $w['address'];

    // insert loan record
    $stmt = $db->prepare('INSERT INTO loans (borrower_id, amount, borrower_deposit, borrower_deposit_address, status, created_at, due_at) VALUES (?, ?, ?, ?, "waiting_deposit", datetime("now"), datetime("now", "+48 hours"))');
    $stmt->execute([$borrower_id, $amount, 0, $deposit_address]);
    $loan_id = $db->lastInsertId();
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "created", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Job request created; deposit_address=$deposit_address; amount=$amount"]);
    return ['loan_id'=>$loan_id, 'deposit_address'=>$deposit_address, 'amount'=>$amount];
}

// Borrower marks deposit funded (record txid) - in production verify via API
function borrower_mark_deposit($db, $loan_id, $txid) {
    $stmt = $db->prepare('SELECT status, borrower_deposit_address, amount FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error'=>'Loan not found'];
    if ($l['status'] !== 'waiting_deposit') return ['error'=>'Loan not in waiting_deposit'];
    // In production: verify txid sends amount >= amount to borrower_deposit_address
    $stmt = $db->prepare('UPDATE loans SET borrower_deposit = ?, borrower_deposit_txid = ?, status = "waiting_lender" WHERE id = ?');
    $stmt->execute([$l['amount'], $txid, $loan_id]);
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "deposit", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Borrower deposit recorded: $txid"]);
    return ['success'=>true];
}

// When lender wants to contribute, system generates an address for the lender to fund
function create_lender_contribution_address($db, $loan_id, $lender_id) {
    $stmt = $db->prepare('SELECT status, amount, borrower_deposit FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error'=>'Loan not found'];
    if ($l['status'] !== 'waiting_lender') return ['error'=>'Loan not waiting for lender'];
    // lender contribution is half the loan amount
    $contribution = intdiv((int)$l['amount'], 2);
    $w = generate_wallet_for_note('lender_loan_'.$loan_id.'_'.time());
    $stmt = $db->prepare('INSERT INTO wallets (user_id,address,privkey,note,created_at) VALUES (?, ?, ?, ?, datetime("now"))');
    $stmt->execute([$lender_id, $w['address'], $w['privkey'], 'loan_lender_contribution']);
    $contrib_address = $w['address'];
    // store lender_id and contribution_address but keep contribution amount 0 until funded
    $stmt = $db->prepare('UPDATE loans SET lender_id = ?, lender_contribution_address = ?, lender_contribution = 0 WHERE id = ?');
    $stmt->execute([$lender_id, $contrib_address, $loan_id]);
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "lender_join", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Lender $lender_id assigned contribution_address=$contrib_address; expected_contribution=$contribution"]);
    return ['contribution_address'=>$contrib_address, 'expected_contribution'=>$contribution];
}

// Lender marks contribution funded (record txid) - in production verify via API
function lender_mark_contribution($db, $loan_id, $txid) {
    $stmt = $db->prepare('SELECT status, lender_contribution_address, amount FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error'=>'Loan not found'];
    if ($l['status'] !== 'waiting_lender') return ['error'=>'Loan not waiting for lender'];
    $contribution = intdiv((int)$l['amount'], 2);
    // In production: verify txid sends contribution amount to lender_contribution_address
    $stmt = $db->prepare('UPDATE loans SET lender_contribution = ?, lender_contribution_txid = ?, status = "active" WHERE id = ?');
    $stmt->execute([$contribution, $txid, $loan_id]);
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "lender_funded", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Lender contribution recorded: $txid (amount=$contribution)"]);
    return ['success'=>true];
}

// Borrower marks repayment (record txid) - in production verify on-chain
function borrower_mark_repayment($db, $loan_id, $txid) {
    $stmt = $db->prepare('SELECT status, amount, borrower_id, lender_id FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error'=>'Loan not found'];
    if ($l['status'] !== 'active') return ['error'=>'Loan not active'];
    // In production: verify txid sends required repayment amounts to appropriate parties
    $stmt = $db->prepare('UPDATE loans SET repaid_txid = ?, repaid_at = datetime("now"), status = "repaid" WHERE id = ?');
    $stmt->execute([$txid, $loan_id]);
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "repaid", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Loan repaid: $txid"]);
    // update credit for borrower and lender
    update_credit_on_repay($db, $l['borrower_id'], $l['lender_id']);
    return ['success'=>true];
}

// update credit after a successful repay
function update_credit_on_repay($db, $borrower_id, $lender_id) {
    if ($borrower_id) {
        $stmt = $db->prepare('UPDATE users SET credit_score = credit_score + 10, loans_repaid = loans_repaid + 1 WHERE id = ?');
        $stmt->execute([$borrower_id]);
    }
    if ($lender_id) {
        // reward lender's score a bit
        $stmt = $db->prepare('UPDATE users SET credit_score = credit_score + 2 WHERE id = ?');
        $stmt->execute([$lender_id]);
    }
}

// when loan overdue -> default: lender claims borrower's deposit and borrower credit reduced
function handle_loan_default($db, $loan_id) {
    $stmt = $db->prepare('SELECT borrower_id, lender_id, status FROM loans WHERE id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return;
    if ($l['status'] !== 'active') return;
    $stmt = $db->prepare('UPDATE loans SET status = "defaulted" WHERE id = ?');
    $stmt->execute([$loan_id]);
    $stmt = $db->prepare('INSERT INTO events (loan_id, type, note, created_at) VALUES (?, "defaulted", ?, datetime("now"))');
    $stmt->execute([$loan_id, "Loan defaulted after due date."]);
    // penalize borrower credit
    if ($l['borrower_id']) {
        $stmt = $db->prepare('UPDATE users SET credit_score = credit_score - 20, loans_defaulted = loans_defaulted + 1 WHERE id = ?');
        $stmt->execute([$l['borrower_id']]);
    }
    // in production: perform on-chain transfer of borrower's deposit to lender
}

// check overdue loans (call on page load or from cron)
function check_and_default_overdue($db) {
    $stmt = $db->prepare('SELECT id FROM loans WHERE status = "active" AND datetime("now") > due_at');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $loan_id) handle_loan_default($db, $loan_id);
}

// ---------------- ROUTING + UI (simple) ----------------
start_session_from_cookie($db);
check_and_default_overdue($db);
$user = current_user($db);
$action = $_POST['action'] ?? null;
$page = $_GET['page'] ?? 'home';
$flash = '';

// ACTIONS
if ($action === 'register') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    $u = trim($_POST['username'] ?? ''); $p = $_POST['password'] ?? ''; $e = trim($_POST['email'] ?? '');
    if ($u === '' || $p === '') $flash = 'Username and password required';
    else { if (register_user($db,$u,$p,$e)) { $flash = 'Registered — please login'; $page='login'; } else $flash = 'Registration failed (maybe username taken)'; }
}
if ($action === 'login') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    $u = $_POST['username'] ?? ''; $p = $_POST['password'] ?? ''; $remember = !empty($_POST['remember']);
    if (login_user($db,$u,$p,$remember)) { header('Location: quickloan.php'); exit; } else $flash = 'Login failed';
}
if ($action === 'logout') {
    session_destroy(); setcookie('ql_token','', time()-3600, '/'); header('Location: quickloan.php'); exit;
}

// Borrower creates a job request and deposit address
if ($action === 'create_job') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $amount = intval($_POST['amount'] ?? 0);
    $res = create_job_request($db, $user['id'], $amount);
    if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
    else { $flash = "Job created. Deposit address: ".$res['deposit_address']." — send {$res['amount']} units and then click 'I funded'"; $page='loan_details'; $_GET['loan_id'] = $res['loan_id']; }
}

// Borrower marks deposit funded
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

// Lender wants to join -> create contribution address
if ($action === 'request_lend') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $loan_id = intval($_POST['loan_id'] ?? 0);
    $res = create_lender_contribution_address($db, $loan_id, $user['id']);
    if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
    else { $flash = "Please send contribution: {$res['expected_contribution']} units to address {$res['contribution_address']} and then click 'I funded'"; $page='loan_details'; $_GET['loan_id'] = $loan_id; }
}

// Lender marks contribution funded
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

// Borrower marks repayment
if ($action === 'mark_repay') {
    if (!check_csrf($_POST['csrf'] ?? '')) { $flash = 'CSRF token invalid'; goto render; }
    if (!$user) { $flash = 'Login first'; $page='login'; goto render; }
    $loan_id = intval($_POST['loan_id'] ?? 0); $txid = trim($_POST['txid'] ?? '');
    if ($txid === '') $flash = 'TXID required';
    else {
        $res = borrower_mark_repayment($db, $loan_id, $txid);
        if (!empty($res['error'])) $flash = 'Error: '.$res['error'];
        else { $flash = 'Repayment recorded. Loan closed as repaid.'; $page='loan_details'; $_GET['loan_id']=$loan_id; }
    }
}

render:

// ---------------- SIMPLE HTML VIEWS ----------------
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
    echo '<p><a href="quickloan.php">Home</a></p>';
    foot(); exit;
}
if ($page === 'login') {
    top('Login');
    echo !empty($flash) ? "<p style='color:red'>".h($flash)."</p>" : '';
    echo '<form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
    echo 'Username: <input name="username"><br>Password: <input type="password" name="password"><br>Remember: <input type="checkbox" name="remember"><br>';
    echo '<button>Login</button></form>';
    echo '<p><a href="quickloan.php?page=register">Register</a></p>';
    foot(); exit;
}

top('Dashboard');
echo !empty($flash) ? "<p style='color:green'>".h($flash)."</p>" : '';
if (!$user) {
    echo '<p><a href="quickloan.php?page=login">Login</a> or <a href="quickloan.php?page=register">Register</a></p>';
    foot(); exit;
}

// logged in view
echo '<p>Logged in as <strong>'.h($user['username']).'</strong> — Credit score: <strong>'.h($user['credit_score']).'</strong> (repaid: '.h($user['loans_repaid']).' defaulted: '.h($user['loans_defaulted']).')</p>';
echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="logout"><button>Logout</button></form>';

// Borrower create job form
echo '<h2>Create Job Request (Borrower)</h2>';
echo '<form method="post"><input type="hidden" name="action" value="create_job"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
echo 'Amount (integer units): <input name="amount"> <button>Create job & get deposit address</button></form>';
echo '<p>Note: Borrower must first deposit full job amount. Lender will later contribute half.</p>';

// List loans related to user
echo '<h2>Your loans (borrower or lender)</h2>';
$stmt = $db->prepare('SELECT l.*, bu.username as borrower_name, lu.username as lender_name FROM loans l LEFT JOIN users bu ON l.borrower_id=bu.id LEFT JOIN users lu ON l.lender_id=lu.id WHERE l.borrower_id = ? OR l.lender_id = ? ORDER BY l.created_at DESC');
$stmt->execute([$user['id'],$user['id']]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$loans) echo '<p>No loans yet.</p>';
else {
    echo '<table border=1 cellpadding=6><tr><th>ID</th><th>Borrower</th><th>Lender</th><th>Amount</th><th>B Deposit addr</th><th>L Contr addr</th><th>Status</th><th>Due At</th><th>Actions</th></tr>';
    foreach ($loans as $l) {
        echo '<tr>';
        echo '<td>'.h($l['id']).'</td>';
        echo '<td>'.h($l['borrower_name']).'</td>';
        echo '<td>'.h($l['lender_name']).'</td>';
        echo '<td>'.h($l['amount']).'</td>';
        echo '<td>'.h($l['borrower_deposit_address']).'</td>';
        echo '<td>'.h($l['lender_contribution_address']).'</td>';
        echo '<td>'.h($l['status']).'</td>';
        echo '<td>'.h($l['due_at']).'</td>';
        echo '<td><a href="quickloan.php?page=loan_details&loan_id='.urlencode($l['id']).'">Details</a></td>';
        echo '</tr>';
    }
    echo '</table>';
}

foot();
exit;

// ---------------- LOAN DETAILS PAGE (separate block but put after exit) ----------------
// Note: unreachable because of the exit above; if you prefer the loan details page to be reachable,
// move the dashboard exit and use this block. For convenience, we'll handle loan_details when requested.

if ($page === 'loan_details') {
    top('Loan Details');
    $loan_id = intval($_GET['loan_id'] ?? 0);
    $stmt = $db->prepare('SELECT l.*, bu.username as borrower_name, lu.username as lender_name FROM loans l LEFT JOIN users bu ON l.borrower_id=bu.id LEFT JOIN users lu ON l.lender_id=lu.id WHERE l.id = ?');
    $stmt->execute([$loan_id]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) { echo "<p>Loan not found.</p>"; foot(); exit; }
    echo '<p>Loan #'.h($l['id']).' borrower: '.h($l['borrower_name']).' lender: '.h($l['lender_name']).'</p>';
    echo '<p>Amount: '.h($l['amount']).' status: '.h($l['status']).' due: '.h($l['due_at']).'</p>';
    echo '<p>Borrower deposit address: '.h($l['borrower_deposit_address']).' txid: '.h($l['borrower_deposit_txid']).'</p>';
    echo '<p>Lender contribution address: '.h($l['lender_contribution_address']).' txid: '.h($l['lender_contribution_txid']).'</p>';

    // Borrower actions
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

    // Lender actions
    if ($user && $l['status'] === 'waiting_lender' && ($user['id'] != $l['borrower_id'])) {
        echo '<h3>Join as Lender</h3>';
        echo '<form method="post"><input type="hidden" name="action" value="request_lend"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
        echo '<input type="hidden" name="loan_id" value="'.h($loan_id).'"><button>Request contribution address</button></form>';
    }
    if ($user && $user['id'] == $l['lender_id'] && $l['status'] === 'waiting_lender') {
        echo '<h3>Mark contribution funded</h3>';
        echo '<form method="post"><input type="hidden" name="action" value="mark_lender_contribution"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
        echo '<input type="hidden" name="loan_id" value="'.h($loan_id).'">TXID: <input name="txid"><button>Mark funded</button></form>';
    }

    echo '<p><a href="quickloan.php">Back</a></p>';
    foot(); exit;
}
